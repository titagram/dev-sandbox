<?php

use App\Services\GenesisGraphImportService;
use App\Services\Neo4jClientFactory;
use App\Services\Neo4jRebuildService;
use App\Jobs\ImportGenesisGraphToNeo4j;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

beforeEach(function () {
    Storage::fake('local');
    $this->seed(\Database\Seeders\DevBoardSeeder::class);
});

it('imports a valid graph snapshot with a DevBoardSnapshot command first', function () {
    $context = createGraphImportContext();
    $client = new FakeNeo4jClient();

    app(GenesisGraphImportService::class)->importGenesis($context['import_id'], $client);

    $snapshotCommand = collect($client->commands)->first(
        fn (array $command): bool => str_contains($command['cypher'], 'MERGE (s:DevBoardSnapshot'),
    );

    expect($snapshotCommand)->not->toBeNull();
    expect($snapshotCommand['params']['snapshot_id'])->toBe($context['snapshot_id']);
    expect(DB::table('run_events')->where('run_id', $context['run_id'])->where('event_type', 'graph.imported')->exists())->toBeTrue();
});

it('creates Neo4j lookup indexes before importing graph batches', function () {
    $context = createGraphImportContext();
    $client = new FakeNeo4jClient();

    app(GenesisGraphImportService::class)->importGenesis($context['import_id'], $client);

    expect($client->commands[0]['cypher'])->toContain('CREATE INDEX code_node_snapshot_external');
    expect($client->commands[1]['cypher'])->toContain('CREATE INDEX devboard_snapshot_snapshot_id');
    expect($client->commands[2]['cypher'])->toContain('CALL db.awaitIndexes');
});

it('imports file and function nodes with snapshot metadata', function () {
    $context = createGraphImportContext();
    $client = new FakeNeo4jClient();

    app(GenesisGraphImportService::class)->importGenesis($context['import_id'], $client);

    $nodeCommand = array_values(array_filter(
        $client->commands,
        fn (array $command): bool => str_contains($command['cypher'], 'UNWIND $nodes'),
    ))[0];

    expect($nodeCommand['params']['nodes'])->toHaveCount(2);
    expect($nodeCommand['params']['nodes'][0]['properties']['snapshot_id'])->toBe($context['snapshot_id']);
    expect($nodeCommand['params']['nodes'][1]['properties']['repository_id'])->toBe($context['repository_id']);
});

it('imports relationships with run and repository metadata', function () {
    $context = createGraphImportContext();
    $client = new FakeNeo4jClient();

    app(GenesisGraphImportService::class)->importGenesis($context['import_id'], $client);

    $relationshipCommand = array_values(array_filter(
        $client->commands,
        fn (array $command): bool => str_contains($command['cypher'], 'UNWIND $relationships'),
    ))[0];

    expect($relationshipCommand['params']['relationships'][0]['properties'])->toMatchArray([
        'run_id' => $context['run_id'],
        'repository_id' => $context['repository_id'],
    ]);
});

it('imports nodes and relationships with batched Cypher commands', function () {
    $context = createGraphImportContext();
    $client = new FakeNeo4jClient();

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
    expect($nodeBatchCommands[0]['params']['nodes'])->toHaveCount(2);
    expect($nodeBatchCommands[0]['params']['nodes'][0]['properties'])->toMatchArray([
        'snapshot_id' => $context['snapshot_id'],
        'run_id' => $context['run_id'],
        'repository_id' => $context['repository_id'],
    ]);

    expect($relationshipBatchCommands)->toHaveCount(1);
    expect($relationshipBatchCommands[0]['params']['relationships'])->toHaveCount(1);
    expect($relationshipBatchCommands[0]['params']['relationships'][0]['properties'])->toMatchArray([
        'snapshot_id' => $context['snapshot_id'],
        'run_id' => $context['run_id'],
        'repository_id' => $context['repository_id'],
    ]);
});

it('marks the import failed when Neo4j import fails', function () {
    $context = createGraphImportContext();

    expect(fn () => app(GenesisGraphImportService::class)->importGenesis($context['import_id'], new FailingNeo4jClient()))
        ->toThrow(RuntimeException::class);

    expect(DB::table('genesis_imports')->where('id', $context['import_id'])->value('status'))->toBe('failed');
    expect(DB::table('run_events')->where('run_id', $context['run_id'])->where('event_type', 'graph.imported')->exists())->toBeFalse();
});

it('keeps the import active while a queued graph retry is still pending', function () {
    $context = createGraphImportContext();

    expect(fn () => app(GenesisGraphImportService::class)->importGenesis(
        $context['import_id'],
        new FailingNeo4jClient(),
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
        'services.neo4j.auth' => ['neo4j', 'graphify-sandbox'],
    ]);

    expect(app(Neo4jClientFactory::class)->client())->toBeObject();
});

it('rebuilds a Neo4j projection from stored graph artifacts', function () {
    $context = createGraphImportContext();
    $client = new FakeNeo4jClient();

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
        fn (array $command): bool => str_contains($command['cypher'], 'DevBoardSnapshot'),
    ))->toBeTrue();
    expect(DB::table('run_events')
        ->where('run_id', $context['run_id'])
        ->where('event_type', 'graph.imported')
        ->where('message', 'Neo4j rebuild validated in fake mode.')
        ->exists())->toBeTrue();
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

it('does not purge an existing projection when the stored graph artifact is missing', function () {
    $context = createGraphImportContext();
    $client = new FakeNeo4jClient();
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

class FakeNeo4jClient
{
    public array $commands = [];

    public function run(string $cypher, array $params): void
    {
        $this->commands[] = ['cypher' => $cypher, 'params' => $params];
    }
}

class FailingNeo4jClient
{
    public function run(string $cypher, array $params): void
    {
        throw new RuntimeException('neo4j unavailable');
    }
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
            ['id' => 'function:health', 'labels' => ['Function'], 'properties' => ['name' => 'health']],
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
