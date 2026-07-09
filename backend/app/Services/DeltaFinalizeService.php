<?php

namespace App\Services;

use App\Jobs\ImportGraphToNeo4j;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class DeltaFinalizeService
{
    public function __construct(
        private readonly ArtifactStorageService $storage,
        private readonly WikiRevisionService $wiki,
    )
    {
    }

    /**
     * @return array{status: string, snapshot_id: string}
     */
    public function finalize(string $deltaId, bool $allowBlockedSecurityFindings = false): array
    {
        $delta = DB::table('delta_syncs')->where('id', $deltaId)->first();
        if (! $delta) {
            throw new ArtifactStorageException('schema_validation_failed', 'Delta sync was not found.');
        }

        $artifacts = DB::table('artifacts')
            ->where('run_id', $delta->run_id)
            ->where('repository_id', $delta->repository_id)
            ->get();

        foreach ($artifacts as $artifact) {
            $this->storage->assembleArtifact($artifact, $deltaId, 'delta');
        }

        $securityReport = $artifacts->firstWhere('artifact_type', 'security_report');
        if ($securityReport) {
            $report = json_decode($this->storage->artifactContents($securityReport), true, 512, JSON_THROW_ON_ERROR);
            $blocked = $this->blockedFindings($report);
            if ($blocked !== [] && ! $allowBlockedSecurityFindings) {
                DB::table('delta_syncs')->where('id', $deltaId)->update([
                    'status' => 'failed',
                    'updated_at' => now(),
                ]);

                throw new ArtifactStorageException('secret_scan_blocked', 'Security report contains blocked findings.');
            }

            if ($blocked !== []) {
                $this->recordBlockedSecurityApproval(
                    runId: $delta->run_id,
                    targetType: 'delta_sync',
                    targetId: $deltaId,
                    blocked: $blocked,
                );
            }
        }

        $fileInventory = $artifacts->firstWhere('artifact_type', 'file_inventory')
            ?? $artifacts->firstWhere('artifact_type', 'file_hashes');
        $graphSnapshot = $artifacts->firstWhere('artifact_type', 'graph_snapshot');
        $snapshotId = (string) Str::ulid();
        $now = now();

        DB::table('snapshots')->insert([
            'id' => $snapshotId,
            'project_id' => $delta->project_id,
            'repository_id' => $delta->repository_id,
            'local_workspace_id' => $delta->local_workspace_id,
            'source_type' => 'local_plugin_snapshot',
            'branch' => $delta->branch,
            'base_sha' => $delta->base_sha,
            'head_sha' => $delta->head_sha,
            'dirty_status' => $delta->dirty_status,
            'file_inventory_artifact_id' => $fileInventory?->id,
            'graph_snapshot_artifact_id' => $graphSnapshot?->id,
            'created_by_run_id' => $delta->run_id,
            'created_at' => $now,
        ]);

        DB::table('delta_syncs')->where('id', $deltaId)->update([
            'new_snapshot_id' => $snapshotId,
            'updated_at' => $now,
        ]);

        $this->writeWikiRevisions($artifacts, $delta);

        DB::table('artifacts')
            ->where('run_id', $delta->run_id)
            ->where('repository_id', $delta->repository_id)
            ->update(['status' => 'imported', 'updated_at' => $now]);

        DB::table('delta_syncs')->where('id', $deltaId)->update([
            'status' => 'active',
            'finished_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('local_workspaces')->where('id', $delta->local_workspace_id)->update([
            'last_snapshot_id' => $snapshotId,
            'last_head_sha' => $delta->head_sha,
            'dirty_status' => $delta->dirty_status,
            'updated_at' => now(),
        ]);

        DB::table('run_events')->insert([
            'id' => (string) Str::ulid(),
            'run_id' => $delta->run_id,
            'event_type' => 'delta.finalized',
            'severity' => 'info',
            'message' => 'Delta sync finalized.',
            'payload' => json_encode([
                'delta_id' => $deltaId,
                'base_snapshot_id' => $delta->base_snapshot_id,
                'snapshot_id' => $snapshotId,
                'changed_file_count' => $delta->changed_file_count,
                'risk_level' => $delta->risk_level,
            ], JSON_THROW_ON_ERROR),
            'created_at' => now(),
        ]);

        DB::table('audit_logs')->insert([
            'id' => (string) Str::ulid(),
            'actor_user_id' => null,
            'actor_device_id' => null,
            'actor_type' => 'plugin',
            'action' => 'delta.finalized',
            'target_type' => 'delta_sync',
            'target_id' => $deltaId,
            'ip_address' => null,
            'user_agent' => null,
            'payload' => json_encode(['snapshot_id' => $snapshotId], JSON_THROW_ON_ERROR),
            'created_at' => now(),
        ]);

        if ($graphSnapshot) {
            ImportGraphToNeo4j::dispatch('delta', $deltaId);
        }

        return ['status' => 'active', 'snapshot_id' => $snapshotId];
    }

    /**
     * @return list<array<string, string>>
     */
    private function blockedFindings(array $report): array
    {
        $blocked = $report['blocked'] ?? [];
        if (! is_array($blocked)) {
            return [];
        }

        $findings = [];
        foreach ($blocked as $item) {
            if (is_array($item)) {
                $findings[] = [
                    'path' => (string) ($item['path'] ?? 'unknown'),
                    'reason' => (string) ($item['reason'] ?? 'unknown'),
                ];
            } else {
                $findings[] = [
                    'path' => (string) $item,
                    'reason' => 'unknown',
                ];
            }
        }

        return $findings;
    }

    /**
     * @param list<array<string, string>> $blocked
     */
    private function recordBlockedSecurityApproval(string $runId, string $targetType, string $targetId, array $blocked): void
    {
        $run = DB::table('runs')->where('id', $runId)->first();
        $payload = [
            'approval' => 'plugin_explicit_override',
            'blocked_count' => count($blocked),
            'blocked' => array_slice($blocked, 0, 50),
        ];

        DB::table('run_events')->insert([
            'id' => (string) Str::ulid(),
            'run_id' => $runId,
            'event_type' => 'security.blocked_upload_approved',
            'severity' => 'warning',
            'message' => 'Blocked security findings were explicitly approved by the local plugin user.',
            'payload' => json_encode($payload, JSON_THROW_ON_ERROR),
            'created_at' => now(),
        ]);

        DB::table('audit_logs')->insert([
            'id' => (string) Str::ulid(),
            'actor_user_id' => null,
            'actor_device_id' => $run?->device_id,
            'actor_type' => 'plugin',
            'action' => 'security.blocked_upload_approved',
            'target_type' => $targetType,
            'target_id' => $targetId,
            'ip_address' => null,
            'user_agent' => null,
            'payload' => json_encode($payload, JSON_THROW_ON_ERROR),
            'created_at' => now(),
        ]);
    }

    private function writeWikiRevisions(object $artifacts, object $delta): void
    {
        $wikiArtifact = $artifacts->firstWhere('artifact_type', 'wiki_pages');
        if (! $wikiArtifact) {
            return;
        }

        $run = DB::table('runs')->where('id', $delta->run_id)->first();
        $document = json_decode($this->storage->artifactContents($wikiArtifact), true, 512, JSON_THROW_ON_ERROR);

        foreach ($document['pages'] ?? [] as $page) {
            $this->wiki->write(array_merge($page, [
                'project_id' => $page['project_id'] ?? $delta->project_id,
                'repository_id' => $page['repository_id'] ?? $delta->repository_id,
                'producer' => $page['producer'] ?? 'plugin',
                'evidence_refs' => $page['evidence_refs'] ?? [],
            ]), null, $run?->device_id);
        }
    }
}
