<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class GenesisFinalizeService
{
    public function __construct(
        private readonly ArtifactStorageService $storage,
        private readonly WikiRevisionService $wiki,
        private readonly GraphImportQueueService $graphQueue,
    ) {}

    /**
     * @return array{status: string, snapshot_id: string}
     */
    public function finalize(string $importId, bool $allowBlockedSecurityFindings = false): array
    {
        $dispatchGraphImportAfterCommit = false;
        $transactionalQueue = $this->graphQueue->isTransactionalDatabaseQueue();
        $rejectedArtifact = null;

        try {
            $result = DB::transaction(function () use ($importId, $allowBlockedSecurityFindings, $transactionalQueue, &$dispatchGraphImportAfterCommit, &$rejectedArtifact): array {
                $import = DB::table('genesis_imports')->where('id', $importId)->lockForUpdate()->first();
                if (! $import) {
                    throw new ArtifactStorageException('schema_validation_failed', 'Genesis import was not found.');
                }

                if ($import->snapshot_id !== null) {
                    $snapshotHasGraph = DB::table('snapshots')
                        ->where('id', $import->snapshot_id)
                        ->whereNotNull('graph_snapshot_artifact_id')
                        ->exists();
                    if ($snapshotHasGraph && $this->graphQueue->needsDispatch('genesis', $importId)) {
                        if ($transactionalQueue) {
                            $this->graphQueue->dispatchIfNeeded('genesis', $importId);
                        } else {
                            $dispatchGraphImportAfterCommit = true;
                        }
                    }

                    return ['status' => (string) $import->status, 'snapshot_id' => (string) $import->snapshot_id];
                }

                $artifacts = DB::table('artifacts')
                    ->where('project_id', $import->project_id)
                    ->where('repository_id', $import->repository_id)
                    ->where('run_id', $import->run_id)
                    ->where('storage_path', 'like', "devboard/artifacts/genesis/{$importId}/%/artifact")
                    ->get();

                foreach ($artifacts as $artifact) {
                    $rejectedArtifact = $artifact;
                    $this->storage->assembleArtifact($artifact, $importId);
                    $rejectedArtifact = null;
                }

                $securityReport = $artifacts->firstWhere('artifact_type', 'security_report');
                if ($securityReport) {
                    $report = json_decode($this->storage->artifactContents($securityReport), true, 512, JSON_THROW_ON_ERROR);
                    $blocked = $this->blockedFindings($report);
                    if ($blocked !== [] && ! $allowBlockedSecurityFindings) {
                        throw new ArtifactStorageException('secret_scan_blocked', 'Security report contains blocked findings.');
                    }

                    if ($blocked !== []) {
                        $this->recordBlockedSecurityApproval(
                            runId: $import->run_id,
                            targetType: 'genesis_import',
                            targetId: $importId,
                            blocked: $blocked,
                        );
                    }
                }

                $this->writeWikiRevisions($artifacts, $import);

                $artifactIds = $artifacts->pluck('id')->all();
                if ($artifactIds !== []) {
                    DB::table('artifacts')->whereIn('id', $artifactIds)->update([
                        'status' => 'imported',
                        'updated_at' => now(),
                    ]);
                }

                $fileInventory = $artifacts->firstWhere('artifact_type', 'file_inventory');
                $graphSnapshot = $artifacts->firstWhere('artifact_type', 'graph_snapshot');
                $snapshotId = (string) Str::ulid();

                DB::table('snapshots')->insert([
                    'id' => $snapshotId,
                    'project_id' => $import->project_id,
                    'repository_id' => $import->repository_id,
                    'local_workspace_id' => $import->local_workspace_id,
                    'source_type' => 'local_plugin_snapshot',
                    'branch' => $import->base_branch,
                    'base_sha' => $import->base_sha,
                    'head_sha' => $import->head_sha,
                    'dirty_status' => 'clean',
                    'file_inventory_artifact_id' => $fileInventory?->id,
                    'graph_snapshot_artifact_id' => $graphSnapshot?->id,
                    'created_by_run_id' => $import->run_id,
                    'created_at' => now(),
                ]);

                DB::table('genesis_imports')->where('id', $importId)->update([
                    'status' => 'active',
                    'snapshot_id' => $snapshotId,
                    'finished_at' => now(),
                    'updated_at' => now(),
                ]);

                DB::table('run_events')->insert([
                    'id' => (string) Str::ulid(),
                    'run_id' => $import->run_id,
                    'event_type' => 'genesis.finalized',
                    'severity' => 'info',
                    'message' => 'Genesis import finalized.',
                    'payload' => json_encode(['genesis_import_id' => $importId, 'snapshot_id' => $snapshotId], JSON_THROW_ON_ERROR),
                    'created_at' => now(),
                ]);

                DB::table('audit_logs')->insert([
                    'id' => (string) Str::ulid(),
                    'actor_user_id' => null,
                    'actor_device_id' => null,
                    'actor_type' => 'plugin',
                    'action' => 'genesis.finalized',
                    'target_type' => 'genesis_import',
                    'target_id' => $importId,
                    'ip_address' => null,
                    'user_agent' => null,
                    'payload' => json_encode(['snapshot_id' => $snapshotId], JSON_THROW_ON_ERROR),
                    'created_at' => now(),
                ]);

                if ($graphSnapshot) {
                    if ($transactionalQueue) {
                        $this->graphQueue->dispatchIfNeeded('genesis', $importId);
                    } else {
                        $dispatchGraphImportAfterCommit = true;
                    }
                }

                return ['status' => 'active', 'snapshot_id' => $snapshotId];
            }, 3);

            if ($dispatchGraphImportAfterCommit) {
                $this->graphQueue->dispatchIfNeeded('genesis', $importId);
            }

            return $result;
        } catch (ArtifactStorageException $exception) {
            if ($rejectedArtifact !== null) {
                app(AuditLogger::class)->record('artifact.rejected', 'artifact', $rejectedArtifact->id, [
                    'artifact_type' => $rejectedArtifact->artifact_type,
                    'reason' => $exception->getMessage(),
                ], ['type' => 'plugin']);
            }

            if ($exception->errorCode === 'secret_scan_blocked') {
                DB::table('genesis_imports')->where('id', $importId)->whereNull('snapshot_id')->update([
                    'status' => 'failed',
                    'updated_at' => now(),
                ]);
            }

            throw $exception;
        }
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
     * @param  list<array<string, string>>  $blocked
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

    private function writeWikiRevisions(object $artifacts, object $import): void
    {
        $wikiArtifact = $artifacts->firstWhere('artifact_type', 'wiki_pages');
        if (! $wikiArtifact) {
            return;
        }

        $run = DB::table('runs')->where('id', $import->run_id)->first();
        $document = json_decode($this->storage->artifactContents($wikiArtifact), true, 512, JSON_THROW_ON_ERROR);

        foreach ($document['pages'] ?? [] as $page) {
            $this->wiki->write(array_merge($page, [
                'project_id' => $page['project_id'] ?? $import->project_id,
                'repository_id' => $page['repository_id'] ?? $import->repository_id,
                'producer' => $page['producer'] ?? 'plugin',
                'evidence_refs' => $page['evidence_refs'] ?? [],
            ]), null, $run?->device_id);
        }
    }
}
