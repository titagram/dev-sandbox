<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

beforeEach(function () {
    Storage::fake('local');
    $this->seed(\Database\Seeders\DevBoardSeeder::class);
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

it('stores uploaded chunks by import artifact and index', function () {
    $context = createGenesisUploadContext();
    $artifact = genesisArtifact('file_inventory', 'file-inventory.json', 'hello');
    $importId = genesisStart($context, genesisManifest([$artifact]))->json('import_id');

    genesisChunk($context, $importId, $artifact['artifact_id'], 0, 'hello', hash('sha256', 'hello'))
        ->assertOk()
        ->assertJsonPath('status', 'received');

    Storage::disk('local')->assertExists("devboard/artifacts/genesis/{$importId}/{$artifact['artifact_id']}/chunks/0");
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

/**
 * @return array<string, string>
 */
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

/**
 * @param list<array<string, mixed>> $artifacts
 * @return array<string, mixed>
 */
function genesisManifest(array $artifacts): array
{
    return [
        'protocol_version' => 'v1',
        'bundle_type' => 'genesis_import',
        'schema_version' => 'v1',
        'artifacts' => array_map(fn (array $artifact): array => Arr::except($artifact, ['content']), $artifacts),
    ];
}

/**
 * @return array<string, mixed>
 */
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

function genesisStart(array $context, array $manifest): Illuminate\Testing\TestResponse
{
    return test()->postJson("/api/plugin/v1/repositories/{$context['repository_id']}/genesis-imports", [
        'protocol_version' => 'v1',
        'run_id' => $context['run_id'],
        'local_workspace_id' => $context['local_workspace_id'],
        'manifest' => $manifest,
    ], genesisUploadHeaders($context));
}

function genesisChunk(
    array $context,
    ?string $importId,
    string $artifactId,
    int $index,
    string $content,
    string $hash,
): Illuminate\Testing\TestResponse {
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

function genesisFinalize(array $context, ?string $importId): Illuminate\Testing\TestResponse
{
    return test()->postJson("/api/plugin/v1/genesis-imports/{$importId}/finalize", [
        'protocol_version' => 'v1',
    ], genesisUploadHeaders($context));
}

/**
 * @return array<string, string>
 */
function genesisUploadHeaders(array $context): array
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
function genesisUploadRawHeaders(array $context): array
{
    return [
        'HTTP_AUTHORIZATION' => 'Bearer '.$context['token'],
        'HTTP_X_DEVBOARD_PROTOCOL' => 'v1',
        'HTTP_X_DEVBOARD_PLUGIN_VERSION' => '0.1.0',
        'HTTP_X_DEVBOARD_DEVICE_ID' => $context['device_id'],
    ];
}
