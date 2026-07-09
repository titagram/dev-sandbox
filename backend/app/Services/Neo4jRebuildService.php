<?php

namespace App\Services;

use App\Services\Neo4j\Neo4jClient;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;
use RuntimeException;

class Neo4jRebuildService
{
    public function __construct(
        private readonly GenesisGraphImportService $graphs,
        private readonly Neo4jClientFactory $clients,
    ) {
    }

    /**
     * @param array{project_id?: string|null, repository_id?: string|null, snapshot_id?: string|null} $filters
     * @return array{scanned: int, rebuilt: int, skipped: int, failed: int, failures: list<array{snapshot_id: string, message: string}>}
     */
    public function rebuild(array $filters = [], ?Neo4jClient $client = null, ?string $mode = null): array
    {
        $mode ??= config('services.devboard.graph_import_mode') === 'fake' ? 'fake' : 'neo4j';

        if (! in_array($mode, ['fake', 'neo4j'], true)) {
            throw new InvalidArgumentException('Neo4j rebuild mode must be fake or neo4j.');
        }

        $client ??= $mode === 'fake' ? $this->fakeClient() : $this->clients->client();
        $snapshots = $this->snapshots($filters);
        $result = [
            'scanned' => $snapshots->count(),
            'rebuilt' => 0,
            'skipped' => 0,
            'failed' => 0,
            'failures' => [],
        ];

        foreach ($snapshots as $snapshot) {
            if (! in_array($snapshot->artifact_status, ['validated', 'imported'], true)) {
                $result['skipped']++;

                continue;
            }

            try {
                $this->ensureGraphArtifactReadable($snapshot);
                $this->purgeSnapshot($client, $snapshot->snapshot_id);
                $this->graphs->importGraphArtifact(
                    $snapshot->snapshot_id,
                    $snapshot->repository_id,
                    $snapshot->run_id,
                    $snapshot->artifact_id,
                    $client,
                    $mode,
                    'Neo4j rebuild validated in fake mode.',
                    'Neo4j projection rebuilt from stored artifact.',
                );
                $result['rebuilt']++;
            } catch (\Throwable $exception) {
                $result['failed']++;
                $result['failures'][] = [
                    'snapshot_id' => $snapshot->snapshot_id,
                    'message' => $exception->getMessage(),
                ];
            }
        }

        return $result;
    }

    /**
     * @param array{project_id?: string|null, repository_id?: string|null, snapshot_id?: string|null} $filters
     * @return Collection<int, object>
     */
    private function snapshots(array $filters): Collection
    {
        return DB::table('snapshots')
            ->join('artifacts', 'artifacts.id', '=', 'snapshots.graph_snapshot_artifact_id')
            ->select([
                'snapshots.id as snapshot_id',
                'snapshots.project_id',
                'snapshots.repository_id',
                'snapshots.created_by_run_id as run_id',
                'snapshots.graph_snapshot_artifact_id as artifact_id',
                'artifacts.status as artifact_status',
                'artifacts.storage_path as artifact_storage_path',
            ])
            ->where('artifacts.artifact_type', 'graph_snapshot')
            ->when($filters['project_id'] ?? null, fn ($query, string $projectId) => $query->where('snapshots.project_id', $projectId))
            ->when($filters['repository_id'] ?? null, fn ($query, string $repositoryId) => $query->where('snapshots.repository_id', $repositoryId))
            ->when($filters['snapshot_id'] ?? null, fn ($query, string $snapshotId) => $query->where('snapshots.id', $snapshotId))
            ->orderBy('snapshots.created_at')
            ->get();
    }

    private function ensureGraphArtifactReadable(object $snapshot): void
    {
        if (! Storage::disk('local')->exists($snapshot->artifact_storage_path)) {
            throw new RuntimeException('Stored graph artifact is not readable.');
        }
    }

    private function purgeSnapshot(Neo4jClient $client, string $snapshotId): void
    {
        $params = ['snapshot_id' => $snapshotId];

        $client->run('MATCH (n:CodeNode {snapshot_id: $snapshot_id}) DETACH DELETE n', $params);
        $client->run('MATCH (s:DevBoardSnapshot {snapshot_id: $snapshot_id}) DETACH DELETE s', $params);
    }

    private function fakeClient(): Neo4jClient
    {
        return new class implements Neo4jClient
        {
            public function run(string $cypher, array $params = []): mixed
            {
                return [];
            }
        };
    }
}
