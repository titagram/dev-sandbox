<?php

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(\Database\Seeders\DevBoardSeeder::class);
});

it('rate limits plugin api requests by token prefix', function () {
    config([
        'services.devboard.plugin_light_rate_limit_per_minute' => 2,
        'services.devboard.plugin_heavy_rate_limit_per_minute' => 10,
    ]);
    $token = createRateLimitToken();

    $this->postJson('/api/plugin/v1/auth/check', ['protocol_version' => 'v1'], rateLimitHeaders($token))->assertOk();
    $this->postJson('/api/plugin/v1/auth/check', ['protocol_version' => 'v1'], rateLimitHeaders($token))->assertOk();

    $this->postJson('/api/plugin/v1/auth/check', ['protocol_version' => 'v1'], rateLimitHeaders($token))
        ->assertTooManyRequests()
        ->assertJsonPath('error.code', 'rate_limited');
});

it('keeps heavy upload throttling separate from lightweight auth checks', function () {
    config([
        'services.devboard.plugin_light_rate_limit_per_minute' => 2,
        'services.devboard.plugin_heavy_rate_limit_per_minute' => 1,
    ]);
    $token = createRateLimitToken();
    $context = createHeavyRateLimitContext();
    $payload = [
        'protocol_version' => 'v1',
        'run_id' => $context['run_id'],
        'local_workspace_id' => $context['local_workspace_id'],
        'manifest' => [
            'artifacts' => [[
                'artifact_id' => (string) Str::ulid(),
                'artifact_type' => 'file_inventory',
                'sha256' => hash('sha256', '{}'),
                'size_bytes' => 2,
                'mime_type' => 'application/json',
                'schema_version' => 'v1',
                'producer' => 'devboard-python-plugin',
                'chunk_count' => 1,
            ]],
        ],
    ];

    $this->postJson("/api/plugin/v1/repositories/{$context['repository_id']}/genesis-imports", $payload, rateLimitHeaders($token))->assertOk();
    $this->postJson("/api/plugin/v1/repositories/{$context['repository_id']}/genesis-imports", $payload, rateLimitHeaders($token))
        ->assertTooManyRequests()
        ->assertJsonPath('error.code', 'rate_limited');

    $this->postJson('/api/plugin/v1/auth/check', ['protocol_version' => 'v1'], rateLimitHeaders($token))->assertOk();
    $this->postJson('/api/plugin/v1/auth/check', ['protocol_version' => 'v1'], rateLimitHeaders($token))->assertOk();
    $this->postJson('/api/plugin/v1/auth/check', ['protocol_version' => 'v1'], rateLimitHeaders($token))
        ->assertTooManyRequests()
        ->assertJsonPath('error.code', 'rate_limited');
});

/**
 * @return array{prefix: string, plain_token: string}
 */
function createRateLimitToken(): array
{
    $user = User::factory()->create(['status' => 'active']);
    $id = (string) Str::ulid();
    $secret = 'rate-limit-secret';
    $prefix = 'devb_live_'.$id;
    $now = now();

    DB::table('api_tokens')->insert([
        'id' => $id,
        'token_prefix' => $prefix,
        'token_hash' => hash('sha256', $secret),
        'user_id' => $user->id,
        'device_id' => null,
        'name' => 'Rate Limit Test Token',
        'scopes' => json_encode(['projects.read', 'repositories.read', 'runs.write', 'artifacts.write'], JSON_THROW_ON_ERROR),
        'expires_at' => now()->addMonth(),
        'revoked_at' => null,
        'last_used_at' => null,
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    return [
        'prefix' => $prefix,
        'plain_token' => $prefix.'|'.$secret,
    ];
}

/**
 * @param array{plain_token: string} $token
 * @return array<string, string>
 */
function rateLimitHeaders(array $token): array
{
    return [
        'Authorization' => 'Bearer '.$token['plain_token'],
        'X-DevBoard-Protocol' => 'v1',
        'X-DevBoard-Plugin-Version' => '0.1.0',
    ];
}

/**
 * @return array{repository_id: string, local_workspace_id: string, run_id: string}
 */
function createHeavyRateLimitContext(): array
{
    $userId = DB::table('users')->where('email', 'admin@example.com')->value('id');
    $repositoryId = DB::table('repositories')->where('slug', 'demo-repository')->value('id');
    $projectId = DB::table('projects')->where('slug', 'demo-project')->value('id');
    $deviceId = (string) Str::ulid();
    $workspaceId = (string) Str::ulid();
    $runId = (string) Str::ulid();
    $now = now();

    DB::table('devices')->insert([
        'id' => $deviceId,
        'user_id' => $userId,
        'name' => 'Rate Limit Device',
        'fingerprint_hash' => 'sha256:rate-limit-device',
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
        'local_root_hash' => 'sha256:rate-limit-workspace',
        'display_path' => '/tmp/rate-limit',
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
        'status' => 'running',
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
        'repository_id' => $repositoryId,
        'local_workspace_id' => $workspaceId,
        'run_id' => $runId,
    ];
}
