<?php

namespace App\Http\Controllers\Dashboard;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Dashboard\Concerns\ChecksDashboardRoles;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response;

class GraphShowController extends Controller
{
    use ChecksDashboardRoles;

    public function __invoke(Request $request): Response
    {
        $runId = $request->query('run');
        $project = DB::table('projects')->orderBy('created_at')->first();
        $run = $runId ? DB::table('runs')->where('id', $runId)->firstOrFail() : null;

        $snapshot = DB::table('snapshots')
            ->when($runId, fn ($query, string $id) => $query->where('created_by_run_id', $id))
            ->when(! $runId && $project, fn ($query) => $query->where('project_id', $project->id))
            ->orderByDesc('created_at')
            ->first();

        $artifact = $snapshot?->graph_snapshot_artifact_id
            ? DB::table('artifacts')->where('id', $snapshot->graph_snapshot_artifact_id)->first()
            : null;

        $project ??= $snapshot ? DB::table('projects')->where('id', $snapshot->project_id)->first() : null;
        $repository = $snapshot?->repository_id ? DB::table('repositories')->where('id', $snapshot->repository_id)->first() : null;
        $linkedRun = $run ?? ($snapshot?->created_by_run_id ? DB::table('runs')->where('id', $snapshot->created_by_run_id)->first() : null);

        return Inertia::render('Graph/Show', [
            'sourceLabel' => 'local_plugin_snapshot',
            'project' => $project,
            'repository' => $repository,
            'linkedRun' => $linkedRun ? [
                'id' => $linkedRun->id,
                'status' => $linkedRun->status,
                'href' => "/runs/{$linkedRun->id}",
            ] : null,
            'snapshot' => $snapshot ? [
                'id' => $snapshot->id,
                'project_id' => $snapshot->project_id,
                'repository_id' => $snapshot->repository_id,
                'run_id' => $snapshot->created_by_run_id,
                'source_type' => $snapshot->source_type,
                'branch' => $snapshot->branch,
                'base_sha' => $snapshot->base_sha,
                'head_sha' => $snapshot->head_sha,
                'dirty_status' => $snapshot->dirty_status,
                'created_at' => $snapshot->created_at,
            ] : null,
            'graph' => $this->graphSummary($artifact),
            'dashboard' => [
                'user' => $this->dashboardUser($request->user()),
                'navigation' => $this->dashboardNavigation($request->user(), $project?->id ?? $snapshot?->project_id),
            ],
        ]);
    }

    /**
     * @return array{
     *     artifact_status: string,
     *     artifact_id: ?string,
     *     node_count: int,
     *     relationship_count: int,
     *     labels: list<array{name: string, count: int}>
     * }
     */
    private function graphSummary(?object $artifact): array
    {
        if (! $artifact || ! $artifact->storage_path || ! Storage::disk('local')->exists($artifact->storage_path)) {
            return [
                'artifact_status' => $artifact->status ?? 'missing',
                'artifact_id' => $artifact->id ?? null,
                'extraction_mode' => 'unknown',
                'parser' => 'unknown',
                'analyzer' => 'unknown',
                'node_count' => 0,
                'relationship_count' => 0,
                'labels' => [],
            ];
        }

        $payload = json_decode(Storage::disk('local')->get($artifact->storage_path), true);
        $metadata = $this->decodeJson($artifact->metadata);
        $nodes = is_array($payload['nodes'] ?? null) ? $payload['nodes'] : [];
        $relationships = is_array($payload['relationships'] ?? null) ? $payload['relationships'] : [];
        $labelCounts = [];

        foreach ($nodes as $node) {
            foreach (($node['labels'] ?? []) as $label) {
                if (! is_string($label)) {
                    continue;
                }

                $labelCounts[$label] = ($labelCounts[$label] ?? 0) + 1;
            }
        }

        /** @var Collection<int, array{name: string, count: int}> $labels */
        $labels = collect($labelCounts)
            ->sortKeys()
            ->map(fn (int $count, string $name): array => ['name' => $name, 'count' => $count])
            ->sortByDesc('count')
            ->values();

        return [
            'artifact_status' => $artifact->status,
            'artifact_id' => $artifact->id,
            'extraction_mode' => $metadata['graph_extraction_mode'] ?? 'unknown',
            'parser' => $metadata['graph_parser'] ?? 'unknown',
            'analyzer' => $metadata['graph_analyzer'] ?? 'unknown',
            'node_count' => count($nodes),
            'relationship_count' => count($relationships),
            'labels' => $labels->all(),
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
}
