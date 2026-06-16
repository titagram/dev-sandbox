<?php

namespace App\Http\Controllers\Dashboard;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Dashboard\Concerns\ChecksDashboardRoles;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
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
        $risk = $this->riskSummary($events, $artifacts);

        return Inertia::render('Runs/Show', [
            'run' => $runRow,
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
            'risk' => $risk,
            'safety' => [
                'blocked' => $this->artifactMetadataList($securityReport, 'blocked'),
                'warnings' => $this->artifactMetadataList($securityReport, 'warnings'),
            ],
            'state' => [
                'graph_status' => $artifacts->firstWhere('artifact_type', 'graph_snapshot')?->status ?? 'not_promoted',
                'wiki_status' => DB::table('wiki_revisions')
                    ->whereIn('wiki_page_id', DB::table('wiki_pages')->where('project_id', $runRow->project_id)->pluck('id'))
                    ->where('producer', 'devboard-python-plugin')
                    ->exists() ? 'updated_from_local_analyzer' : 'not_updated',
                'source_truth' => 'local plugin state, not remote Git truth',
            ],
        ]);
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
