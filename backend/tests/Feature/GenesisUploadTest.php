<?php

use App\Jobs\ImportGraphToNeo4j;
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
    config([
        'queue.default' => 'sync',
        'services.devboard.graph_import_mode' => 'fake',
    ]);
    $this->seed(DevBoardSeeder::class);
});

it('starts Genesis import and creates artifact rows', function () {
    $context = createGenesisUploadContext();
    $manifest = genesisManifest([
        genesisArtifact('file_inventory', 'file-inventory.json', '{"files":[]}'),
        genesisArtifact('security_report', 'security-report.json', '{"blocked":[]}'),
    ]);

    $response = genesisStart($context, $manifest);

    $response
        ->assertOk()
        ->assertJsonPath('status', 'uploading')
        ->assertJsonStructure(['import_id', 'artifacts']);

    expect(DB::table('genesis_imports')->where('id', $response->json('import_id'))->value('status'))->toBe('uploading');
    expect(DB::table('artifacts')->where('run_id', $context['run_id'])->count())->toBe(2);
});

it('returns the existing Genesis import when retrying start with the same run manifest', function () {
    $context = createGenesisUploadContext();
    $manifest = genesisManifest([
        genesisArtifact('file_inventory', 'file-inventory.json', '{"files":[]}'),
        genesisArtifact('security_report', 'security-report.json', '{"blocked":[{"path":".env","reason":"env_file"}]}'),
    ]);

    $first = genesisStart($context, $manifest)
        ->assertOk()
        ->assertJsonPath('status', 'uploading');

    $second = genesisStart($context, $manifest)
        ->assertOk()
        ->assertJsonPath('status', 'uploading');

    expect($second->json('import_id'))->toBe($first->json('import_id'));
    expect(DB::table('genesis_imports')->where('run_id', $context['run_id'])->count())->toBe(1);
    expect(DB::table('artifacts')->where('run_id', $context['run_id'])->count())->toBe(2);
});

it('resets a failed Genesis import to uploading when retrying start with the same run manifest', function () {
    $context = createGenesisUploadContext();
    $manifest = genesisManifest([
        genesisArtifact('file_inventory', 'file-inventory.json', '{"files":[]}'),
        genesisArtifact('security_report', 'security-report.json', '{"blocked":[{"path":".env","reason":"env_file"}]}'),
    ]);
    $importId = genesisStart($context, $manifest)->json('import_id');

    DB::table('genesis_imports')->where('id', $importId)->update([
        'status' => 'failed',
        'finished_at' => now(),
        'updated_at' => now(),
    ]);

    genesisStart($context, $manifest)
        ->assertOk()
        ->assertJsonPath('import_id', $importId)
        ->assertJsonPath('status', 'uploading');

    $import = DB::table('genesis_imports')->where('id', $importId)->first();

    expect($import->status)->toBe('uploading');
    expect($import->finished_at)->toBeNull();
    expect(DB::table('genesis_imports')->where('run_id', $context['run_id'])->count())->toBe(1);
    expect(DB::table('artifacts')->where('run_id', $context['run_id'])->count())->toBe(2);
});

it('stores uploaded chunks by import artifact and index', function () {
    $context = createGenesisUploadContext();
    $artifact = genesisArtifact('file_inventory', 'file-inventory.json', 'hello');
    $importId = genesisStart($context, genesisManifest([$artifact]))->json('import_id');

    genesisChunk($context, $importId, $artifact['artifact_id'], 0, 'hello', hash('sha256', 'hello'))
        ->assertOk()
        ->assertJsonPath('status', 'received');

    Storage::disk('local')->assertExists("devboard/artifacts/genesis/{$importId}/{$artifact['artifact_id']}/chunks/0");
});

it('rejects a Genesis artifact uploaded through another import URL', function () {
    $context = createGenesisUploadContext();
    $firstArtifact = genesisArtifact('file_inventory', 'first.json', 'first');
    $secondArtifact = genesisArtifact('file_inventory', 'second.json', 'second');
    genesisStart($context, genesisManifest([$firstArtifact]))->assertOk();
    $secondImportId = genesisStart($context, genesisManifest([$secondArtifact]))->json('import_id');

    genesisChunk(
        $context,
        $secondImportId,
        $firstArtifact['artifact_id'],
        0,
        $firstArtifact['content'],
        hash('sha256', $firstArtifact['content']),
    )
        ->assertUnprocessable()
        ->assertJsonPath('error.code', 'schema_validation_failed');
});

it('rejects Genesis start when workspace and run do not belong to the route repository', function () {
    $context = createGenesisUploadContext();
    $otherRepositoryId = (string) Str::ulid();
    $now = now();

    DB::table('repositories')->insert([
        'id' => $otherRepositoryId,
        'project_id' => $context['project_id'],
        'name' => 'Other Genesis Repository',
        'slug' => 'other-genesis-repository',
        'default_branch' => 'main',
        'local_only' => true,
        'code_exposure_policy' => 'full_code_artifacts',
        'protected_paths' => '[]',
        'excluded_paths' => '[]',
        'stack_hints' => '[]',
        'graph_enabled' => true,
        'created_at' => $now,
        'updated_at' => $now,
    ]);
    DB::table('local_workspaces')->where('id', $context['local_workspace_id'])->update([
        'repository_id' => $otherRepositoryId,
        'updated_at' => $now,
    ]);

    genesisStart($context, genesisManifest([
        genesisArtifact('file_inventory', 'file-inventory.json', '{"files":[]}'),
    ]))
        ->assertUnprocessable()
        ->assertJsonPath('error.code', 'schema_validation_failed');
});

it('allows duplicate uploads of the same chunk hash', function () {
    $context = createGenesisUploadContext();
    $artifact = genesisArtifact('file_inventory', 'file-inventory.json', 'hello');
    $importId = genesisStart($context, genesisManifest([$artifact]))->json('import_id');

    genesisChunk($context, $importId, $artifact['artifact_id'], 0, 'hello', hash('sha256', 'hello'))->assertOk();
    genesisChunk($context, $importId, $artifact['artifact_id'], 0, 'hello', hash('sha256', 'hello'))
        ->assertOk()
        ->assertJsonPath('status', 'received');
});

it('rejects a negative Genesis chunk index with artifact_chunk_out_of_range and no file', function () {
    $context = createGenesisUploadContext();
    $artifact = genesisArtifact('file_inventory', 'file-inventory.json', 'hello', 1);
    $importId = genesisStart($context, genesisManifest([$artifact]))->json('import_id');

    genesisChunk($context, $importId, $artifact['artifact_id'], -1, 'evil', hash('sha256', 'evil'))
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'artifact_chunk_out_of_range');

    Storage::disk('local')->assertMissing(
        "devboard/artifacts/genesis/{$importId}/{$artifact['artifact_id']}/chunks/-1"
    );
});

it('rejects a Genesis chunk index equal to chunk_count with artifact_chunk_out_of_range and no file', function () {
    $context = createGenesisUploadContext();
    $artifact = genesisArtifact('file_inventory', 'file-inventory.json', 'hello', 1);
    $importId = genesisStart($context, genesisManifest([$artifact]))->json('import_id');

    genesisChunk($context, $importId, $artifact['artifact_id'], 1, 'evil', hash('sha256', 'evil'))
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'artifact_chunk_out_of_range');

    Storage::disk('local')->assertMissing(
        "devboard/artifacts/genesis/{$importId}/{$artifact['artifact_id']}/chunks/1"
    );
});

it('rejects a Genesis chunk index far above chunk_count with artifact_chunk_out_of_range and no file', function () {
    $context = createGenesisUploadContext();
    $artifact = genesisArtifact('file_inventory', 'file-inventory.json', 'hello', 1);
    $importId = genesisStart($context, genesisManifest([$artifact]))->json('import_id');

    genesisChunk($context, $importId, $artifact['artifact_id'], 999, 'evil', hash('sha256', 'evil'))
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'artifact_chunk_out_of_range');

    Storage::disk('local')->assertMissing(
        "devboard/artifacts/genesis/{$importId}/{$artifact['artifact_id']}/chunks/999"
    );
});

it('rejects a Genesis chunk whose bytes would exceed declared size_bytes', function () {
    $context = createGenesisUploadContext();
    $artifact = genesisArtifact('file_inventory', 'file-inventory.json', 'abcde', 2);
    $artifact['size_bytes'] = 5;
    $importId = genesisStart($context, genesisManifest([$artifact]))->json('import_id');

    genesisChunk($context, $importId, $artifact['artifact_id'], 0, 'abc', hash('sha256', 'abc'))->assertOk();

    genesisChunk($context, $importId, $artifact['artifact_id'], 1, 'def', hash('sha256', 'def'))
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'artifact_size_mismatch');

    Storage::disk('local')->assertMissing(
        "devboard/artifacts/genesis/{$importId}/{$artifact['artifact_id']}/chunks/1"
    );
});

it('rejects Genesis finalize when assembled size does not match declared size_bytes and deletes the partial file', function () {
    $context = createGenesisUploadContext();
    $content = 'hello';
    $artifact = genesisArtifact('file_inventory', 'file-inventory.json', $content, 1);
    $artifact['size_bytes'] = 10;
    $importId = genesisStart($context, genesisManifest([$artifact]))->json('import_id');

    genesisChunk($context, $importId, $artifact['artifact_id'], 0, $content, hash('sha256', $content))->assertOk();

    $storagePath = DB::table('artifacts')->where('id', $artifact['artifact_id'])->value('storage_path');

    genesisFinalize($context, $importId)
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'artifact_size_mismatch');

    Storage::disk('local')->assertMissing($storagePath);
});

it('finalizes a large multi-chunk Genesis artifact after retrying a chunk upload', function () {
    $context = createGenesisUploadContext();
    $largeContent = json_encode([
        'files' => array_map(
            fn (int $index): array => [
                'path' => "src/LargeFile{$index}.php",
                'sha256' => hash('sha256', str_repeat("line {$index}\n", 40)),
                'size_bytes' => 4096 + $index,
            ],
            range(1, 180),
        ),
    ], JSON_THROW_ON_ERROR);
    $chunks = str_split($largeContent, 1024);
    $largeArtifact = genesisArtifact('file_inventory', 'file-inventory.json', $largeContent, count($chunks));
    $securityReport = genesisArtifact('security_report', 'security-report.json', '{"blocked":[]}');
    $importId = genesisStart($context, genesisManifest([$largeArtifact, $securityReport]))->json('import_id');

    foreach ($chunks as $index => $chunk) {
        genesisChunk($context, $importId, $largeArtifact['artifact_id'], $index, $chunk, hash('sha256', $chunk))->assertOk();

        if ($index === 2) {
            genesisChunk($context, $importId, $largeArtifact['artifact_id'], $index, $chunk, hash('sha256', $chunk))->assertOk();
        }
    }

    genesisChunk($context, $importId, $securityReport['artifact_id'], 0, $securityReport['content'], hash('sha256', $securityReport['content']))->assertOk();

    genesisFinalize($context, $importId)
        ->assertOk()
        ->assertJsonPath('status', 'active');

    $storagePath = DB::table('artifacts')->where('id', $largeArtifact['artifact_id'])->value('storage_path');

    expect(Storage::disk('local')->get($storagePath))->toBe($largeContent);
    expect(DB::table('genesis_imports')->where('id', $importId)->value('status'))->toBe('active');
});

it('rejects duplicate uploads of the same chunk index with a different hash', function () {
    $context = createGenesisUploadContext();
    $artifact = genesisArtifact('file_inventory', 'file-inventory.json', 'hello');
    $importId = genesisStart($context, genesisManifest([$artifact]))->json('import_id');

    genesisChunk($context, $importId, $artifact['artifact_id'], 0, 'hello', hash('sha256', 'hello'))->assertOk();
    genesisChunk($context, $importId, $artifact['artifact_id'], 0, 'changed', hash('sha256', 'changed'))
        ->assertConflict()
        ->assertJsonPath('error.code', 'artifact_finalize_conflict');
});

it('rejects finalize when a chunk is missing', function () {
    $context = createGenesisUploadContext();
    $content = 'hello world';
    $artifact = genesisArtifact('file_inventory', 'file-inventory.json', $content, 2);
    $importId = genesisStart($context, genesisManifest([$artifact]))->json('import_id');

    genesisChunk($context, $importId, $artifact['artifact_id'], 0, 'hello ', hash('sha256', 'hello '))->assertOk();

    genesisFinalize($context, $importId)
        ->assertUnprocessable()
        ->assertJsonPath('error.code', 'artifact_chunk_missing');
});

it('rejects finalize when the artifact hash mismatches', function () {
    $context = createGenesisUploadContext();
    $artifact = genesisArtifact('file_inventory', 'file-inventory.json', 'hello');
    $artifact['sha256'] = str_repeat('0', 64);
    $importId = genesisStart($context, genesisManifest([$artifact]))->json('import_id');

    genesisChunk($context, $importId, $artifact['artifact_id'], 0, 'hello', hash('sha256', 'hello'))->assertOk();

    genesisFinalize($context, $importId)
        ->assertUnprocessable()
        ->assertJsonPath('error.code', 'artifact_hash_mismatch');
});

it('finalizes a valid bundle and creates an active snapshot', function () {
    $context = createGenesisUploadContext();
    $artifacts = [
        genesisArtifact('genesis_manifest', 'genesis-manifest.json', '{"protocol_version":"v1"}'),
        genesisArtifact('file_inventory', 'file-inventory.json', '{"files":[]}'),
        genesisArtifact('graph_snapshot', 'graph-snapshot.json', '{"nodes":[],"relationships":[]}'),
        genesisArtifact('security_report', 'security-report.json', '{"blocked":[]}'),
    ];
    $importId = genesisStart($context, genesisManifest($artifacts))->json('import_id');

    foreach ($artifacts as $artifact) {
        genesisChunk($context, $importId, $artifact['artifact_id'], 0, $artifact['content'], hash('sha256', $artifact['content']))->assertOk();
    }

    genesisFinalize($context, $importId)
        ->assertOk()
        ->assertJsonPath('status', 'active')
        ->assertJsonStructure(['snapshot_id']);

    expect(DB::table('genesis_imports')->where('id', $importId)->value('status'))->toBe('active');
    expect(DB::table('snapshots')->where('repository_id', $context['repository_id'])->exists())->toBeTrue();
    expect(DB::table('run_events')->where('run_id', $context['run_id'])->where('event_type', 'graph.imported')->exists())->toBeTrue();
});

it('returns the existing Genesis result on sequential finalize without duplicate side effects', function () {
    config(['queue.default' => 'database']);
    Queue::fake();
    $context = createGenesisUploadContext();
    $artifacts = [
        genesisArtifact('file_inventory', 'file-inventory.json', '{"files":[]}'),
        genesisArtifact('graph_snapshot', 'graph-snapshot.json', '{"nodes":[],"relationships":[]}'),
        genesisArtifact('security_report', 'security-report.json', '{"blocked":[]}'),
    ];
    $importId = genesisStart($context, genesisManifest($artifacts))->json('import_id');

    foreach ($artifacts as $artifact) {
        genesisChunk($context, $importId, $artifact['artifact_id'], 0, $artifact['content'], hash('sha256', $artifact['content']))->assertOk();
    }

    $first = genesisFinalize($context, $importId)->assertOk()->json();
    $second = genesisFinalize($context, $importId)->assertOk()->json();

    expect($second['snapshot_id'])->toBe($first['snapshot_id']);
    expect(DB::table('snapshots')->where('created_by_run_id', $context['run_id'])->count())->toBe(1);
    expect(DB::table('run_events')->where('run_id', $context['run_id'])->where('event_type', 'genesis.finalized')->count())->toBe(1);
    expect(DB::table('audit_logs')->where('action', 'genesis.finalized')->where('target_id', $importId)->count())->toBe(1);
    Queue::assertPushed(ImportGraphToNeo4j::class, 1);
});

it('retries a non-database graph dispatch when the durable marker is missing', function () {
    config(['queue.default' => 'sync']);
    Queue::fake();
    $context = createGenesisUploadContext();
    $artifact = genesisArtifact('graph_snapshot', 'graph-snapshot.json', '{"nodes":[],"relationships":[]}');
    $importId = genesisStart($context, genesisManifest([$artifact]))->json('import_id');
    genesisChunk($context, $importId, $artifact['artifact_id'], 0, $artifact['content'], hash('sha256', $artifact['content']))->assertOk();

    genesisFinalize($context, $importId)->assertOk();
    Queue::assertPushed(ImportGraphToNeo4j::class, 1);

    DB::table('audit_logs')
        ->where('action', 'graph.import_dispatched')
        ->where('target_type', 'genesis_import')
        ->where('target_id', $importId)
        ->delete();

    genesisFinalize($context, $importId)->assertOk();
    Queue::assertPushed(ImportGraphToNeo4j::class, 2);
});

it('blocks finalize when security report contains blocked findings', function () {
    $context = createGenesisUploadContext();
    $artifacts = [
        genesisArtifact('file_inventory', 'file-inventory.json', '{"files":[]}'),
        genesisArtifact('security_report', 'security-report.json', '{"blocked":[{"path":".env"}]}'),
    ];
    $importId = genesisStart($context, genesisManifest($artifacts))->json('import_id');

    foreach ($artifacts as $artifact) {
        genesisChunk($context, $importId, $artifact['artifact_id'], 0, $artifact['content'], hash('sha256', $artifact['content']))->assertOk();
    }

    genesisFinalize($context, $importId)
        ->assertForbidden()
        ->assertJsonPath('error.code', 'secret_scan_blocked');
});

it('allows finalize with blocked findings only when explicitly approved', function () {
    $context = createGenesisUploadContext();
    $artifacts = [
        genesisArtifact('file_inventory', 'file-inventory.json', '{"files":[]}'),
        genesisArtifact('security_report', 'security-report.json', '{"blocked":[{"path":".env","reason":"env_file"}]}'),
    ];
    $importId = genesisStart($context, genesisManifest($artifacts))->json('import_id');

    foreach ($artifacts as $artifact) {
        genesisChunk($context, $importId, $artifact['artifact_id'], 0, $artifact['content'], hash('sha256', $artifact['content']))->assertOk();
    }

    genesisFinalize($context, $importId, ['allow_blocked_security_findings' => true])
        ->assertOk()
        ->assertJsonPath('status', 'active')
        ->assertJsonStructure(['snapshot_id']);

    expect(DB::table('genesis_imports')->where('id', $importId)->value('status'))->toBe('active');
    expect(DB::table('run_events')
        ->where('run_id', $context['run_id'])
        ->where('event_type', 'security.blocked_upload_approved')
        ->exists())->toBeTrue();
    expect(DB::table('audit_logs')
        ->where('action', 'security.blocked_upload_approved')
        ->where('target_type', 'genesis_import')
        ->where('target_id', $importId)
        ->exists())->toBeTrue();
});

/**
 * @return array<string, string>
 */
if (! function_exists('createGenesisUploadContext')) {
    function createGenesisUploadContext(): array
    {
        $userId = DB::table('users')->where('email', 'admin@example.com')->value('id');
        $projectId = DB::table('projects')->where('slug', 'demo-project')->value('id');
        $repositoryId = DB::table('repositories')->where('slug', 'demo-repository')->value('id');
        $now = now();
        $deviceId = (string) Str::ulid();
        $tokenId = (string) Str::ulid();
        $workspaceId = (string) Str::ulid();
        $runId = (string) Str::ulid();
        $secret = 'genesis-upload-secret';
        $prefix = 'devb_live_'.$tokenId;

        DB::table('devices')->insert([
            'id' => $deviceId,
            'user_id' => $userId,
            'name' => 'Genesis Upload Device',
            'fingerprint_hash' => 'sha256:genesis-upload-device',
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
            'name' => 'Genesis Upload Token',
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
            'local_root_hash' => 'sha256:genesis-upload-workspace',
            'display_path' => '/tmp/genesis-upload-workspace',
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

        return [
            'token' => $prefix.'|'.$secret,
            'device_id' => $deviceId,
            'project_id' => $projectId,
            'repository_id' => $repositoryId,
            'local_workspace_id' => $workspaceId,
            'run_id' => $runId,
        ];
    }
}

/**
 * @param  list<array<string, mixed>>  $artifacts
 * @return array<string, mixed>
 */
if (! function_exists('genesisManifest')) {
    function genesisManifest(array $artifacts): array
    {
        return [
            'protocol_version' => 'v1',
            'bundle_type' => 'genesis_import',
            'schema_version' => 'v1',
            'artifacts' => array_map(fn (array $artifact): array => Arr::except($artifact, ['content']), $artifacts),
        ];
    }
}

/**
 * @return array<string, mixed>
 */
if (! function_exists('genesisArtifact')) {
    function genesisArtifact(string $type, string $filename, string $content, int $chunkCount = 1): array
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
}

if (! function_exists('genesisStart')) {
    function genesisStart(array $context, array $manifest): TestResponse
    {
        return test()->postJson("/api/plugin/v1/repositories/{$context['repository_id']}/genesis-imports", [
            'protocol_version' => 'v1',
            'run_id' => $context['run_id'],
            'local_workspace_id' => $context['local_workspace_id'],
            'manifest' => $manifest,
        ], genesisUploadHeaders($context));
    }
}

if (! function_exists('genesisChunk')) {
    function genesisChunk(
        array $context,
        ?string $importId,
        string $artifactId,
        int $index,
        string $content,
        string $hash,
    ): TestResponse {
        return test()->call(
            'PUT',
            "/api/plugin/v1/genesis-imports/{$importId}/artifacts/{$artifactId}/chunks/{$index}",
            [],
            [],
            [],
            array_merge(genesisUploadRawHeaders($context), [
                'HTTP_X_DEVBOARD_CHUNK_SHA256' => $hash,
                'HTTP_X_DEVBOARD_CHUNK_SIZE' => strlen($content),
                'CONTENT_TYPE' => 'application/octet-stream',
            ]),
            $content,
        );
    }
}

if (! function_exists('genesisFinalize')) {
    function genesisFinalize(array $context, ?string $importId, array $payload = []): TestResponse
    {
        return test()->postJson("/api/plugin/v1/genesis-imports/{$importId}/finalize", array_merge([
            'protocol_version' => 'v1',
        ], $payload), genesisUploadHeaders($context));
    }
}

/**
 * @return array<string, string>
 */
if (! function_exists('genesisUploadHeaders')) {
    function genesisUploadHeaders(array $context): array
    {
        return [
            'Authorization' => 'Bearer '.$context['token'],
            'X-DevBoard-Protocol' => 'v1',
            'X-DevBoard-Plugin-Version' => '0.1.0',
            'X-DevBoard-Device-Id' => $context['device_id'],
        ];
    }
}

/**
 * @return array<string, string>
 */
if (! function_exists('genesisUploadRawHeaders')) {
    function genesisUploadRawHeaders(array $context): array
    {
        return [
            'HTTP_AUTHORIZATION' => 'Bearer '.$context['token'],
            'HTTP_X_DEVBOARD_PROTOCOL' => 'v1',
            'HTTP_X_DEVBOARD_PLUGIN_VERSION' => '0.1.0',
            'HTTP_X_DEVBOARD_DEVICE_ID' => $context['device_id'],
        ];
    }
}
