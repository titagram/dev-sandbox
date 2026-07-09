<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

beforeEach(function () {
    Storage::fake('local');
    config([
        'queue.default' => 'sync',
        'services.devboard.graph_import_mode' => 'fake',
    ]);
    $this->seed(\Database\Seeders\DevBoardSeeder::class);
});

it('rejects genesis manifests whose size_bytes exceeds max_artifact_bytes', function () {
    config(['devboard.artifacts.max_artifact_bytes' => 100]);
    $context = createLimitsGenesisContext();
    $artifact = limitsGenesisArtifact('file_inventory', 'file-inventory.json', str_repeat('x', 101));
    $artifact['size_bytes'] = 101;

    limitsGenesisStart($context, limitsGenesisManifest([$artifact]))
        ->assertStatus(422);
});

it('rejects genesis manifests whose chunk_count exceeds max_chunks', function () {
    config(['devboard.artifacts.max_chunks' => 5]);
    $context = createLimitsGenesisContext();
    $artifact = limitsGenesisArtifact('file_inventory', 'file-inventory.json', 'hello', 6);

    limitsGenesisStart($context, limitsGenesisManifest([$artifact]))
        ->assertStatus(422);
});

it('rejects chunk bodies larger than max_chunk_bytes with 413', function () {
    config(['devboard.artifacts.max_chunk_bytes' => 5]);
    $context = createLimitsGenesisContext();
    $content = 'toolarge';
    $artifact = limitsGenesisArtifact('file_inventory', 'file-inventory.json', $content);
    $importId = limitsGenesisStart($context, limitsGenesisManifest([$artifact]))->json('import_id');

    limitsGenesisChunk($context, $importId, $artifact['artifact_id'], 0, $content, hash('sha256', $content))
        ->assertStatus(413)
        ->assertJsonPath('error.code', 'artifact_chunk_too_large');
});

it('assembles chunks without concatenating the full artifact in a PHP string', function () {
    $context = createLimitsGenesisContext();
    $chunk1 = 'first chunk ';
    $chunk2 = 'second chunk';
    $full = $chunk1.$chunk2;
    $artifact = limitsGenesisArtifact('file_inventory', 'file-inventory.json', $full, 2);
    $importId = limitsGenesisStart($context, limitsGenesisManifest([$artifact]))->json('import_id');

    limitsGenesisChunk($context, $importId, $artifact['artifact_id'], 0, $chunk1, hash('sha256', $chunk1))->assertOk();
    limitsGenesisChunk($context, $importId, $artifact['artifact_id'], 1, $chunk2, hash('sha256', $chunk2))->assertOk();

    limitsGenesisFinalize($context, $importId)
        ->assertOk()
        ->assertJsonPath('status', 'active');

    $storagePath = DB::table('artifacts')->where('id', $artifact['artifact_id'])->value('storage_path');
    $assembled = Storage::disk('local')->get($storagePath);

    expect($assembled)->toBe($full);
    expect(hash('sha256', $assembled))->toBe($artifact['sha256']);
});

function createLimitsGenesisContext(): array
{
    $userId = DB::table('users')->where('email', 'admin@example.com')->value('id');
    $projectId = DB::table('projects')->where('slug', 'demo-project')->value('id');
    $repositoryId = DB::table('repositories')->where('slug', 'demo-repository')->value('id');
    $now = now();
    $deviceId = (string) Str::ulid();
    $tokenId = (string) Str::ulid();
    $workspaceId = (string) Str::ulid();
    $runId = (string) Str::ulid();
    $secret = 'limits-genesis-secret';
    $prefix = 'devb_live_'.$tokenId;

    DB::table('devices')->insert([
        'id' => $deviceId,
        'user_id' => $userId,
        'name' => 'Limits Genesis Device',
        'fingerprint_hash' => 'sha256:limits-genesis',
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
        'name' => 'Limits Genesis Token',
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
        'local_root_hash' => 'sha256:limits-genesis-workspace',
        'display_path' => '/tmp/limits-genesis',
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

function limitsGenesisManifest(array $artifacts): array
{
    return [
        'protocol_version' => 'v1',
        'bundle_type' => 'genesis_import',
        'schema_version' => 'v1',
        'artifacts' => array_map(fn (array $artifact): array => Arr::except($artifact, ['content']), $artifacts),
    ];
}

function limitsGenesisArtifact(string $type, string $filename, string $content, int $chunkCount = 1): array
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

function limitsGenesisStart(array $context, array $manifest): Illuminate\Testing\TestResponse
{
    return test()->postJson("/api/plugin/v1/repositories/{$context['repository_id']}/genesis-imports", [
        'protocol_version' => 'v1',
        'run_id' => $context['run_id'],
        'local_workspace_id' => $context['local_workspace_id'],
        'manifest' => $manifest,
    ], limitsGenesisHeaders($context));
}

function limitsGenesisChunk(
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
        array_merge(limitsGenesisRawHeaders($context), [
            'HTTP_X_DEVBOARD_CHUNK_SHA256' => $hash,
            'HTTP_X_DEVBOARD_CHUNK_SIZE' => strlen($content),
            'CONTENT_TYPE' => 'application/octet-stream',
        ]),
        $content,
    );
}

function limitsGenesisFinalize(array $context, ?string $importId, array $payload = []): Illuminate\Testing\TestResponse
{
    return test()->postJson("/api/plugin/v1/genesis-imports/{$importId}/finalize", array_merge([
        'protocol_version' => 'v1',
    ], $payload), limitsGenesisHeaders($context));
}

function limitsGenesisHeaders(array $context): array
{
    return [
        'Authorization' => 'Bearer '.$context['token'],
        'X-DevBoard-Protocol' => 'v1',
        'X-DevBoard-Plugin-Version' => '0.1.0',
        'X-DevBoard-Device-Id' => $context['device_id'],
    ];
}

function limitsGenesisRawHeaders(array $context): array
{
    return [
        'HTTP_AUTHORIZATION' => 'Bearer '.$context['token'],
        'HTTP_X_DEVBOARD_PROTOCOL' => 'v1',
        'HTTP_X_DEVBOARD_PLUGIN_VERSION' => '0.1.0',
        'HTTP_X_DEVBOARD_DEVICE_ID' => $context['device_id'],
    ];
}
