<?php

namespace App\Services\Dashboard;

use Illuminate\Support\Facades\DB;

final class ProjectOperationalStatusResolver
{
    /**
     * @return array{
     *     source: string,
     *     graph: array<string, mixed>,
     *     workspace: array<string, mixed>,
     *     genesis: array<string, mixed>,
     *     artifacts: array<string, mixed>
     * }
     */
    public function forProject(string $projectId): array
    {
        $repositoryCount = (int) DB::table('repositories')
            ->where('project_id', $projectId)
            ->count();
        $linkedBindingCount = (int) DB::table('hades_workspace_bindings')
            ->where('project_id', $projectId)
            ->where('status', 'linked')
            ->count();
        $linkedLocalWorkspaceCount = (int) DB::table('local_workspaces')
            ->join('repositories', 'repositories.id', '=', 'local_workspaces.repository_id')
            ->where('repositories.project_id', $projectId)
            ->count();
        $projection = $this->readyProjection($projectId);
        $latestProjection = $this->latestProjection($projectId);
        $legacyArtifactCount = (int) DB::table('artifacts')
            ->where('project_id', $projectId)
            ->where('artifact_type', 'graph_snapshot')
            ->count();
        $latestGenesisStatus = DB::table('genesis_imports')
            ->where('project_id', $projectId)
            ->orderByDesc('created_at')
            ->value('status');

        $hasCanonicalWorkspaceBinding = $projection?->source_scope_type === 'workspace_binding';
        $linkedCount = max($linkedBindingCount, $linkedLocalWorkspaceCount, $hasCanonicalWorkspaceBinding ? 1 : 0);
        $workspaceStatus = $linkedCount > 0
            ? ($linkedCount < max($repositoryCount, 1) ? 'partial' : 'linked')
            : 'missing';

        $graph = $this->graphStatus($projection, $latestProjection);
        $genesis = $this->genesisStatus($projection, $latestGenesisStatus);
        $artifacts = [
            'status' => $projection !== null || $legacyArtifactCount > 0 ? 'available' : 'empty',
            'legacy_count' => $legacyArtifactCount,
            'reason' => $projection !== null
                ? 'Canonical graph artifacts are available; the legacy artifact list may be empty.'
                : ($legacyArtifactCount > 0 ? 'Graph artifacts are available.' : 'No graph artifacts are available yet.'),
        ];

        return [
            'source' => $projection !== null ? 'canonical_graph_projection' : 'project_operational_records',
            'graph' => $graph,
            'workspace' => [
                'status' => $workspaceStatus,
                'linked_count' => $linkedCount,
                'repository_count' => $repositoryCount,
                'reason' => match ($workspaceStatus) {
                    'linked' => 'A workspace is linked to this project.',
                    'partial' => 'Some project repositories have a workspace link; others still need one.',
                    default => 'No workspace is linked to this project yet.',
                },
            ],
            'genesis' => $genesis,
            'artifacts' => $artifacts,
        ];
    }

    private function readyProjection(string $projectId): ?object
    {
        return DB::table('canonical_graph_projections')
            ->where('project_id', $projectId)
            ->where('status', 'ready')
            ->whereNotNull('active_graph_version')
            ->where('active_graph_version', '!=', '')
            ->orderByRaw("CASE source_scope_type WHEN 'workspace_binding' THEN 0 ELSE 1 END")
            ->orderByDesc('projected_at')
            ->orderByDesc('id')
            ->first();
    }

    private function latestProjection(string $projectId): ?object
    {
        return DB::table('canonical_graph_projections')
            ->where('project_id', $projectId)
            ->orderByRaw("CASE status WHEN 'ready' THEN 4 WHEN 'projecting' THEN 3 WHEN 'queued' THEN 2 WHEN 'stale' THEN 1 WHEN 'failed' THEN 1 ELSE 0 END DESC")
            ->orderByDesc('projected_at')
            ->orderByDesc('id')
            ->first();
    }

    /** @return array<string, mixed> */
    private function graphStatus(?object $readyProjection, ?object $latestProjection): array
    {
        if ($readyProjection !== null) {
            return [
                'status' => 'ready',
                'canonical' => true,
                'scope_type' => (string) $readyProjection->source_scope_type,
                'scope_id' => (string) $readyProjection->source_scope_id,
                'quality' => (string) ($readyProjection->quality ?? 'unknown'),
                'node_count' => (int) ($readyProjection->node_count ?? 0),
                'relationship_count' => (int) ($readyProjection->relationship_count ?? 0),
                'reason' => 'Canonical graph projection is ready.',
            ];
        }

        $status = (string) ($latestProjection?->status ?? 'not_started');

        return [
            'status' => match ($status) {
                'failed', 'stale' => 'partial',
                'projecting', 'queued' => 'not_ready',
                default => 'not_indexed',
            },
            'canonical' => false,
            'scope_type' => $latestProjection?->source_scope_type ? (string) $latestProjection->source_scope_type : null,
            'scope_id' => $latestProjection?->source_scope_id ? (string) $latestProjection->source_scope_id : null,
            'quality' => $latestProjection?->quality ? (string) $latestProjection->quality : null,
            'node_count' => $latestProjection?->node_count === null ? null : (int) $latestProjection->node_count,
            'relationship_count' => $latestProjection?->relationship_count === null ? null : (int) $latestProjection->relationship_count,
            'reason' => match ($status) {
                'failed' => 'The latest canonical graph projection failed and needs another import.',
                'stale' => 'The canonical graph projection is stale and needs another import.',
                'projecting', 'queued' => 'The canonical graph projection is still being prepared.',
                default => 'No ready canonical graph projection is indexed yet.',
            },
        ];
    }

    /** @return array<string, mixed> */
    private function genesisStatus(?object $readyProjection, mixed $latestStatus): array
    {
        if ($readyProjection !== null) {
            return [
                'status' => 'complete',
                'reason' => 'Genesis analysis is represented in the canonical projection.',
            ];
        }

        $status = $this->pipelineStatus($latestStatus);

        return [
            'status' => $status,
            'reason' => match ($status) {
                'complete' => 'Genesis analysis is complete.',
                'in_progress' => 'Genesis analysis is in progress.',
                'failed' => 'Genesis analysis failed and needs attention.',
                default => 'Genesis analysis has not completed yet.',
            },
        ];
    }

    private function pipelineStatus(mixed $status): string
    {
        return match ((string) $status) {
            'complete', 'completed', 'success', 'succeeded', 'ready' => 'complete',
            'active', 'started', 'queued', 'running', 'uploading', 'in_progress' => 'in_progress',
            'failed', 'error' => 'failed',
            'stale' => 'stale',
            default => 'not_started',
        };
    }
}
