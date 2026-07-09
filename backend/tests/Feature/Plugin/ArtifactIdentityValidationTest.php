<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
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
    $this->seed(\Database\Seeders\DevBoardSeeder::class);
});

it('rejects Genesis artifact ids that are not strict ULIDs', function () {
    $context = createUnsafeGenesisContext();

    genesisStartWith($context, validGenesisManifest())
        ->assertOk();

    genesisStartWith($context, genesisManifestWith(['artifact_id' => '../outside']))
        ->assertStatus(422);

    genesisStartWith($context, genesisManifestWith(['artifact_id' => '01J00000000000000000000000/evil']))
        ->assertStatus(422);

    genesisStartWith($context, genesisManifestWith(['artifact_id' => strtolower((string) Str::ulid())]))
        ->assertStatus(422);
});

it('rejects Delta artifact ids that are not strict ULIDs', function () {
    $context = createUnsafeDeltaContext();

    deltaStartWith($context, validDeltaManifest())
        ->assertOk();

    deltaStartWith($context, deltaManifestWith(['artifact_id' => '../outside']))
        ->assertStatus(422);

    deltaStartWith($context, deltaManifestWith(['artifact_id' => '01J00000000000000000000000/evil']))
        ->assertStatus(422);

    deltaStartWith($context, deltaManifestWith(['artifact_id' => strtolower((string) Str::ulid())]))
        ->assertStatus(422);
});

it('rejects Genesis with chunk_count of zero', function () {
    $context = createUnsafeGenesisContext();

    genesisStartWith($context, genesisManifestWith(['chunk_count' => 0]))
        ->assertStatus(422);
});

it('rejects Genesis with negative chunk_count', function () {
    $context = createUnsafeGenesisContext();

    genesisStartWith($context, genesisManifestWith(['chunk_count' => -1]))
        ->assertStatus(422);
});

it('rejects Genesis with chunk_count above max', function () {
    $context = createUnsafeGenesisContext();

    genesisStartWith($context, genesisManifestWith(['chunk_count' => (int) config('devboard.artifacts.max_chunks') + 1]))
        ->assertStatus(422);
});

it('rejects Genesis with negative size_bytes', function () {
    $context = createUnsafeGenesisContext();

    genesisStartWith($context, genesisManifestWith(['size_bytes' => -1]))
        ->assertStatus(422);
});

it('rejects Genesis with size_bytes above max', function () {
    $context = createUnsafeGenesisContext();

    genesisStartWith($context, genesisManifestWith(['size_bytes' => (int) config('devboard.artifacts.max_artifact_bytes') + 1]))
        ->assertStatus(422);
});

it('rejects Delta with chunk_count of zero', function () {
    $context = createUnsafeDeltaContext();

    deltaStartWith($context, deltaManifestWith(['chunk_count' => 0]))
        ->assertStatus(422);
});

it('rejects Delta with negative chunk_count', function () {
    $context = createUnsafeDeltaContext();

    deltaStartWith($context, deltaManifestWith(['chunk_count' => -1]))
        ->assertStatus(422);
});

it('rejects Delta with chunk_count above max', function () {
    $context = createUnsafeDeltaContext();

    deltaStartWith($context, deltaManifestWith(['chunk_count' => (int) config('devboard.artifacts.max_chunks') + 1]))
        ->assertStatus(422);
});

it('rejects Delta with negative size_bytes', function () {
    $context = createUnsafeDeltaContext();

    deltaStartWith($context, deltaManifestWith(['size_bytes' => -1]))
        ->assertStatus(422);
});

it('rejects Delta with size_bytes above max', function () {
    $context = createUnsafeDeltaContext();

    deltaStartWith($context, deltaManifestWith(['size_bytes' => (int) config('devboard.artifacts.max_artifact_bytes') + 1]))
        ->assertStatus(422);
});

it('rejects storage path construction for unsafe artifact ids', function () {
    $storage = app(\App\Services\ArtifactStorageService::class);

    $importId = (string) Str::ulid();

    $validPath = $storage->chunkPath($importId, (string) Str::ulid(), 0);
    expect($validPath)->toBeString();

    expect(fn () => $storage->chunkPath($importId, '../outside', 0))
        ->toThrow(InvalidArgumentException::class);

    expect(fn () => $storage->chunkPath($importId, '01J00000000000000000000000/evil', 0))
        ->toThrow(InvalidArgumentException::class);

    expect(fn () => $storage->chunkPath($importId, strtolower((string) Str::ulid()), 0))
        ->toThrow(InvalidArgumentException::class);

    expect(fn () => $storage->artifactPath($importId, '../outside'))
        ->toThrow(InvalidArgumentException::class);

    expect(fn () => $storage->artifactPath($importId, '01J00000000000000000000000/evil'))
        ->toThrow(InvalidArgumentException::class);

    expect(fn () => $storage->artifactPath($importId, strtolower((string) Str::ulid())))
        ->toThrow(InvalidArgumentException::class);
});

function validGenesisManifest(): array
{
    return [
        'protocol_version' => 'v1',
        'bundle_type' => 'genesis_import',
        'schema_version' => 'v1',
        'artifacts' => [
            [
                'artifact_id' => (string) Str::ulid(),
                'artifact_type' => 'file_inventory',
                'schema_version' => 'v1',
                'filename' => 'file-inventory.json',
                'mime_type' => 'application/json',
                'sha256' => hash('sha256', '{"files":[]}'),
                'size_bytes' => 12,
                'chunk_count' => 1,
                'producer' => 'devboard-python-plugin',
                'source_type' => 'local_analyzer',
                'source_status' => 'verified_from_code',
            ],
        ],
    ];
}

function genesisManifestWith(array $artifactOverrides): array
{
    $artifact = array_merge([
        'artifact_id' => (string) Str::ulid(),
        'artifact_type' => 'file_inventory',
        'schema_version' => 'v1',
        'filename' => 'file-inventory.json',
        'mime_type' => 'application/json',
        'sha256' => hash('sha256', 'x'),
        'size_bytes' => 1,
        'chunk_count' => 1,
        'producer' => 'devboard-python-plugin',
        'source_type' => 'local_analyzer',
        'source_status' => 'verified_from_code',
    ], $artifactOverrides);

    return [
        'protocol_version' => 'v1',
        'bundle_type' => 'genesis_import',
        'schema_version' => 'v1',
        'artifacts' => [$artifact],
    ];
}

function genesisStartWith(array $context, array $manifest): TestResponse
{
    return test()->postJson("/api/plugin/v1/repositories/{$context['repository_id']}/genesis-imports", [
        'protocol_version' => 'v1',
        'run_id' => $context['run_id'],
        'local_workspace_id' => $context['local_workspace_id'],
        'manifest' => $manifest,
    ], genesisHeaders($context));
}

function validDeltaManifest(): array
{
    return [
        'protocol_version' => 'v1',
        'bundle_type' => 'delta_sync',
        'schema_version' => 'v1',
        'changed_file_count' => 1,
        'risk_report' => ['risk_level' => 'medium', 'triggers' => ['large_multi_file_diff']],
        'artifacts' => [
            [
                'artifact_id' => (string) Str::ulid(),
                'artifact_type' => 'file_hashes',
                'schema_version' => 'v1',
                'filename' => 'file-hashes.json',
                'mime_type' => 'application/json',
                'sha256' => hash('sha256', '{"hashes":[]}'),
                'size_bytes' => 14,
                'chunk_count' => 1,
                'producer' => 'devboard-python-plugin',
                'source_type' => 'local_analyzer',
                'source_status' => 'verified_from_code',
            ],
        ],
    ];
}

function deltaManifestWith(array $artifactOverrides): array
{
    $artifact = array_merge([
        'artifact_id' => (string) Str::ulid(),
        'artifact_type' => 'file_hashes',
        'schema_version' => 'v1',
        'filename' => 'file-hashes.json',
        'mime_type' => 'application/json',
        'sha256' => hash('sha256', 'x'),
        'size_bytes' => 1,
        'chunk_count' => 1,
        'producer' => 'devboard-python-plugin',
        'source_type' => 'local_analyzer',
        'source_status' => 'verified_from_code',
    ], $artifactOverrides);

    return [
        'protocol_version' => 'v1',
        'bundle_type' => 'delta_sync',
        'schema_version' => 'v1',
        'changed_file_count' => 1,
        'risk_report' => ['risk_level' => 'medium', 'triggers' => ['large_multi_file_diff']],
        'artifacts' => [$artifact],
    ];
}

function deltaStartWith(array $context, array $manifest): TestResponse
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
    ], genesisHeaders($context));
}

function genesisHeaders(array $context): array
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
function createUnsafeGenesisContext(): array
{
    $userId = DB::table('users')->where('email', 'admin@example.com')->value('id');
    $projectId = DB::table('projects')->where('slug', 'demo-project')->value('id');
    $repositoryId = DB::table('repositories')->where('slug', 'demo-repository')->value('id');
    $now = now();
    $deviceId = (string) Str::ulid();
    $tokenId = (string) Str::ulid();
    $workspaceId = (string) Str::ulid();
    $runId = (string) Str::ulid();
    $secret = 'unsafe-genesis-secret';
    $prefix = 'devb_live_'.$tokenId;

    DB::table('devices')->insert([
        'id' => $deviceId,
        'user_id' => $userId,
        'name' => 'Unsafe Genesis Device',
        'fingerprint_hash' => 'sha256:unsafe-genesis',
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
        'name' => 'Unsafe Genesis Token',
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
        'local_root_hash' => 'sha256:unsafe-genesis-workspace',
        'display_path' => '/tmp/unsafe-genesis',
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
 * @return array<string, string>
 */
function createUnsafeDeltaContext(): array
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
    $secret = 'unsafe-delta-secret';
    $prefix = 'devb_live_'.$tokenId;

    DB::table('devices')->insert([
        'id' => $deviceId,
        'user_id' => $userId,
        'name' => 'Unsafe Delta Device',
        'fingerprint_hash' => 'sha256:unsafe-delta',
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
        'name' => 'Unsafe Delta Token',
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
        'local_root_hash' => 'sha256:unsafe-delta-workspace',
        'display_path' => '/tmp/unsafe-delta',
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
