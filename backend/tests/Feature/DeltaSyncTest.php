<?php

use App\Jobs\ImportGraphToNeo4j;
use App\Services\GenesisGraphImportService;
use App\Services\Neo4j\FakeNeo4jClient;
use Database\Seeders\DevBoardSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;

uses(RefreshDatabase::class);

beforeEach(function () {
    Storage::fake('local');
    config(['services.devboard.graph_import_mode' => 'fake']);
    $this->seed(DevBoardSeeder::class);
});

it('records local snapshot metadata before delta sync', function () {
    $context = createDeltaContext();

    deltaLocalSnapshot($context, [
        'local_workspace_id' => $context['local_workspace_id'],
        'branch' => 'feature/devboard',
        'head_sha' => 'def456',
        'dirty_status' => 'dirty',
        'changed_files' => [['path' => 'app.py', 'change_type' => 'modified']],
    ])
        ->assertOk()
        ->assertJsonPath('status', 'local_snapshot_received');

    expect(DB::table('local_workspaces')->where('id', $context['local_workspace_id'])->value('last_head_sha'))->toBe('def456');
    expect(DB::table('run_events')->where('run_id', $context['run_id'])->where('event_type', 'local_snapshot.received')->exists())->toBeTrue();
});

it('starts delta sync and creates artifact rows', function () {
    $context = createDeltaContext();
    $manifest = deltaManifest([
        deltaArtifact('diff_summary', 'diff-summary.json', '{"changed_file_count":1}'),
        deltaArtifact('security_report', 'security-report.json', '{"blocked":[]}'),
    ]);

    $response = deltaStart($context, $manifest);

    $response
        ->assertOk()
        ->assertJsonPath('status', 'uploading')
        ->assertJsonStructure(['delta_id', 'artifacts']);

    expect(DB::table('delta_syncs')->where('id', $response->json('delta_id'))->value('status'))->toBe('uploading');
    expect(DB::table('delta_syncs')->where('id', $response->json('delta_id'))->value('base_snapshot_id'))->toBe($context['base_snapshot_id']);
    expect(DB::table('artifacts')->where('run_id', $context['run_id'])->count())->toBe(2);
});

it('rejects local snapshot and Delta start with cross-workspace or snapshot references', function () {
    $context = createDeltaContext();
    $otherWorkspaceId = (string) Str::ulid();
    $now = now();
    DB::table('local_workspaces')->insert([
        'id' => $otherWorkspaceId,
        'repository_id' => $context['repository_id'],
        'device_id' => $context['device_id'],
        'local_root_hash' => 'sha256:other-delta-workspace',
        'display_path' => '/tmp/other-delta-workspace',
        'current_branch' => 'main',
        'last_head_sha' => 'abc123',
        'dirty_status' => 'clean',
        'last_snapshot_id' => null,
        'last_seen_at' => $now,
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    deltaLocalSnapshot($context, [
        'local_workspace_id' => $otherWorkspaceId,
        'branch' => 'main',
        'head_sha' => 'abc123',
        'dirty_status' => 'clean',
    ])->assertUnprocessable()->assertJsonPath('error.code', 'schema_validation_failed');

    DB::table('snapshots')->where('id', $context['base_snapshot_id'])->update([
        'local_workspace_id' => $otherWorkspaceId,
    ]);
    deltaStart($context, deltaManifest([
        deltaArtifact('diff_summary', 'diff-summary.json', '{"changed_file_count":1}'),
    ]))->assertUnprocessable()->assertJsonPath('error.code', 'schema_validation_failed');
});

it('rejects a Delta artifact uploaded through another sync URL', function () {
    $context = createDeltaContext();
    $firstArtifact = deltaArtifact('file_hashes', 'first.json', 'first');
    $secondArtifact = deltaArtifact('file_hashes', 'second.json', 'second');
    deltaStart($context, deltaManifest([$firstArtifact]))->assertOk();
    $secondDeltaId = deltaStart($context, deltaManifest([$secondArtifact]))->json('delta_id');

    deltaChunk(
        $context,
        $secondDeltaId,
        $firstArtifact['artifact_id'],
        0,
        $firstArtifact['content'],
        hash('sha256', $firstArtifact['content']),
    )
        ->assertUnprocessable()
        ->assertJsonPath('error.code', 'schema_validation_failed');
});

it('allows duplicate uploads of the same Delta chunk hash', function () {
    $context = createDeltaContext();
    $artifact = deltaArtifact('file_hashes', 'file-hashes.json', 'hello');
    $deltaId = deltaStart($context, deltaManifest([$artifact]))->json('delta_id');

    deltaChunk($context, $deltaId, $artifact['artifact_id'], 0, 'hello', hash('sha256', 'hello'))->assertOk();
    deltaChunk($context, $deltaId, $artifact['artifact_id'], 0, 'hello', hash('sha256', 'hello'))
        ->assertOk()
        ->assertJsonPath('status', 'received');
});

it('rejects a negative Delta chunk index with artifact_chunk_out_of_range and no file', function () {
    $context = createDeltaContext();
    $artifact = deltaArtifact('file_hashes', 'file-hashes.json', 'hello', 1);
    $deltaId = deltaStart($context, deltaManifest([$artifact]))->json('delta_id');

    deltaChunk($context, $deltaId, $artifact['artifact_id'], -1, 'evil', hash('sha256', 'evil'))
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'artifact_chunk_out_of_range');

    Storage::disk('local')->assertMissing(
        "devboard/artifacts/delta/{$deltaId}/{$artifact['artifact_id']}/chunks/-1"
    );
});

it('rejects a Delta chunk index equal to chunk_count with artifact_chunk_out_of_range and no file', function () {
    $context = createDeltaContext();
    $artifact = deltaArtifact('file_hashes', 'file-hashes.json', 'hello', 1);
    $deltaId = deltaStart($context, deltaManifest([$artifact]))->json('delta_id');

    deltaChunk($context, $deltaId, $artifact['artifact_id'], 1, 'evil', hash('sha256', 'evil'))
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'artifact_chunk_out_of_range');

    Storage::disk('local')->assertMissing(
        "devboard/artifacts/delta/{$deltaId}/{$artifact['artifact_id']}/chunks/1"
    );
});

it('rejects a Delta chunk index far above chunk_count with artifact_chunk_out_of_range and no file', function () {
    $context = createDeltaContext();
    $artifact = deltaArtifact('file_hashes', 'file-hashes.json', 'hello', 1);
    $deltaId = deltaStart($context, deltaManifest([$artifact]))->json('delta_id');

    deltaChunk($context, $deltaId, $artifact['artifact_id'], 999, 'evil', hash('sha256', 'evil'))
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'artifact_chunk_out_of_range');

    Storage::disk('local')->assertMissing(
        "devboard/artifacts/delta/{$deltaId}/{$artifact['artifact_id']}/chunks/999"
    );
});

it('rejects a Delta chunk whose bytes would exceed declared size_bytes', function () {
    $context = createDeltaContext();
    $artifact = deltaArtifact('file_hashes', 'file-hashes.json', 'abcde', 2);
    $artifact['size_bytes'] = 5;
    $deltaId = deltaStart($context, deltaManifest([$artifact]))->json('delta_id');

    deltaChunk($context, $deltaId, $artifact['artifact_id'], 0, 'abc', hash('sha256', 'abc'))->assertOk();

    deltaChunk($context, $deltaId, $artifact['artifact_id'], 1, 'def', hash('sha256', 'def'))
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'artifact_size_mismatch');

    Storage::disk('local')->assertMissing(
        "devboard/artifacts/delta/{$deltaId}/{$artifact['artifact_id']}/chunks/1"
    );
});

it('rejects Delta finalize when assembled size does not match declared size_bytes and deletes the partial file', function () {
    $context = createDeltaContext();
    $content = 'hello';
    $artifact = deltaArtifact('file_hashes', 'file-hashes.json', $content, 1);
    $artifact['size_bytes'] = 10;
    $deltaId = deltaStart($context, deltaManifest([$artifact]))->json('delta_id');

    deltaChunk($context, $deltaId, $artifact['artifact_id'], 0, $content, hash('sha256', $content))->assertOk();

    $storagePath = DB::table('artifacts')->where('id', $artifact['artifact_id'])->value('storage_path');

    deltaFinalize($context, $deltaId)
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'artifact_size_mismatch');

    Storage::disk('local')->assertMissing($storagePath);
});

it('finalizes a valid delta bundle and creates a new snapshot', function () {
    Queue::fake();

    $context = createDeltaContext();
    $artifacts = [
        deltaArtifact('delta_manifest', 'delta-manifest.json', '{"protocol_version":"v1"}'),
        deltaArtifact('file_hashes', 'file-hashes.json', '{"hashes":[]}'),
        deltaArtifact('diff_summary', 'diff-summary.json', '{"changed_file_count":1}'),
        deltaArtifact('graph_snapshot', 'graph-snapshot.json', '{"nodes":[],"relationships":[]}'),
        deltaArtifact('security_report', 'security-report.json', '{"blocked":[]}'),
        deltaArtifact('wiki_pages', 'wiki-pages.json', '{"pages":[]}'),
    ];
    $deltaId = deltaStart($context, deltaManifest($artifacts))->json('delta_id');

    foreach ($artifacts as $artifact) {
        deltaChunk($context, $deltaId, $artifact['artifact_id'], 0, $artifact['content'], hash('sha256', $artifact['content']))->assertOk();
    }

    deltaFinalize($context, $deltaId)
        ->assertOk()
        ->assertJsonPath('status', 'active')
        ->assertJsonStructure(['snapshot_id']);

    Queue::assertPushed(ImportGraphToNeo4j::class, function (ImportGraphToNeo4j $job) use ($deltaId): bool {
        return $job->scope === 'delta' && $job->importOrDeltaId === $deltaId;
    });

    $job = new ImportGraphToNeo4j('delta', $deltaId);
    $job->handle(app(GenesisGraphImportService::class));

    $newSnapshotId = DB::table('delta_syncs')->where('id', $deltaId)->value('new_snapshot_id');

    expect($newSnapshotId)->not->toBeNull();
    expect($newSnapshotId)->not->toBe($context['base_snapshot_id']);
    expect(DB::table('delta_syncs')->where('id', $deltaId)->value('status'))->toBe('active');
    expect(DB::table('local_workspaces')->where('id', $context['local_workspace_id'])->value('last_snapshot_id'))->toBe($newSnapshotId);
    expect(DB::table('run_events')->where('run_id', $context['run_id'])->where('event_type', 'delta.finalized')->exists())->toBeTrue();
    expect(DB::table('run_events')->where('run_id', $context['run_id'])->where('event_type', 'graph.imported')->exists())->toBeTrue();
});

it('completes the actual fake ImportGraphToNeo4j path after adjacency verification', function (): void {
    Queue::fake();
    $context = createDeltaContext();
    $artifacts = [
        deltaArtifact('diff_summary', 'diff-summary.json', '{"changed_file_count":0}'),
        deltaArtifact('graph_snapshot', 'graph-snapshot.json', '{"nodes":[],"relationships":[]}'),
        deltaArtifact('security_report', 'security-report.json', '{"blocked":[]}'),
    ];
    $deltaId = deltaStart($context, deltaManifest($artifacts))->json('delta_id');

    foreach ($artifacts as $artifact) {
        deltaChunk($context, $deltaId, $artifact['artifact_id'], 0, $artifact['content'], hash('sha256', $artifact['content']))->assertOk();
    }

    deltaFinalize($context, $deltaId)->assertOk();
    (new ImportGraphToNeo4j('delta', $deltaId))->handle(app(GenesisGraphImportService::class));

    expect(DB::table('canonical_graph_projections')->where('status', 'ready')->exists())->toBeTrue()
        ->and(DB::table('run_events')->where('run_id', $context['run_id'])->where('event_type', 'graph.imported')->exists())->toBeTrue();
});

it('returns the existing Delta result on sequential finalize without duplicate side effects', function () {
    config(['queue.default' => 'database']);
    Queue::fake();
    $context = createDeltaContext();
    $artifacts = [
        deltaArtifact('file_hashes', 'file-hashes.json', '{"hashes":[]}'),
        deltaArtifact('graph_snapshot', 'graph-snapshot.json', '{"nodes":[],"relationships":[]}'),
        deltaArtifact('security_report', 'security-report.json', '{"blocked":[]}'),
    ];
    $deltaId = deltaStart($context, deltaManifest($artifacts))->json('delta_id');

    foreach ($artifacts as $artifact) {
        deltaChunk($context, $deltaId, $artifact['artifact_id'], 0, $artifact['content'], hash('sha256', $artifact['content']))->assertOk();
    }

    $first = deltaFinalize($context, $deltaId)->assertOk()->json();
    $second = deltaFinalize($context, $deltaId)->assertOk()->json();

    expect($second['snapshot_id'])->toBe($first['snapshot_id']);
    expect(DB::table('snapshots')->where('created_by_run_id', $context['run_id'])->count())->toBe(2);
    expect(DB::table('run_events')->where('run_id', $context['run_id'])->where('event_type', 'delta.finalized')->count())->toBe(1);
    expect(DB::table('audit_logs')->where('action', 'delta.finalized')->where('target_id', $deltaId)->count())->toBe(1);
    Queue::assertPushed(ImportGraphToNeo4j::class, 1);
});

it('inserts the database queue job atomically on first finalize and not on retry', function () {
    config([
        'queue.default' => 'database',
        'queue.connections.database.connection' => null,
    ]);
    $context = createDeltaContext();
    $artifact = deltaArtifact('graph_snapshot', 'graph-snapshot.json', '{"nodes":[],"relationships":[]}');
    $deltaId = deltaStart($context, deltaManifest([$artifact]))->json('delta_id');
    deltaChunk($context, $deltaId, $artifact['artifact_id'], 0, $artifact['content'], hash('sha256', $artifact['content']))->assertOk();

    deltaFinalize($context, $deltaId)->assertOk();

    expect(DB::table('jobs')->count())->toBe(1);
    expect(json_decode(DB::table('jobs')->value('payload'), true, 512, JSON_THROW_ON_ERROR)['displayName'])
        ->toBe(ImportGraphToNeo4j::class);
    expect(DB::table('audit_logs')
        ->where('action', 'graph.import_dispatched')
        ->where('target_type', 'delta_sync')
        ->where('target_id', $deltaId)
        ->count())->toBe(1);

    deltaFinalize($context, $deltaId)->assertOk();

    expect(DB::table('jobs')->count())->toBe(1);
    expect(DB::table('audit_logs')
        ->where('action', 'graph.import_dispatched')
        ->where('target_type', 'delta_sync')
        ->where('target_id', $deltaId)
        ->count())->toBe(1);
});

it('imports an affected subgraph by cloning the base snapshot and applying tombstone lists', function () {
    Queue::fake();
    $context = createDeltaContext();
    $baseArtifactId = (string) Str::ulid();
    $baseStoragePath = "devboard/artifacts/delta-base/{$baseArtifactId}/artifact";
    $baseGraph = json_encode([
        'nodes' => [
            ['id' => 'function:kept', 'labels' => ['Function'], 'properties' => ['name' => 'kept']],
            ['id' => 'function:removed', 'labels' => ['Function'], 'properties' => ['name' => 'removed']],
            ['id' => 'file:deleted.py', 'labels' => ['File'], 'properties' => ['path' => 'deleted.py']],
        ],
        'relationships' => [[
            'id' => 'rel:removed',
            'type' => 'CALLS',
            'source_id' => 'function:removed',
            'target_id' => 'function:kept',
            'properties' => [],
        ]],
    ], JSON_THROW_ON_ERROR);
    Storage::disk('local')->put($baseStoragePath, $baseGraph);
    DB::table('artifacts')->insert([
        'id' => $baseArtifactId,
        'project_id' => $context['project_id'],
        'repository_id' => $context['repository_id'],
        'run_id' => $context['run_id'],
        'artifact_type' => 'graph_snapshot',
        'storage_path' => $baseStoragePath,
        'sha256' => hash('sha256', $baseGraph),
        'size_bytes' => strlen($baseGraph),
        'mime_type' => 'application/json',
        'schema_version' => 'v1',
        'status' => 'imported',
        'producer' => 'devboard-python-plugin',
        'metadata' => '{}',
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    DB::table('snapshots')->where('id', $context['base_snapshot_id'])->update([
        'graph_snapshot_artifact_id' => $baseArtifactId,
    ]);
    $graph = json_encode([
        'graph_mode' => 'affected_subgraph',
        'base_snapshot_id' => $context['base_snapshot_id'],
        'nodes' => [[
            'id' => 'function:changed',
            'labels' => ['Function'],
            'properties' => ['name' => 'changed'],
        ]],
        'nodes_deleted' => [
            'function:removed',
            ['id' => 'file:deleted.py'],
        ],
        'relationships' => [[
            'id' => 'rel:new',
            'type' => 'CALLS',
            'source_id' => 'function:changed',
            'target_id' => 'function:kept',
            'properties' => [],
        ]],
        'relationships_deleted' => [
            ['external_id' => 'rel:removed'],
        ],
        'affected_file_paths' => ['changed.py'],
        'affected_symbol_ids' => ['function:changed'],
    ], JSON_THROW_ON_ERROR);
    $artifact = deltaArtifact('graph_snapshot', 'graph-snapshot.json', $graph);
    $deltaId = deltaStart($context, deltaManifest([$artifact]))->json('delta_id');
    deltaChunk($context, $deltaId, $artifact['artifact_id'], 0, $artifact['content'], hash('sha256', $artifact['content']))->assertOk();
    deltaFinalize($context, $deltaId)->assertOk();

    $client = new FakeNeo4jClient;
    app(GenesisGraphImportService::class)->importDelta($deltaId, $client, 'fake');

    $clone = collect($client->commands)->first(
        fn (array $command): bool => str_contains($command['cypher'], 'base_snapshot_id')
            && str_contains($command['cypher'], 'MERGE (copy:CodeNode'),
    );
    $relationshipDeletion = collect($client->commands)->first(
        fn (array $command): bool => str_contains($command['cypher'], 'relationship_ids'),
    );
    $nodeDeletion = collect($client->commands)->first(
        fn (array $command): bool => str_contains($command['cypher'], 'node_ids'),
    );
    $upsert = collect($client->commands)->first(
        fn (array $command): bool => str_contains($command['cypher'], 'UNWIND $nodes'),
    );

    expect($clone)->not->toBeNull();
    expect($clone['params']['base_snapshot_id'])->toBe($context['base_snapshot_id']);
    expect($relationshipDeletion['params']['relationship_ids'])->toBe(['rel:removed', 'rel:new']);
    expect($nodeDeletion['params']['node_ids'])->toBe(['function:removed', 'file:deleted.py']);
    expect($upsert['params']['nodes'][0]['id'])->toBe('function:changed');
});

it('rejects affected subgraph deletion counters before snapshot creation or dispatch', function () {
    Queue::fake();
    $context = createDeltaContext();
    $graph = json_encode([
        'graph_mode' => 'affected_subgraph',
        'base_snapshot_id' => $context['base_snapshot_id'],
        'nodes' => [],
        'nodes_deleted' => 2,
        'relationships' => [],
        'relationships_deleted' => 1,
    ], JSON_THROW_ON_ERROR);
    $artifact = deltaArtifact('graph_snapshot', 'graph-snapshot.json', $graph);
    $deltaId = deltaStart($context, deltaManifest([$artifact]))->json('delta_id');
    deltaChunk($context, $deltaId, $artifact['artifact_id'], 0, $artifact['content'], hash('sha256', $artifact['content']))->assertOk();

    deltaFinalize($context, $deltaId)
        ->assertUnprocessable()
        ->assertJsonPath('error.code', 'schema_validation_failed');

    expect(DB::table('delta_syncs')->where('id', $deltaId)->value('new_snapshot_id'))->toBeNull();
    Queue::assertNothingPushed();
});

it('finalizes a large multi-chunk Delta artifact after retrying a chunk upload', function () {
    $context = createDeltaContext();
    $largeContent = json_encode([
        'hashes' => array_map(
            fn (int $index): array => [
                'path' => "src/ChangedFile{$index}.php",
                'sha256' => hash('sha256', str_repeat("changed {$index}\n", 50)),
            ],
            range(1, 180),
        ),
    ], JSON_THROW_ON_ERROR);
    $chunks = str_split($largeContent, 1024);
    $fileHashes = deltaArtifact('file_hashes', 'file-hashes.json', $largeContent, count($chunks));
    $artifacts = [
        deltaArtifact('delta_manifest', 'delta-manifest.json', '{"protocol_version":"v1"}'),
        $fileHashes,
        deltaArtifact('diff_summary', 'diff-summary.json', '{"changed_file_count":180}'),
        deltaArtifact('graph_snapshot', 'graph-snapshot.json', '{"nodes":[],"relationships":[]}'),
        deltaArtifact('security_report', 'security-report.json', '{"blocked":[]}'),
    ];
    $deltaId = deltaStart($context, deltaManifest($artifacts))->json('delta_id');

    foreach ($artifacts as $artifact) {
        if ($artifact['artifact_id'] !== $fileHashes['artifact_id']) {
            deltaChunk($context, $deltaId, $artifact['artifact_id'], 0, $artifact['content'], hash('sha256', $artifact['content']))->assertOk();
        }
    }

    foreach ($chunks as $index => $chunk) {
        deltaChunk($context, $deltaId, $fileHashes['artifact_id'], $index, $chunk, hash('sha256', $chunk))->assertOk();

        if ($index === 2) {
            deltaChunk($context, $deltaId, $fileHashes['artifact_id'], $index, $chunk, hash('sha256', $chunk))->assertOk();
        }
    }

    deltaFinalize($context, $deltaId)
        ->assertOk()
        ->assertJsonPath('status', 'active');

    $storagePath = DB::table('artifacts')->where('id', $fileHashes['artifact_id'])->value('storage_path');

    expect(Storage::disk('local')->get($storagePath))->toBe($largeContent);
    expect(DB::table('delta_syncs')->where('id', $deltaId)->value('status'))->toBe('active');
});

it('blocks delta finalize when security report contains blocked findings', function () {
    $context = createDeltaContext();
    $artifacts = [
        deltaArtifact('diff_summary', 'diff-summary.json', '{"changed_file_count":1}'),
        deltaArtifact('security_report', 'security-report.json', '{"blocked":[{"path":".env"}]}'),
    ];
    $deltaId = deltaStart($context, deltaManifest($artifacts))->json('delta_id');

    foreach ($artifacts as $artifact) {
        deltaChunk($context, $deltaId, $artifact['artifact_id'], 0, $artifact['content'], hash('sha256', $artifact['content']))->assertOk();
    }

    deltaFinalize($context, $deltaId)
        ->assertForbidden()
        ->assertJsonPath('error.code', 'secret_scan_blocked');

    expect(DB::table('delta_syncs')->where('id', $deltaId)->value('status'))->toBe('failed');
});

it('allows delta finalize with blocked findings only when explicitly approved', function () {
    $context = createDeltaContext();
    $artifacts = [
        deltaArtifact('diff_summary', 'diff-summary.json', '{"changed_file_count":1}'),
        deltaArtifact('security_report', 'security-report.json', '{"blocked":[{"path":".env","reason":"env_file"}]}'),
    ];
    $deltaId = deltaStart($context, deltaManifest($artifacts))->json('delta_id');

    foreach ($artifacts as $artifact) {
        deltaChunk($context, $deltaId, $artifact['artifact_id'], 0, $artifact['content'], hash('sha256', $artifact['content']))->assertOk();
    }

    deltaFinalize($context, $deltaId, ['allow_blocked_security_findings' => true])
        ->assertOk()
        ->assertJsonPath('status', 'active')
        ->assertJsonStructure(['snapshot_id']);

    expect(DB::table('delta_syncs')->where('id', $deltaId)->value('status'))->toBe('active');
    expect(DB::table('run_events')
        ->where('run_id', $context['run_id'])
        ->where('event_type', 'security.blocked_upload_approved')
        ->exists())->toBeTrue();
    expect(DB::table('audit_logs')
        ->where('action', 'security.blocked_upload_approved')
        ->where('target_type', 'delta_sync')
        ->where('target_id', $deltaId)
        ->exists())->toBeTrue();
});

it('dispatches a graph import job instead of importing synchronously when finalizing a delta with a graph snapshot', function () {
    Queue::fake();

    $context = createDeltaContext();
    $artifacts = [
        deltaArtifact('delta_manifest', 'delta-manifest.json', '{"protocol_version":"v1"}'),
        deltaArtifact('file_hashes', 'file-hashes.json', '{"hashes":[]}'),
        deltaArtifact('diff_summary', 'diff-summary.json', '{"changed_file_count":1}'),
        deltaArtifact('graph_snapshot', 'graph-snapshot.json', '{"nodes":[],"relationships":[]}'),
        deltaArtifact('security_report', 'security-report.json', '{"blocked":[]}'),
        deltaArtifact('wiki_pages', 'wiki-pages.json', '{"pages":[]}'),
    ];
    $deltaId = deltaStart($context, deltaManifest($artifacts))->json('delta_id');

    foreach ($artifacts as $artifact) {
        deltaChunk($context, $deltaId, $artifact['artifact_id'], 0, $artifact['content'], hash('sha256', $artifact['content']))->assertOk();
    }

    deltaFinalize($context, $deltaId)
        ->assertOk()
        ->assertJsonPath('status', 'active')
        ->assertJsonStructure(['snapshot_id']);

    Queue::assertPushed(ImportGraphToNeo4j::class, function (ImportGraphToNeo4j $job) use ($deltaId): bool {
        return $job->scope === 'delta' && $job->importOrDeltaId === $deltaId;
    });

    expect(DB::table('run_events')
        ->where('run_id', $context['run_id'])
        ->where('event_type', 'graph.imported')
        ->exists())->toBeFalse();
});

/**
 * @return array<string, string>
 */
function createDeltaContext(): array
{
    $userId = DB::table('users')->where('email', 'admin@example.com')->value('id');
    $projectId = DB::table('projects')->where('slug', 'demo-project')->value('id');
    $repositoryId = DB::table('repositories')->where('slug', 'demo-repository')->value('id');
    $now = now();
    $deviceId = (string) Str::ulid();
    $tokenId = (string) Str::ulid();
    $workspaceId = (string) Str::ulid();
    $runId = (string) Str::ulid();
    $baseSnapshotId = (string) Str::ulid();
    $secret = 'delta-upload-secret';
    $prefix = 'devb_live_'.$tokenId;

    DB::table('devices')->insert([
        'id' => $deviceId,
        'user_id' => $userId,
        'name' => 'Delta Device',
        'fingerprint_hash' => 'sha256:delta-device',
        'platform_os' => 'darwin',
        'platform_arch' => 'arm64',
        'plugin_version' => '0.1.0',
        'last_seen_at' => $now,
        'status' => 'active',
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    DB::table('api_tokens')->insert([
        'id' => $tokenId,
        'token_prefix' => $prefix,
        'token_hash' => hash('sha256', $secret),
        'user_id' => $userId,
        'device_id' => $deviceId,
        'name' => 'Delta Token',
        'scopes' => json_encode(['repositories.read', 'runs.write', 'artifacts.write'], JSON_THROW_ON_ERROR),
        'expires_at' => now()->addMonth(),
        'revoked_at' => null,
        'last_used_at' => null,
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    DB::table('local_workspaces')->insert([
        'id' => $workspaceId,
        'repository_id' => $repositoryId,
        'device_id' => $deviceId,
        'local_root_hash' => 'sha256:delta-workspace',
        'display_path' => '/tmp/delta-workspace',
        'current_branch' => 'feature/devboard',
        'last_head_sha' => 'abc123',
        'dirty_status' => 'clean',
        'last_snapshot_id' => $baseSnapshotId,
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
        'branch' => 'feature/devboard',
        'base_branch' => 'main',
        'base_sha' => 'abc123',
        'head_sha' => 'def456',
        'summary' => null,
        'risk_level' => 'medium',
        'started_at' => $now,
        'finished_at' => null,
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    DB::table('snapshots')->insert([
        'id' => $baseSnapshotId,
        'project_id' => $projectId,
        'repository_id' => $repositoryId,
        'local_workspace_id' => $workspaceId,
        'source_type' => 'local_plugin_snapshot',
        'branch' => 'main',
        'base_sha' => 'abc123',
        'head_sha' => 'abc123',
        'dirty_status' => 'clean',
        'file_inventory_artifact_id' => null,
        'graph_snapshot_artifact_id' => null,
        'created_by_run_id' => $runId,
        'created_at' => $now,
    ]);

    return [
        'token' => $prefix.'|'.$secret,
        'device_id' => $deviceId,
        'project_id' => $projectId,
        'repository_id' => $repositoryId,
        'local_workspace_id' => $workspaceId,
        'run_id' => $runId,
        'base_snapshot_id' => $baseSnapshotId,
    ];
}

/**
 * @param  list<array<string, mixed>>  $artifacts
 * @return array<string, mixed>
 */
function deltaManifest(array $artifacts): array
{
    return [
        'protocol_version' => 'v1',
        'bundle_type' => 'delta_sync',
        'schema_version' => 'v1',
        'changed_file_count' => 1,
        'risk_report' => ['risk_level' => 'medium', 'triggers' => ['large_multi_file_diff']],
        'artifacts' => array_map(fn (array $artifact): array => Arr::except($artifact, ['content']), $artifacts),
    ];
}

/**
 * @return array<string, mixed>
 */
function deltaArtifact(string $type, string $filename, string $content, int $chunkCount = 1): array
{
    return [
        'protocol_version' => 'v1',
        'artifact_id' => (string) Str::ulid(),
        'artifact_type' => $type,
        'schema_version' => 'v1',
        'filename' => $filename,
        'mime_type' => 'application/json',
        'sha256' => hash('sha256', $content),
        'size_bytes' => strlen($content),
        'chunk_count' => $chunkCount,
        'producer' => 'devboard-python-plugin',
        'source_type' => 'local_analyzer',
        'source_status' => 'verified_from_code',
        'content' => $content,
    ];
}

function deltaLocalSnapshot(array $context, array $payload): TestResponse
{
    return test()->postJson("/api/plugin/v1/runs/{$context['run_id']}/local-snapshots", array_merge([
        'protocol_version' => 'v1',
    ], $payload), deltaHeaders($context));
}

function deltaStart(array $context, array $manifest): TestResponse
{
    return test()->postJson("/api/plugin/v1/runs/{$context['run_id']}/delta-syncs", [
        'protocol_version' => 'v1',
        'local_workspace_id' => $context['local_workspace_id'],
        'base_snapshot_id' => $context['base_snapshot_id'],
        'branch' => 'feature/devboard',
        'base_sha' => 'abc123',
        'head_sha' => 'def456',
        'dirty_status' => 'dirty',
        'manifest' => $manifest,
    ], deltaHeaders($context));
}

function deltaChunk(
    array $context,
    ?string $deltaId,
    string $artifactId,
    int $index,
    string $content,
    string $hash,
): TestResponse {
    return test()->call(
        'PUT',
        "/api/plugin/v1/delta-syncs/{$deltaId}/artifacts/{$artifactId}/chunks/{$index}",
        [],
        [],
        [],
        array_merge(deltaRawHeaders($context), [
            'HTTP_X_DEVBOARD_CHUNK_SHA256' => $hash,
            'HTTP_X_DEVBOARD_CHUNK_SIZE' => strlen($content),
            'CONTENT_TYPE' => 'application/octet-stream',
        ]),
        $content,
    );
}

function deltaFinalize(array $context, ?string $deltaId, array $payload = []): TestResponse
{
    return test()->postJson("/api/plugin/v1/delta-syncs/{$deltaId}/finalize", array_merge([
        'protocol_version' => 'v1',
    ], $payload), deltaHeaders($context));
}

/**
 * @return array<string, string>
 */
function deltaHeaders(array $context): array
{
    return [
        'Authorization' => 'Bearer '.$context['token'],
        'X-DevBoard-Protocol' => 'v1',
        'X-DevBoard-Plugin-Version' => '0.1.0',
        'X-DevBoard-Device-Id' => $context['device_id'],
    ];
}

/**
 * @return array<string, string>
 */
function deltaRawHeaders(array $context): array
{
    return [
        'HTTP_AUTHORIZATION' => 'Bearer '.$context['token'],
        'HTTP_ACCEPT' => 'application/json',
        'HTTP_X_DEVBOARD_PROTOCOL' => 'v1',
        'HTTP_X_DEVBOARD_PLUGIN_VERSION' => '0.1.0',
        'HTTP_X_DEVBOARD_DEVICE_ID' => $context['device_id'],
    ];
}
