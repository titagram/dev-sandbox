<?php

namespace App\Services\Graph;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

class CanonicalGraphProjectionService
{
    public function queue(array $graph): object
    {
        $identity = $graph['identity'];
        $artifactType = (string) $identity['artifact_type'];
        $artifactId = (string) $identity['artifact_id'];
        $checksum = (string) $identity['checksum'];
        $now = now();

        DB::table('canonical_graph_projections')->insertOrIgnore([
            'id' => (string) Str::ulid(),
            'project_id' => $identity['project_id'],
            'source_scope_type' => $identity['source_scope_type'],
            'source_scope_id' => $identity['source_scope_id'],
            'artifact_type' => $artifactType,
            'artifact_id' => $artifactId,
            'graph_version' => hash('sha256', $artifactType.'|'.$artifactId.'|'.$checksum),
            'checksum' => $checksum,
            'head_commit' => $graph['contract']['source']['head_commit'] ?? null,
            'quality' => $graph['contract']['extractor']['quality'],
            'status' => 'queued',
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return DB::table('canonical_graph_projections')
            ->where('artifact_type', $artifactType)
            ->where('artifact_id', $artifactId)
            ->firstOrFail();
    }

    public function markProjecting(string $id): void
    {
        DB::table('canonical_graph_projections')->where('id', $id)->update([
            'status' => 'projecting',
            'error_code' => null,
            'updated_at' => now(),
        ]);
    }

    public function markReady(string $id, int $nodes, int $relationships): void
    {
        DB::transaction(function () use ($id, $nodes, $relationships): void {
            $candidate = DB::table('canonical_graph_projections')->where('id', $id)->lockForUpdate()->first();
            if ($candidate === null) {
                throw new RuntimeException('Canonical graph projection not found.');
            }

            DB::table('canonical_graph_projections')
                ->where('project_id', $candidate->project_id)
                ->where('source_scope_type', $candidate->source_scope_type)
                ->where('source_scope_id', $candidate->source_scope_id)
                ->where('status', 'ready')
                ->where('id', '!=', $id)
                ->update(['status' => 'stale', 'updated_at' => now()]);

            DB::table('canonical_graph_projections')->where('id', $id)->update([
                'status' => 'ready',
                'node_count' => $nodes,
                'relationship_count' => $relationships,
                'error_code' => null,
                'projected_at' => now(),
                'updated_at' => now(),
            ]);
        });
    }

    public function markFailed(string $id, string $code): void
    {
        $boundedCode = preg_match('/\A[a-z0-9_]{1,100}\z/', $code) === 1 ? $code : 'projection_failed';
        DB::table('canonical_graph_projections')->where('id', $id)->update([
            'status' => 'failed',
            'error_code' => $boundedCode,
            'updated_at' => now(),
        ]);
    }

    public function readyForScope(string $projectId, string $scopeType, string $scopeId): ?object
    {
        return DB::table('canonical_graph_projections')
            ->where('project_id', $projectId)
            ->where('source_scope_type', $scopeType)
            ->where('source_scope_id', $scopeId)
            ->where('status', 'ready')
            ->orderByDesc('projected_at')
            ->orderByDesc('id')
            ->first();
    }
}
