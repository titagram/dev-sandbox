<?php

use App\Models\User;
use App\Services\AuditLogger;
use Database\Seeders\DevBoardSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Arr;
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
    $this->seed(DevBoardSeeder::class);
});

it('records token created and revoked events', function () {
    $admin = adminUserForAuditTest();

    $created = $this->actingAs($admin)->postJson('/admin/plugin-tokens', [
        'name' => 'Audit test token',
        'scopes' => ['projects.read'],
        'expires_in_days' => 30,
    ]);

    $created->assertOk();
    $token = $created->json('token');

    expect(DB::table('audit_logs')
        ->where('action', 'token.created')
        ->where('target_type', 'api_token')
        ->where('target_id', $token['id'])
        ->exists())->toBeTrue('Expected token.created audit event after store');

    $this->actingAs($admin)->deleteJson("/admin/plugin-tokens/{$token['id']}")
        ->assertOk()
        ->assertJsonPath('revoked', true);

    expect(DB::table('audit_logs')
        ->where('action', 'token.revoked')
        ->where('target_type', 'api_token')
        ->where('target_id', $token['id'])
        ->exists())->toBeTrue('Expected token.revoked audit event after destroy');
});

it('records permission denied when plugin scope is missing', function () {
    $token = createPluginToken([
        'scopes' => json_encode(['projects.read'], JSON_THROW_ON_ERROR),
    ]);

    $this->postJson('/api/plugin/v1/runs', ['protocol_version' => 'v1'], pluginHeaders($token['plain_token']))
        ->assertForbidden()
        ->assertJsonPath('error.code', 'scope_missing');

    expect(DB::table('audit_logs')
        ->where('action', 'permission.denied')
        ->where('target_type', 'api_token')
        ->where('target_id', $token['id'])
        ->exists())->toBeTrue('Expected permission.denied audit event');
});

it('records artifact upload rejection when validation fails after start', function () {
    $context = createGenesisUploadContext();
    $artifact = genesisArtifact('file_inventory', 'file-inventory.json', 'hello');
    $artifact['sha256'] = str_repeat('0', 64);
    $importId = genesisStart($context, genesisManifest([$artifact]))->json('import_id');

    genesisChunk($context, $importId, $artifact['artifact_id'], 0, 'hello', hash('sha256', 'hello'))->assertOk();

    genesisFinalize($context, $importId)
        ->assertUnprocessable()
        ->assertJsonPath('error.code', 'artifact_hash_mismatch');

    expect(DB::table('audit_logs')
        ->where('action', 'artifact.rejected')
        ->where('target_type', 'artifact')
        ->where('target_id', $artifact['artifact_id'])
        ->exists())->toBeTrue('Expected artifact.rejected audit event');
    expect(DB::table('artifacts')->where('id', $artifact['artifact_id'])->value('status'))->toBe('uploading');
    expect(DB::table('genesis_imports')->where('id', $importId)->value('snapshot_id'))->toBeNull();
    expect(DB::table('snapshots')->where('created_by_run_id', $context['run_id'])->exists())->toBeFalse();
});

it('chains audit rows with prev_hash and row_hash', function () {
    $logger = app(AuditLogger::class);

    $logger->record('test.event_one', 'test_target', 'target-1', ['key' => 'one'], [
        'type' => 'system',
    ]);

    $logger->record('test.event_two', 'test_target', 'target-2', ['key' => 'two'], [
        'type' => 'user',
    ]);

    $rows = DB::table('audit_logs')->orderBy('sequence')->get();

    expect($rows)->toHaveCount(2);

    $first = $rows[0];
    $second = $rows[1];

    expect($first->row_hash)->not->toBeNull();
    expect($first->sequence)->toBe(1);
    expect($first->chain_version)->toBe(1);
    expect($first->prev_hash)->toBeNull();
    expect($second->row_hash)->not->toBeNull();
    expect($second->sequence)->toBe(2);
    expect($second->chain_version)->toBe(1);
    expect($second->prev_hash)->toBe($first->row_hash);
});

it('records many audit rows while preserving one contiguous chain', function () {
    app(AuditLogger::class)->recordMany([
        [
            'action' => 'test.batch_one',
            'target_type' => 'test_target',
            'target_id' => 'target-1',
            'payload' => ['z' => 'last', 'a' => 'first'],
            'actor' => ['type' => 'system'],
        ],
        [
            'action' => 'test.batch_two',
            'target_type' => 'test_target',
            'target_id' => 'target-2',
            'payload' => ['b' => 'second'],
            'actor' => ['type' => 'system'],
        ],
    ]);

    $rows = DB::table('audit_logs')->orderBy('sequence')->get();
    $head = DB::table('audit_chain_heads')->where('chain_key', 'global')->first();

    expect($rows)->toHaveCount(2);
    expect($rows->pluck('sequence')->all())->toBe([1, 2]);
    expect($rows[1]->prev_hash)->toBe($rows[0]->row_hash);
    expect($head->last_sequence)->toBe(2);
    expect($head->last_hash)->toBe($rows[1]->row_hash);
});

function adminUserForAuditTest(): User
{
    $user = User::factory()->create();
    $roleId = DB::table('roles')->where('name', 'Admin')->value('id');

    DB::table('role_user')->insert([
        'user_id' => $user->id,
        'role_id' => $roleId,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    return $user;
}

/**
 * @param  array<string, mixed>  $overrides
 * @return array{id: string, prefix: string, plain_token: string, secret: string, user_id: int}
 */
if (! function_exists('createPluginToken')) {
    function createPluginToken(array $overrides = []): array
    {
        $user = User::factory()->create(['status' => 'active']);
        $id = (string) Str::ulid();
        $secret = $overrides['secret'] ?? 'test-secret';
        $prefix = 'devb_live_'.$id;
        $now = now();

        DB::table('api_tokens')->insert(array_merge([
            'id' => $id,
            'token_prefix' => $prefix,
            'token_hash' => hash('sha256', $secret),
            'user_id' => $user->id,
            'device_id' => null,
            'name' => 'Test Plugin Token',
            'scopes' => json_encode(['projects.read', 'runs.write'], JSON_THROW_ON_ERROR),
            'expires_at' => now()->addMonth(),
            'revoked_at' => null,
            'last_used_at' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ], $overrides));

        return [
            'id' => $id,
            'prefix' => $prefix,
            'plain_token' => $prefix.'|'.$secret,
            'secret' => $secret,
            'user_id' => $user->id,
        ];
    }
}

if (! function_exists('pluginHeaders')) {
    function pluginHeaders(?string $token = null): array
    {
        $headers = [
            'X-DevBoard-Protocol' => 'v1',
            'X-DevBoard-Plugin-Version' => '0.1.0',
        ];

        if ($token !== null) {
            $headers['Authorization'] = 'Bearer '.$token;
        }

        return $headers;
    }
}

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
