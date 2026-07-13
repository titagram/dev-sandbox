<?php

use App\Exceptions\CanonicalGraphProjectionException;
use App\Jobs\ImportGenesisGraphToNeo4j;
use App\Jobs\ImportGraphToNeo4j;
use App\Services\GenesisGraphImportService;
use App\Services\Graph\CanonicalGraphProjectionService;
use App\Services\Graph\CanonicalGraphQueryService;
use App\Services\Graph\CanonicalGraphRepository;
use App\Services\Neo4j\FailingNeo4jClient;
use App\Services\Neo4j\FakeNeo4jClient;
use App\Services\Neo4j\Neo4jClient;
use App\Services\Neo4jClientFactory;
use App\Services\Neo4jRebuildService;
use Database\Seeders\DevBoardSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

beforeEach(function () {
    Storage::fake('local');
    $this->seed(DevBoardSeeder::class);
});

it('keeps canonical projection retry error codes bounded', function () {
    $exception = new CanonicalGraphProjectionException('projection-1', 'SQLSTATE[HY000]: secret endpoint');

    expect($exception->failureCode)->toBe('projection_failed')
        ->and($exception->getMessage())->toBe('projection_failed');
});

it('delegates a valid full graph snapshot to the canonical projector', function () {
    $context = createGraphImportContext();
    $client = new FakeNeo4jClient;

    app(GenesisGraphImportService::class)->importGenesis($context['import_id'], $client);

    $snapshotCommand = collect($client->commands)->first(
        fn (array $command): bool => str_contains($command['cypher'], 'MERGE (v:CanonicalGraphVersion'),
    );

    expect($snapshotCommand)->not->toBeNull();
    expect($snapshotCommand['params']['project_id'])->not->toBeEmpty();
    expect(DB::table('canonical_graph_projections')->value('status'))->toBe('ready');
    expect(DB::table('run_events')->where('run_id', $context['run_id'])->where('event_type', 'graph.imported')->exists())->toBeTrue();
});

it('does not reapply a graph snapshot that was already imported', function () {
    $context = createGraphImportContext();
    $client = new FakeNeo4jClient;
    $service = app(GenesisGraphImportService::class);

    $service->importGenesis($context['import_id'], $client);
    $commandCount = count($client->commands);
    $service->importGenesis($context['import_id'], $client);

    expect($client->commands)->toHaveCount($commandCount);
    expect(DB::table('run_events')
        ->where('run_id', $context['run_id'])
        ->where('event_type', 'graph.imported')
        ->count())->toBe(1);
});

it('detects an imported snapshot by decoding candidate event payloads in PHP', function () {
    $context = createGraphImportContext();
    $client = new FakeNeo4jClient;

    DB::table('run_events')->insert([
        'id' => (string) Str::ulid(),
        'run_id' => $context['run_id'],
        'event_type' => 'graph.imported',
        'severity' => 'info',
        'message' => 'Existing portable graph event.',
        'payload' => json_encode([
            'snapshot_id' => $context['snapshot_id'],
            'mode' => 'fake',
        ], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT),
        'created_at' => now(),
    ]);

    app(GenesisGraphImportService::class)->importGenesis($context['import_id'], $client);

    expect($client->commands)->toBe([]);
});

it('creates Neo4j lookup indexes before importing graph batches', function () {
    $context = createGraphImportContext();
    $client = new FakeNeo4jClient;

    app(GenesisGraphImportService::class)->importGenesis($context['import_id'], $client);

    expect($client->commands[0]['cypher'])->toContain('CREATE INDEX code_node_snapshot_external');
    expect($client->commands[1]['cypher'])->toContain('CREATE INDEX devboard_snapshot_snapshot_id');
    expect($client->commands[2]['cypher'])->toContain('CALL db.awaitIndexes');
});

it('imports full snapshot nodes with canonical scope metadata', function () {
    $context = createGraphImportContext();
    $client = new FakeNeo4jClient;

    app(GenesisGraphImportService::class)->importGenesis($context['import_id'], $client);

    $nodeCommands = array_values(array_filter(
        $client->commands,
        fn (array $command): bool => str_contains($command['cypher'], 'UNWIND $nodes'),
    ));

    expect($nodeCommands)->toHaveCount(1);

    $allNodes = collect($nodeCommands)->flatMap(
        fn (array $cmd): array => $cmd['params']['nodes'],
    )->all();

    expect($allNodes)->toHaveCount(2);
    expect($nodeCommands[0]['params']['source_scope_type'])->toBe('repository')
        ->and($nodeCommands[0]['params']['source_scope_id'])->toBe($context['repository_id']);
});

it('imports relationships with canonical scope metadata', function () {
    $context = createGraphImportContext();
    $client = new FakeNeo4jClient;

    app(GenesisGraphImportService::class)->importGenesis($context['import_id'], $client);

    $relationshipCommand = array_values(array_filter(
        $client->commands,
        fn (array $command): bool => str_contains($command['cypher'], 'UNWIND $relationships'),
    ))[0];

    expect($relationshipCommand['params']['source_scope_type'])->toBe('repository')
        ->and($relationshipCommand['params']['source_scope_id'])->toBe($context['repository_id']);
});

it('imports nodes and relationships with canonical batched Cypher commands', function () {
    $context = createGraphImportContext();
    $client = new FakeNeo4jClient;

    app(GenesisGraphImportService::class)->importGenesis($context['import_id'], $client);

    $nodeBatchCommands = array_values(array_filter(
        $client->commands,
        fn (array $command): bool => str_contains($command['cypher'], 'UNWIND $nodes'),
    ));
    $relationshipBatchCommands = array_values(array_filter(
        $client->commands,
        fn (array $command): bool => str_contains($command['cypher'], 'UNWIND $relationships'),
    ));

    expect($nodeBatchCommands)->toHaveCount(1);
    expect($nodeBatchCommands[0]['params']['graph_version'])->toBeString();

    expect($relationshipBatchCommands)->toHaveCount(1);
    expect($relationshipBatchCommands[0]['params']['relationships'])->toHaveCount(1);
    expect($relationshipBatchCommands[0]['params']['graph_version'])->toBeString();
    expect($relationshipBatchCommands[0]['cypher'])->toContain(':DECLARES');
});

it('routes an affected subgraph resulting version through the canonical projector', function () {
    $context = createGraphImportContext();
    $artifact = DB::table('artifacts')->where('id', $context['artifact_id'])->first();
    $payload = json_decode(Storage::disk('local')->get($artifact->storage_path), true, flags: JSON_THROW_ON_ERROR);
    $payload['graph_mode'] = 'affected_subgraph';
    $payload['base_snapshot_id'] = $context['snapshot_id'];
    $payload['nodes_upserted'] = $payload['nodes'];
    $payload['relationships_upserted'] = $payload['relationships'];
    $payload['nodes_deleted'] = [];
    $payload['relationships_deleted'] = [];
    Storage::disk('local')->put($artifact->storage_path, json_encode($payload, JSON_THROW_ON_ERROR));
    DB::table('artifacts')->where('id', $context['artifact_id'])->update(['sha256' => hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR))]);
    $client = new FakeNeo4jClient;

    app(GenesisGraphImportService::class)->importGraphArtifact($context['snapshot_id'], $context['repository_id'], $context['run_id'], $context['artifact_id'], $client, 'fake', baseSnapshotId: $context['snapshot_id'], deltaId: 'delta-1');

    expect(collect($client->commands)->contains(fn (array $command) => str_contains($command['cypher'], 'clone')))->toBeFalse();
    expect(collect($client->commands)->contains(fn (array $command) => str_contains($command['cypher'], 'CanonicalGraphVersion')))->toBeTrue()
        ->and(collect($client->commands)->contains(fn (array $command) => str_contains($command['cypher'], 'UNWIND $nodes AS node MERGE (n:CanonicalGraphNode')))->toBeTrue();
    expect(DB::table('canonical_graph_projections')->value('status'))->toBe('ready');
    expect(DB::table('run_events')->where('run_id', $context['run_id'])->where('event_type', 'graph.imported')->exists())->toBeTrue();
});

it('claims the exact canonical projection before the first Neo4j call', function (bool $affectedSubgraph) {
    $context = createGraphImportContext();
    if ($affectedSubgraph) {
        makeGraphImportContextAffected($context);
    }
    $client = new class implements Neo4jClient
    {
        public int $calls = 0;

        public function run(string $cypher, array $params = []): mixed
        {
            $this->calls++;
            expect(DB::table('canonical_graph_projections')->count())->toBe(1)
                ->and(DB::table('canonical_graph_projections')->value('status'))->toBe('projecting');

            if (str_contains($cypher, 'RETURN nodes, count(r) AS relationships')) {
                return [['nodes' => $params['expected_nodes'], 'relationships' => $params['expected_relationships']]];
            }
            if (str_contains($cypher, 'RETURN count(a) AS adjacencies')) {
                return [['adjacencies' => $params['expected_adjacencies']]];
            }

            return [];
        }
    };

    app(GenesisGraphImportService::class)->importGraphArtifact(
        $context['snapshot_id'],
        $context['repository_id'],
        $context['run_id'],
        $context['artifact_id'],
        $client,
        'fake',
        baseSnapshotId: $affectedSubgraph ? $context['snapshot_id'] : null,
        deltaId: $affectedSubgraph ? 'delta-claim-order' : null,
    );

    expect($client->calls)->toBeGreaterThan(0)
        ->and(DB::table('canonical_graph_projections')->value('status'))->toBe('ready');
})->with([
    'full snapshot' => false,
    'affected subgraph' => true,
]);

it('does not project or publish while another worker owns the exact projection', function () {
    $context = createGraphImportContext();
    DB::table('artifacts')->where('id', $context['artifact_id'])->update(['status' => 'uploaded']);
    $canonical = canonicalGraphForImportContext($context);
    $projections = app(CanonicalGraphProjectionService::class);
    $projection = $projections->queue($canonical);
    expect($projections->claimForWorker($projection->id))->toBeTrue();
    $client = new FakeNeo4jClient;
    $caught = null;

    try {
        app(GenesisGraphImportService::class)->importGenesis($context['import_id'], $client, 'fake', false);
    } catch (Throwable $exception) {
        $caught = $exception;
    }

    expect($caught)->not->toBeNull()
        ->and(property_exists($caught, 'projectionId'))->toBeTrue()
        ->and($caught->projectionId)->toBe($projection->id)
        ->and(property_exists($caught, 'failureCode'))->toBeTrue()
        ->and($caught->failureCode)->toBe('projection_busy')
        ->and($client->commands)->toBe([])
        ->and(DB::table('run_events')->where('run_id', $context['run_id'])->where('event_type', 'graph.imported')->exists())->toBeFalse()
        ->and(DB::table('artifacts')->where('id', $context['artifact_id'])->value('status'))->toBe('uploaded');
});

it('treats an exact ready projection as idempotent success without another Neo4j write', function () {
    $context = createGraphImportContext();
    $canonical = canonicalGraphForImportContext($context);
    $projections = app(CanonicalGraphProjectionService::class);
    $projection = $projections->queue($canonical);
    expect($projections->claimForWorker($projection->id))->toBeTrue()
        ->and($projections->markReady($projection->id, 2, 1))->toBeTrue();
    $client = new FakeNeo4jClient;

    app(GenesisGraphImportService::class)->importGenesis($context['import_id'], $client, 'fake', false);

    expect($client->commands)->toBe([])
        ->and(DB::table('canonical_graph_projections')->where('id', $projection->id)->value('status'))->toBe('ready')
        ->and(DB::table('canonical_graph_projections')->where('id', $projection->id)->value('node_count'))->toBe(2)
        ->and(DB::table('run_events')->where('run_id', $context['run_id'])->where('event_type', 'graph.imported')->count())->toBe(1);
});

it('does not publish success when projection ownership is lost before mark ready', function () {
    $context = createGraphImportContext();
    $client = new class implements Neo4jClient
    {
        public function run(string $cypher, array $params = []): mixed
        {
            if (str_contains($cypher, "candidate.status = 'ready'")) {
                DB::table('canonical_graph_projections')->update(['status' => 'failed']);
            }
            if (str_contains($cypher, 'RETURN nodes, count(r) AS relationships')) {
                return [['nodes' => $params['expected_nodes'], 'relationships' => $params['expected_relationships']]];
            }
            if (str_contains($cypher, 'RETURN count(a) AS adjacencies')) {
                return [['adjacencies' => $params['expected_adjacencies']]];
            }

            return [];
        }
    };
    $caught = null;

    try {
        app(GenesisGraphImportService::class)->importGenesis($context['import_id'], $client, 'fake', false);
    } catch (Throwable $exception) {
        $caught = $exception;
    }

    expect($caught)->not->toBeNull()
        ->and(property_exists($caught, 'failureCode'))->toBeTrue()
        ->and($caught->failureCode)->toBe('ownership_lost')
        ->and(DB::table('canonical_graph_projections')->value('status'))->toBe('failed')
        ->and(DB::table('run_events')->where('run_id', $context['run_id'])->where('event_type', 'graph.imported')->exists())->toBeFalse();
});

it('releases an owned projection for the next outer job attempt', function (bool $affectedSubgraph) {
    $context = createGraphImportContext();
    if ($affectedSubgraph) {
        makeGraphImportContextAffected($context);
    }
    $client = new class implements Neo4jClient
    {
        public function run(string $cypher, array $params = []): mixed
        {
            if (str_contains($cypher, 'CanonicalGraphVersion')) {
                throw new RuntimeException('simulated canonical projection failure');
            }

            return [];
        }
    };
    $caught = null;

    try {
        app(GenesisGraphImportService::class)->importGraphArtifact(
            $context['snapshot_id'],
            $context['repository_id'],
            $context['run_id'],
            $context['artifact_id'],
            $client,
            'fake',
            baseSnapshotId: $affectedSubgraph ? $context['snapshot_id'] : null,
            deltaId: $affectedSubgraph ? 'delta-retry' : null,
        );
    } catch (Throwable $exception) {
        $caught = $exception;
    }

    $projection = DB::table('canonical_graph_projections')->first();
    expect($caught)->not->toBeNull()
        ->and(property_exists($caught, 'projectionId'))->toBeTrue()
        ->and($caught->projectionId)->toBe($projection->id)
        ->and($caught->failureCode)->toBe('neo4j_query_failed')
        ->and($projection->status)->toBe('queued');

    app(GenesisGraphImportService::class)->importGraphArtifact(
        $context['snapshot_id'],
        $context['repository_id'],
        $context['run_id'],
        $context['artifact_id'],
        new FakeNeo4jClient,
        'fake',
        baseSnapshotId: $affectedSubgraph ? $context['snapshot_id'] : null,
        deltaId: $affectedSubgraph ? 'delta-retry' : null,
    );

    expect(DB::table('canonical_graph_projections')->where('id', $projection->id)->value('status'))->toBe('ready')
        ->and(DB::table('run_events')->where('run_id', $context['run_id'])->where('event_type', 'graph.imported')->count())->toBe(1);
})->with([
    'full snapshot' => false,
    'affected subgraph' => true,
]);

it('marks only its queued canonical projection failed after final queue exhaustion', function () {
    $context = createGraphImportContext();
    $caught = canonicalProjectionFailure($context);
    $projection = DB::table('canonical_graph_projections')->first();

    (new ImportGraphToNeo4j('genesis', $context['import_id']))->failed($caught);

    expect(DB::table('canonical_graph_projections')->where('id', $projection->id)->value('status'))->toBe('failed')
        ->and(DB::table('canonical_graph_projections')->where('id', $projection->id)->value('error_code'))->toBe('neo4j_query_failed')
        ->and(DB::table('genesis_imports')->where('id', $context['import_id'])->value('status'))->toBe('failed');

    $canonical = canonicalGraphForImportContext($context);
    $first = app(CanonicalGraphProjectionService::class)->claimForReconcile([$canonical]);
    $second = app(CanonicalGraphProjectionService::class)->claimForReconcile([$canonical]);
    $claimKey = array_key_first($first);
    expect($first[$claimKey]['claimed'])->toBeTrue()
        ->and($second[$claimKey]['claimed'])->toBeFalse();
});

it('does not let an exhausted delivery overwrite a competing projection owner', function () {
    $context = createGraphImportContext();
    $caught = canonicalProjectionFailure($context);
    $projection = DB::table('canonical_graph_projections')->first();
    expect(app(CanonicalGraphProjectionService::class)->claimForWorker($projection->id))->toBeTrue();

    (new ImportGraphToNeo4j('genesis', $context['import_id']))->failed($caught);

    expect(DB::table('canonical_graph_projections')->where('id', $projection->id)->value('status'))->toBe('projecting')
        ->and(DB::table('canonical_graph_projections')->where('id', $projection->id)->value('error_code'))->toBeNull();
});

it('marks the exact queued delta projection failed after final queue exhaustion', function () {
    $context = createGraphImportContext();
    $caught = canonicalProjectionFailure($context);
    $projection = DB::table('canonical_graph_projections')->first();
    $import = DB::table('genesis_imports')->where('id', $context['import_id'])->first();
    $deltaId = (string) Str::ulid();
    DB::table('delta_syncs')->insert([
        'id' => $deltaId,
        'project_id' => $import->project_id,
        'repository_id' => $import->repository_id,
        'local_workspace_id' => $import->local_workspace_id,
        'run_id' => $import->run_id,
        'status' => 'active',
        'base_snapshot_id' => $context['snapshot_id'],
        'new_snapshot_id' => $context['snapshot_id'],
        'branch' => 'main',
        'base_sha' => 'abc123',
        'head_sha' => 'abc123',
        'dirty_status' => 'clean',
        'changed_file_count' => 1,
        'risk_level' => 'low',
        'started_at' => now(),
        'finished_at' => null,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    (new ImportGraphToNeo4j('delta', $deltaId))->failed($caught);

    expect(DB::table('canonical_graph_projections')->where('id', $projection->id)->value('status'))->toBe('failed')
        ->and(DB::table('delta_syncs')->where('id', $deltaId)->value('status'))->toBe('failed')
        ->and(DB::table('run_events')->where('run_id', $context['run_id'])->where('event_type', 'graph.import_failed')->exists())->toBeTrue();
});

it('marks the import failed when Neo4j import fails', function () {
    $context = createGraphImportContext();

    expect(fn () => app(GenesisGraphImportService::class)->importGenesis($context['import_id'], new FailingNeo4jClient))
        ->toThrow(RuntimeException::class);

    expect(DB::table('genesis_imports')->where('id', $context['import_id'])->value('status'))->toBe('failed');
    expect(DB::table('run_events')->where('run_id', $context['run_id'])->where('event_type', 'graph.imported')->exists())->toBeFalse();
});

it('keeps the import active while a queued graph retry is still pending', function () {
    $context = createGraphImportContext();

    expect(fn () => app(GenesisGraphImportService::class)->importGenesis(
        $context['import_id'],
        new FailingNeo4jClient,
        'neo4j',
        false,
    ))->toThrow(RuntimeException::class);

    expect(DB::table('genesis_imports')->where('id', $context['import_id'])->value('status'))->toBe('active');
    expect(DB::table('run_events')->where('run_id', $context['run_id'])->where('event_type', 'graph.import_failed')->exists())->toBeFalse();
});

it('marks the import failed and records a run event after queue retries are exhausted', function () {
    $context = createGraphImportContext();

    (new ImportGenesisGraphToNeo4j($context['import_id']))->failed(new RuntimeException('neo4j unavailable'));

    expect(DB::table('genesis_imports')->where('id', $context['import_id'])->value('status'))->toBe('failed');
    expect(DB::table('run_events')
        ->where('run_id', $context['run_id'])
        ->where('event_type', 'graph.import_failed')
        ->exists())->toBeTrue();
});

it('builds a Neo4j client from configured basic auth', function () {
    config([
        'services.neo4j.uri' => 'bolt://localhost:7687',
        'services.neo4j.auth' => ['neo4j', 'redacted-rotated-neo4j-password'],
    ]);

    expect(app(Neo4jClientFactory::class)->client())->toBeObject();
});

it('rebuilds a Neo4j projection from stored graph artifacts', function () {
    $context = createGraphImportContext();
    $client = new FakeNeo4jClient;

    DB::table('run_events')->insert([
        'id' => (string) Str::ulid(),
        'run_id' => $context['run_id'],
        'event_type' => 'graph.imported',
        'severity' => 'info',
        'message' => 'Graph was already imported before rebuild.',
        'payload' => json_encode(['snapshot_id' => $context['snapshot_id'], 'mode' => 'fake'], JSON_THROW_ON_ERROR),
        'created_at' => now(),
    ]);

    $result = app(Neo4jRebuildService::class)->rebuild([
        'snapshot_id' => $context['snapshot_id'],
    ], $client, 'fake');

    expect($result)->toMatchArray([
        'scanned' => 1,
        'rebuilt' => 1,
        'failed' => 0,
    ]);

    expect($client->commands[0]['cypher'])->toContain('DETACH DELETE');
    expect($client->commands[0]['params']['snapshot_id'])->toBe($context['snapshot_id']);
    expect(collect($client->commands)->contains(
        fn (array $command): bool => str_contains($command['cypher'], 'UNWIND $nodes'),
    ))->toBeTrue();
    expect(collect($client->commands)->contains(
        fn (array $command): bool => str_contains($command['cypher'], 'DevBoardSnapshot'),
    ))->toBeTrue();
    expect(DB::table('run_events')
        ->where('run_id', $context['run_id'])
        ->where('event_type', 'graph.imported')
        ->where('message', 'Neo4j rebuild validated in fake mode.')
        ->exists())->toBeTrue();
});

it('force rebuilds an already ready canonical projection instead of taking the idempotent shortcut', function () {
    $context = createGraphImportContext();
    $client = new FakeNeo4jClient;
    $service = app(GenesisGraphImportService::class);

    $service->importGenesis($context['import_id'], $client);
    $projection = DB::table('canonical_graph_projections')->first();
    expect($projection->status)->toBe('ready');
    $client->commands = [];

    $service->importGraphArtifact(
        $context['snapshot_id'],
        $context['repository_id'],
        $context['run_id'],
        $context['artifact_id'],
        $client,
        'fake',
        'Forced graph rebuild completed.',
        force: true,
    );

    $cypher = collect($client->commands)->pluck('cypher');
    $event = DB::table('run_events')->where('run_id', $context['run_id'])->where('message', 'Forced graph rebuild completed.')->first();
    $eventPayload = json_decode($event->payload, true, flags: JSON_THROW_ON_ERROR);
    expect($cypher->filter(fn (string $query): bool => str_contains($query, 'CREATE INDEX'))->count())->toBeGreaterThanOrEqual(2)
        ->and($cypher->contains(fn (string $query): bool => str_contains($query, 'UNWIND $nodes')))->toBeTrue()
        ->and($cypher->contains(fn (string $query): bool => str_contains($query, 'UNWIND $relationships')))->toBeTrue()
        ->and($cypher->contains(fn (string $query): bool => str_contains($query, 'RETURN nodes, count(r) AS relationships')))->toBeTrue()
        ->and($cypher->contains(fn (string $query): bool => str_contains($query, 'candidate.current = true')))->toBeTrue()
        ->and(DB::table('canonical_graph_projections')->where('id', $projection->id)->value('status'))->toBe('ready')
        ->and(DB::table('run_events')->where('run_id', $context['run_id'])->where('event_type', 'graph.imported')->count())->toBe(2)
        ->and($eventPayload['node_count'])->toBe(2)
        ->and($eventPayload['relationship_count'])->toBe(1)
        ->and($eventPayload['forced_rebuild'])->toBeTrue();
});

it('keeps the verified graph queryable until a forced rebuild publishes atomically', function () {
    $context = createGraphImportContext();
    $service = app(GenesisGraphImportService::class);
    $service->importGenesis($context['import_id'], new FakeNeo4jClient);
    $published = DB::table('canonical_graph_projections')->first();
    $during = null;

    $client = new class implements Neo4jClient
    {
        public Closure $during;

        public bool $observed = false;

        public function run(string $cypher, array $params = []): mixed
        {
            if (! $this->observed && str_contains($cypher, 'CREATE INDEX canonical_node_version_external')) {
                $this->observed = true;
                ($this->during)();
            }
            if (str_contains($cypher, 'RETURN nodes, count(r) AS relationships')) {
                return [['nodes' => $params['expected_nodes'], 'relationships' => $params['expected_relationships']]];
            }
            if (str_contains($cypher, 'RETURN count(a) AS adjacencies')) {
                return [['adjacencies' => $params['expected_adjacencies']]];
            }

            return [];
        }
    };
    $client->during = function () use ($context, &$during): void {
        $during = canonicalGraphQueryProbe($context);
    };

    $service->importGraphArtifact(
        $context['snapshot_id'], $context['repository_id'], $context['run_id'],
        $context['artifact_id'], $client, 'fake', force: true,
    );
    $after = canonicalGraphQueryProbe($context);

    expect($during['result']['found'])->toBeTrue()
        ->and($during['params']['graph_version'])->toBe($published->graph_version)
        ->and($after['result']['found'])->toBeTrue()
        ->and($after['result']['graph_version'])->toBe($published->graph_version)
        ->and($after['params']['graph_version'])->not->toBe($during['params']['graph_version'])
        ->and(DB::table('canonical_graph_projections')->where('id', $published->id)->value('status'))->toBe('ready');
});

it('keeps the verified graph queryable after a failed forced rebuild', function () {
    $context = createGraphImportContext();
    $service = app(GenesisGraphImportService::class);
    $service->importGenesis($context['import_id'], new FakeNeo4jClient);
    $published = DB::table('canonical_graph_projections')->first();
    $during = null;

    $client = new class implements Neo4jClient
    {
        public Closure $during;

        public function run(string $cypher, array $params = []): mixed
        {
            if (str_contains($cypher, 'CREATE INDEX canonical_node_version_external')) {
                ($this->during)();
                throw new RuntimeException('simulated forced rebuild failure');
            }

            return [];
        }
    };
    $client->during = function () use ($context, &$during): void {
        $during = canonicalGraphQueryProbe($context);
    };

    expect(fn () => $service->importGraphArtifact(
        $context['snapshot_id'], $context['repository_id'], $context['run_id'],
        $context['artifact_id'], $client, 'fake', force: true,
    ))->toThrow(CanonicalGraphProjectionException::class, 'neo4j_query_failed');
    $after = canonicalGraphQueryProbe($context);

    expect($during['result']['found'])->toBeTrue()
        ->and($after['result']['found'])->toBeTrue()
        ->and($after['params']['graph_version'])->toBe($during['params']['graph_version'])
        ->and($after['params']['graph_version'])->toBe($published->graph_version)
        ->and(DB::table('canonical_graph_projections')->where('id', $published->id)->value('status'))->toBe('ready');
});

it('allows only one of two interleaved force callers to project and publish', function () {
    $context = createGraphImportContext();
    $service = app(GenesisGraphImportService::class);
    $service->importGenesis($context['import_id'], new FakeNeo4jClient);
    $secondFailure = null;

    $client = new class implements Neo4jClient
    {
        /** @var list<array{cypher: string, params: array<string, mixed>}> */
        public array $commands = [];

        public bool $interleaved = false;

        public Closure $secondCall;

        public function run(string $cypher, array $params = []): mixed
        {
            $this->commands[] = ['cypher' => $cypher, 'params' => $params];
            if (! $this->interleaved) {
                $this->interleaved = true;
                ($this->secondCall)();
            }
            if (str_contains($cypher, 'RETURN nodes, count(r) AS relationships')) {
                return [['nodes' => $params['expected_nodes'], 'relationships' => $params['expected_relationships']]];
            }
            if (str_contains($cypher, 'RETURN count(a) AS adjacencies')) {
                return [['adjacencies' => $params['expected_adjacencies']]];
            }

            return [];
        }
    };
    $client->secondCall = function () use ($service, $context, $client, &$secondFailure): void {
        try {
            $service->importGraphArtifact(
                $context['snapshot_id'],
                $context['repository_id'],
                $context['run_id'],
                $context['artifact_id'],
                $client,
                'fake',
                force: true,
            );
        } catch (Throwable $exception) {
            $secondFailure = $exception;
        }
    };

    $service->importGraphArtifact(
        $context['snapshot_id'],
        $context['repository_id'],
        $context['run_id'],
        $context['artifact_id'],
        $client,
        'fake',
        force: true,
    );

    expect($secondFailure)->toBeInstanceOf(CanonicalGraphProjectionException::class)
        ->and($secondFailure->failureCode)->toBe('projection_busy')
        ->and(collect($client->commands)->filter(fn (array $command): bool => str_contains($command['cypher'], 'UNWIND $nodes')))->toHaveCount(1)
        ->and(DB::table('run_events')->where('run_id', $context['run_id'])->where('event_type', 'graph.imported')->count())->toBe(2);
});

it('does no Neo4j IO or event publishing when a forced rebuild is already active', function (string $status) {
    $context = createGraphImportContext();
    $client = new FakeNeo4jClient;
    $service = app(GenesisGraphImportService::class);
    $service->importGenesis($context['import_id'], $client);
    DB::table('canonical_graph_projections')->update(['status' => $status]);
    $client->commands = [];
    $eventsBefore = DB::table('run_events')->where('run_id', $context['run_id'])->where('event_type', 'graph.imported')->count();

    expect(fn () => $service->importGraphArtifact(
        $context['snapshot_id'],
        $context['repository_id'],
        $context['run_id'],
        $context['artifact_id'],
        $client,
        'fake',
        force: true,
    ))->toThrow(CanonicalGraphProjectionException::class, 'projection_busy');

    expect($client->commands)->toBe([])
        ->and(DB::table('run_events')->where('run_id', $context['run_id'])->where('event_type', 'graph.imported')->count())->toBe($eventsBefore);
})->with(['queued', 'projecting']);

it('counts a rebuild only after a forced projection actually succeeds', function () {
    $context = createGraphImportContext();
    $service = app(GenesisGraphImportService::class);
    $service->importGenesis($context['import_id'], new FakeNeo4jClient);
    DB::table('canonical_graph_projections')->update(['status' => 'projecting']);
    $client = new FakeNeo4jClient;

    $result = app(Neo4jRebuildService::class)->rebuild([
        'snapshot_id' => $context['snapshot_id'],
    ], $client, 'fake');

    expect($result)->toMatchArray([
        'scanned' => 1,
        'rebuilt' => 0,
        'failed' => 1,
    ])->and($client->commands)->toBe([]);
});

it('uses config-driven retry and backoff values for queued graph imports', function () {
    config([
        'services.devboard.graph_import_job_tries' => 4,
        'services.devboard.graph_import_job_backoff_seconds' => [0, 1, 2],
    ]);

    $job = new ImportGenesisGraphToNeo4j('import_123');

    expect($job->tries)->toBe(4);
    expect($job->backoff())->toBe([0, 1, 2]);
});

it('exposes an artisan command to rebuild Neo4j projection by snapshot', function () {
    $context = createGraphImportContext();

    $exitCode = Artisan::call('devboard:neo4j-rebuild', [
        '--snapshot' => $context['snapshot_id'],
        '--mode' => 'fake',
    ]);

    expect($exitCode)->toBe(0);
    expect(Artisan::output())->toContain('Rebuilt 1 graph projection');
});

it('uses a non-recording verifier for production fake rebuild mode', function () {
    $service = app(Neo4jRebuildService::class);
    $method = new ReflectionMethod($service, 'fakeClient');
    $client = $method->invoke($service);

    expect($client)->not->toBeInstanceOf(FakeNeo4jClient::class)
        ->and(property_exists($client, 'commands'))->toBeFalse();
    $client->run('UNWIND $nodes AS node RETURN node', ['nodes' => array_fill(0, 1000, ['large' => str_repeat('x', 1000)])]);
    expect($client->run('RETURN nodes, count(r) AS relationships', ['expected_nodes' => 2, 'expected_relationships' => 1]))
        ->toBe([['nodes' => 2, 'relationships' => 1]]);
});

it('does not purge an existing projection when the stored graph artifact is missing', function () {
    $context = createGraphImportContext();
    $client = new FakeNeo4jClient;
    $storagePath = DB::table('artifacts')->where('id', $context['artifact_id'])->value('storage_path');

    Storage::disk('local')->delete($storagePath);

    $result = app(Neo4jRebuildService::class)->rebuild([
        'snapshot_id' => $context['snapshot_id'],
    ], $client, 'fake');

    expect($result)->toMatchArray([
        'scanned' => 1,
        'rebuilt' => 0,
        'failed' => 1,
    ]);
    expect($result['failures'][0]['message'])->toContain('Stored graph artifact is not readable');
    expect($client->commands)->toBe([]);
});

/**
 * @return array<string, string>
 */
function canonicalGraphForImportContext(array $context): array
{
    $projectId = DB::table('artifacts')->where('id', $context['artifact_id'])->value('project_id');
    $canonical = app(CanonicalGraphRepository::class)->findByIdentity(
        $projectId,
        'repository',
        $context['repository_id'],
        'legacy_artifact',
        $context['artifact_id'],
    );
    expect($canonical)->not->toBeNull();

    return $canonical;
}

/** @return array{result: array<string, mixed>, params: array<string, mixed>} */
function canonicalGraphQueryProbe(array $context): array
{
    $client = new class implements Neo4jClient
    {
        public array $params = [];

        public function run(string $cypher, array $params = []): mixed
        {
            $this->params = $params;

            return [];
        }
    };
    $projectId = DB::table('artifacts')->where('id', $context['artifact_id'])->value('project_id');
    $result = (new CanonicalGraphQueryService($client))->query(
        $projectId, 'repository', $context['repository_id'], 'callers', ['symbol_id' => 'function:health'],
    );

    return ['result' => $result, 'params' => $client->params];
}

function makeGraphImportContextAffected(array $context): void
{
    $artifact = DB::table('artifacts')->where('id', $context['artifact_id'])->first();
    $payload = json_decode(Storage::disk('local')->get($artifact->storage_path), true, flags: JSON_THROW_ON_ERROR);
    $payload['graph_mode'] = 'affected_subgraph';
    $payload['base_snapshot_id'] = $context['snapshot_id'];
    $payload['nodes_upserted'] = $payload['nodes'];
    $payload['relationships_upserted'] = $payload['relationships'];
    $payload['nodes_deleted'] = [];
    $payload['relationships_deleted'] = [];
    $encoded = json_encode($payload, JSON_THROW_ON_ERROR);
    Storage::disk('local')->put($artifact->storage_path, $encoded);
    DB::table('artifacts')->where('id', $context['artifact_id'])->update(['sha256' => hash('sha256', $encoded)]);
}

function canonicalProjectionFailure(array $context): Throwable
{
    $client = new class implements Neo4jClient
    {
        public function run(string $cypher, array $params = []): mixed
        {
            if (str_contains($cypher, 'CanonicalGraphVersion')) {
                throw new RuntimeException('simulated canonical projection failure');
            }

            return [];
        }
    };

    try {
        app(GenesisGraphImportService::class)->importGenesis($context['import_id'], $client, 'fake', false);
    } catch (Throwable $exception) {
        return $exception;
    }

    throw new RuntimeException('Expected the canonical projection to fail.');
}

/**
 * @return array<string, string>
 */
function createGraphImportContext(): array
{
    $userId = DB::table('users')->where('email', 'admin@example.com')->value('id');
    $projectId = DB::table('projects')->where('slug', 'demo-project')->value('id');
    $repositoryId = DB::table('repositories')->where('slug', 'demo-repository')->value('id');
    $now = now();
    $deviceId = (string) Str::ulid();
    $workspaceId = (string) Str::ulid();
    $runId = (string) Str::ulid();
    $artifactId = (string) Str::ulid();
    $snapshotId = (string) Str::ulid();
    $importId = (string) Str::ulid();
    $storagePath = "devboard/artifacts/genesis/{$importId}/{$artifactId}/artifact";

    DB::table('devices')->insert([
        'id' => $deviceId,
        'user_id' => $userId,
        'name' => 'Graph Import Device',
        'fingerprint_hash' => 'sha256:graph-import-device',
        'platform_os' => 'linux',
        'platform_arch' => 'amd64',
        'plugin_version' => '0.1.0',
        'last_seen_at' => $now,
        'status' => 'active',
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    DB::table('local_workspaces')->insert([
        'id' => $workspaceId,
        'repository_id' => $repositoryId,
        'device_id' => $deviceId,
        'local_root_hash' => 'sha256:graph-import-workspace',
        'display_path' => '/tmp/graph-import-workspace',
        'current_branch' => 'main',
        'last_head_sha' => 'abc123',
        'dirty_status' => 'clean',
        'last_snapshot_id' => null,
        'last_seen_at' => $now,
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    DB::table('runs')->insert([
        'id' => $runId,
        'project_id' => $projectId,
        'repository_id' => $repositoryId,
        'local_workspace_id' => $workspaceId,
        'task_id' => null,
        'device_id' => $deviceId,
        'started_by_user_id' => $userId,
        'runtime_profile' => 'agent_plugin',
        'status' => 'started',
        'branch' => 'main',
        'base_branch' => 'main',
        'base_sha' => 'abc123',
        'head_sha' => 'abc123',
        'summary' => null,
        'risk_level' => 'low',
        'started_at' => $now,
        'finished_at' => null,
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    Storage::disk('local')->put($storagePath, json_encode([
        'nodes' => [
            ['id' => 'file:app.py', 'labels' => ['File'], 'properties' => ['path' => 'app.py']],
            ['id' => 'function:health', 'labels' => ['Symbol', 'Function'], 'properties' => ['name' => 'health']],
        ],
        'relationships' => [
            ['id' => 'rel_1', 'type' => 'DECLARES', 'source_id' => 'file:app.py', 'target_id' => 'function:health', 'properties' => []],
        ],
    ], JSON_THROW_ON_ERROR));

    DB::table('artifacts')->insert([
        'id' => $artifactId,
        'project_id' => $projectId,
        'repository_id' => $repositoryId,
        'run_id' => $runId,
        'artifact_type' => 'graph_snapshot',
        'storage_path' => $storagePath,
        'sha256' => str_repeat('a', 64),
        'size_bytes' => 1,
        'mime_type' => 'application/json',
        'schema_version' => 'v1',
        'status' => 'imported',
        'producer' => 'devboard-python-plugin',
        'metadata' => '{}',
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    DB::table('snapshots')->insert([
        'id' => $snapshotId,
        'project_id' => $projectId,
        'repository_id' => $repositoryId,
        'local_workspace_id' => $workspaceId,
        'source_type' => 'local_plugin_snapshot',
        'branch' => 'main',
        'base_sha' => 'abc123',
        'head_sha' => 'abc123',
        'dirty_status' => 'clean',
        'file_inventory_artifact_id' => null,
        'graph_snapshot_artifact_id' => $artifactId,
        'created_by_run_id' => $runId,
        'created_at' => $now,
    ]);

    DB::table('genesis_imports')->insert([
        'id' => $importId,
        'project_id' => $projectId,
        'repository_id' => $repositoryId,
        'local_workspace_id' => $workspaceId,
        'run_id' => $runId,
        'status' => 'active',
        'manifest_artifact_id' => null,
        'snapshot_id' => $snapshotId,
        'base_branch' => 'main',
        'base_sha' => 'abc123',
        'head_sha' => 'abc123',
        'started_at' => $now,
        'finished_at' => $now,
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    return [
        'import_id' => $importId,
        'snapshot_id' => $snapshotId,
        'artifact_id' => $artifactId,
        'run_id' => $runId,
        'repository_id' => $repositoryId,
    ];
}
