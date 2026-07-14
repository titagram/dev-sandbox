<?php

namespace App\Services;

use App\Jobs\ProjectCanonicalGraphToNeo4j;
use App\Services\Graph\CanonicalGraphProjectionService;
use App\Services\Graph\CanonicalGraphRepository;
use App\Services\Neo4j\Neo4jClient;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;
use RuntimeException;

class Neo4jRebuildService
{
    private const RECONCILE_BATCH_SIZE = 25;

    public function __construct(
        private readonly GenesisGraphImportService $graphs,
        private readonly Neo4jClientFactory $clients,
        private readonly CanonicalGraphRepository $canonicalGraphs,
        private readonly CanonicalGraphProjectionService $canonicalProjections,
    ) {}

    /**
     * Reconcile persisted canonical artifacts with their rebuildable Neo4j projections.
     *
     * @param  array{project_id?: string|null, scope_type?: string|null, scope_id?: string|null, dry_run?: bool, force?: bool}  $filters
     * @return array{scanned: int, queued: int, forced: int, ready: int, failed: int, skipped: int, dry_run: bool}
     */
    public function reconcile(array $filters = []): array
    {
        $projectId = $this->optionalString($filters['project_id'] ?? null);
        $scopeType = $this->optionalString($filters['scope_type'] ?? null);
        $scopeId = $this->optionalString($filters['scope_id'] ?? null);
        $dryRun = (bool) ($filters['dry_run'] ?? false);
        $force = (bool) ($filters['force'] ?? false);
        $this->assertCanonicalFilters($projectId, $scopeType, $scopeId, $force);

        $summary = [
            'scanned' => 0,
            'queued' => 0,
            'forced' => 0,
            'ready' => 0,
            'failed' => 0,
            'skipped' => 0,
            'dry_run' => $dryRun,
        ];

        if ($scopeType !== null) {
            $summary['scanned'] = 1;
            try {
                $graph = $this->canonicalGraphs->latestForScope($projectId, $scopeType, $scopeId);
            } catch (\Throwable) {
                $summary['failed'] = 1;

                return $summary;
            }
            $this->processCanonicalItems([[
                'graph' => $graph,
                'failed' => false,
            ]], $dryRun, $force, $summary);

            return $summary;
        }

        foreach (['workspace_binding', 'repository'] as $candidateScopeType) {
            $cursor = null;
            do {
                $batch = $this->canonicalGraphs->scopeBatch(
                    $projectId,
                    $candidateScopeType,
                    $cursor,
                    self::RECONCILE_BATCH_SIZE,
                );
                $summary['scanned'] += count($batch['items']);
                $this->processCanonicalItems($batch['items'], $dryRun, $force, $summary);
                $cursor = $batch['next_cursor'];
            } while ($cursor !== null);
        }

        return $summary;
    }

    /**
     * @param  list<array{graph: array|null, failed: bool}>  $items
     * @param  array{scanned: int, queued: int, forced: int, ready: int, failed: int, skipped: int, dry_run: bool}  $summary
     */
    private function processCanonicalItems(array $items, bool $dryRun, bool $force, array &$summary): void
    {
        $graphs = [];
        foreach ($items as $item) {
            if ($item['failed']) {
                $summary['failed']++;

                continue;
            }
            if ($item['graph'] === null) {
                $summary['skipped']++;

                continue;
            }
            $graphs[] = $item['graph'];
        }
        if ($graphs === []) {
            return;
        }

        if ($dryRun) {
            $projections = $this->canonicalProjections->findForGraphs($graphs);
            foreach ($graphs as $graph) {
                $key = $this->canonicalProjectionKey($graph);
                $projection = $projections[$key] ?? null;
                if ($projection !== null && ! $this->canonicalProjections->matchesGraph($projection, $graph)) {
                    $summary['failed']++;
                } elseif ($force && ($projection === null || in_array($projection->status, ['ready', 'stale', 'failed'], true))) {
                    $summary['forced']++;
                } elseif ($projection === null || $projection->status === 'failed') {
                    $summary['queued']++;
                } elseif ($projection->status === 'ready') {
                    $summary['ready']++;
                } else {
                    $summary['skipped']++;
                }
            }

            return;
        }

        if ($force) {
            foreach ($graphs as $graph) {
                try {
                    $this->graphs->forceCanonicalGraphProjection($graph);
                    $summary['forced']++;
                } catch (\Throwable) {
                    $summary['failed']++;
                }
            }

            return;
        }

        $claims = $this->canonicalProjections->claimForReconcile($graphs);
        foreach ($graphs as $graph) {
            $claim = $claims[$this->canonicalProjectionKey($graph)];
            if ($claim['conflict']) {
                $summary['failed']++;

                continue;
            }
            if ($claim['claimed']) {
                $summary['queued']++;
                ProjectCanonicalGraphToNeo4j::dispatch($claim['projection']->id)->afterCommit();

                continue;
            }
            if ($claim['projection']->status === 'ready') {
                $summary['ready']++;
            } else {
                $summary['skipped']++;
            }
        }
    }

    private function canonicalProjectionKey(array $graph): string
    {
        return $graph['identity']['artifact_type']."\0".$graph['identity']['artifact_id'];
    }

    /**
     * @param  array{project_id?: string|null, repository_id?: string|null, snapshot_id?: string|null}  $filters
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
                $this->graphs->importGraphArtifact(
                    $snapshot->snapshot_id,
                    $snapshot->repository_id,
                    $snapshot->run_id,
                    $snapshot->artifact_id,
                    $client,
                    $mode,
                    'Neo4j rebuild validated in fake mode.',
                    'Neo4j projection rebuilt from stored artifact.',
                    force: true,
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
     * @param  array{project_id?: string|null, repository_id?: string|null, snapshot_id?: string|null}  $filters
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

    private function fakeClient(): Neo4jClient
    {
        return new class implements Neo4jClient
        {
            public function run(string $cypher, array $params = []): mixed
            {
                if (str_contains($cypher, 'RETURN nodes, count(r) AS relationships')) {
                    return [[
                        'nodes' => $params['expected_nodes'],
                        'relationships' => $params['expected_relationships'],
                    ]];
                }

                if (str_contains($cypher, 'RETURN count(a) AS adjacencies')) {
                    return [['adjacencies' => $params['expected_adjacencies']]];
                }

                return [];
            }
        };
    }

    private function optionalString(mixed $value): ?string
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        return trim($value);
    }

    private function assertCanonicalFilters(?string $projectId, ?string $scopeType, ?string $scopeId, bool $force = false): void
    {
        if (($scopeType === null) !== ($scopeId === null)) {
            throw new InvalidArgumentException('Both --scope-type and --scope-id are required together.');
        }
        if ($projectId === null) {
            throw new InvalidArgumentException('Canonical reconcile requires --project.');
        }
        if ($scopeType !== null && ! in_array($scopeType, ['workspace_binding', 'repository'], true)) {
            throw new InvalidArgumentException('Invalid --scope-type. Use workspace_binding or repository.');
        }
        if ($force && $scopeType === null) {
            throw new InvalidArgumentException('Forced reconcile requires exact --scope-type and --scope-id.');
        }
    }
}
