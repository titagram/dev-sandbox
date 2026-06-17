<?php

namespace App\Http\Controllers\Dashboard;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Dashboard\Concerns\ChecksDashboardRoles;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response;

class RunShowController extends Controller
{
    use ChecksDashboardRoles;

    public function __invoke(Request $request, string $run): Response
    {
        $runRow = DB::table('runs')->where('id', $run)->firstOrFail();
        $events = DB::table('run_events')->where('run_id', $run)->orderBy('created_at')->get();
        $artifacts = DB::table('artifacts')->where('run_id', $run)->orderByDesc('created_at')->get();
        $securityReport = $artifacts->firstWhere('artifact_type', 'security_report');
        $diffSummary = $this->artifactPayload($artifacts->firstWhere('artifact_type', 'diff_summary'));
        $testSummary = $this->artifactPayload($artifacts->firstWhere('artifact_type', 'test_map'))
            ?: $this->artifactPayload($artifacts->firstWhere('artifact_type', 'command_output'));
        $risk = $this->riskSummary($events, $artifacts);
        $device = $runRow->device_id ? DB::table('devices')->where('id', $runRow->device_id)->first() : null;

        return Inertia::render('Runs/Show', [
            'run' => $runRow,
            'runContext' => [
                'kind' => $this->runKind($run),
                'device_name' => $device?->name ?? 'unknown',
                'started_at' => $runRow->started_at,
                'finished_at' => $runRow->finished_at,
            ],
            'sourceLabel' => 'local_plugin_snapshot',
            'project' => DB::table('projects')->where('id', $runRow->project_id)->first(),
            'repository' => $runRow->repository_id ? DB::table('repositories')->where('id', $runRow->repository_id)->first() : null,
            'events' => $events->map(fn (object $event) => [
                'id' => $event->id,
                'event_type' => $event->event_type,
                'severity' => $event->severity,
                'message' => $event->message,
                'payload' => $this->decodeJson($event->payload),
                'created_at' => $event->created_at,
            ]),
            'artifacts' => $artifacts,
            'dashboard' => [
                'user' => $this->dashboardUser($request->user()),
                'navigation' => $this->dashboardNavigation($request->user(), $runRow->project_id),
            ],
            'risk' => array_merge($risk, [
                'report' => $this->riskReport($events, $artifacts, $runRow),
            ]),
            'safety' => [
                'blocked' => $this->artifactMetadataList($securityReport, 'blocked'),
                'warnings' => $this->artifactMetadataList($securityReport, 'warnings'),
            ],
            'summary' => [
                'diff' => [
                    'changed_file_count' => $diffSummary['changed_file_count'] ?? null,
                    'additions' => $diffSummary['additions'] ?? null,
                    'deletions' => $diffSummary['deletions'] ?? null,
                ],
                'tests' => [
                    'status' => $testSummary['status'] ?? null,
                    'summary' => $testSummary['summary'] ?? null,
                ],
            ],
            'state' => [
                'graph_status' => $artifacts->firstWhere('artifact_type', 'graph_snapshot')?->status ?? 'not_promoted',
                'wiki_status' => DB::table('wiki_revisions')
                    ->whereIn('wiki_page_id', DB::table('wiki_pages')->where('project_id', $runRow->project_id)->pluck('id'))
                    ->where('producer', 'devboard-python-plugin')
                    ->exists() ? 'updated_from_local_analyzer' : 'not_updated',
                'retryable_import' => $this->retryableImport($run),
                'reviewed' => $events->contains(fn (object $event): bool => $event->event_type === 'run.reviewed'),
                'source_truth' => 'local plugin state, not remote Git truth',
            ],
        ]);
    }

    private function runKind(string $runId): string
    {
        if (DB::table('delta_syncs')->where('run_id', $runId)->exists()) {
            return 'delta_sync';
        }

        if (DB::table('genesis_imports')->where('run_id', $runId)->exists()) {
            return 'genesis_import';
        }

        return 'run';
    }

    private function retryableImport(string $runId): bool
    {
        $genesis = DB::table('genesis_imports')->where('run_id', $runId)->first();
        if ($genesis && $genesis->status === 'failed' && $genesis->snapshot_id) {
            $snapshot = DB::table('snapshots')->where('id', $genesis->snapshot_id)->first();

            return $snapshot?->graph_snapshot_artifact_id !== null;
        }

        $delta = DB::table('delta_syncs')->where('run_id', $runId)->first();
        if ($delta && $delta->status === 'failed' && $delta->new_snapshot_id) {
            $snapshot = DB::table('snapshots')->where('id', $delta->new_snapshot_id)->first();

            return $snapshot?->graph_snapshot_artifact_id !== null;
        }

        return false;
    }

    /**
     * @param iterable<object> $events
     * @param iterable<object> $artifacts
     * @return array{triggers: list<string>, severity: string}
     */
    private function riskSummary(iterable $events, iterable $artifacts): array
    {
        $triggers = [];

        foreach ($events as $event) {
            $payload = $this->decodeJson($event->payload);
            foreach (($payload['risk_triggers'] ?? []) as $trigger) {
                $triggers[] = $trigger;
            }

            if (($event->severity ?? 'info') === 'critical') {
                $triggers[] = $event->event_type;
            }
        }

        foreach ($artifacts as $artifact) {
            if (in_array($artifact->status, ['rejected', 'failed'], true)) {
                $triggers[] = "{$artifact->artifact_type}_{$artifact->status}";
            }
        }

        return [
            'triggers' => array_values(array_unique($triggers)),
            'severity' => $triggers === [] ? 'low' : 'needs_review',
        ];
    }

    /**
     * @param iterable<object> $events
     * @param iterable<object> $artifacts
     * @return array{summary: ?string, triggers: list<string>, risk_level: string}
     */
    private function riskReport(iterable $events, iterable $artifacts, object $runRow): array
    {
        foreach ($events as $event) {
            $payload = $this->decodeJson($event->payload);

            if (isset($payload['risk_report']) && is_array($payload['risk_report'])) {
                return [
                    'summary' => $payload['risk_report']['summary'] ?? $runRow->summary,
                    'triggers' => is_array($payload['risk_report']['triggers'] ?? null) ? $payload['risk_report']['triggers'] : [],
                    'risk_level' => $payload['risk_report']['risk_level'] ?? $runRow->risk_level,
                ];
            }
        }

        foreach ($artifacts as $artifact) {
            if ($artifact->artifact_type !== 'risk_report') {
                continue;
            }

            $payload = $this->artifactPayload($artifact);

            if ($payload !== []) {
                return [
                    'summary' => $payload['summary'] ?? $runRow->summary,
                    'triggers' => is_array($payload['triggers'] ?? null) ? $payload['triggers'] : [],
                    'risk_level' => $payload['risk_level'] ?? $runRow->risk_level,
                ];
            }
        }

        return [
            'summary' => $runRow->summary,
            'triggers' => [],
            'risk_level' => $runRow->risk_level,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function decodeJson(?string $payload): array
    {
        if (! $payload) {
            return [];
        }

        $decoded = json_decode($payload, true);

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * @return array<string, mixed>
     */
    private function artifactPayload(?object $artifact): array
    {
        if (! $artifact || ! $artifact->storage_path || ! Storage::disk('local')->exists($artifact->storage_path)) {
            return [];
        }

        return $this->decodeJson(Storage::disk('local')->get($artifact->storage_path));
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function artifactMetadataList(?object $artifact, string $key): array
    {
        if (! $artifact) {
            return [];
        }

        $metadata = $this->decodeJson($artifact->metadata);

        if (array_key_exists($key, $metadata) && is_array($metadata[$key])) {
            return $metadata[$key];
        }

        return [];
    }
}
