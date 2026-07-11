<?php

use App\Models\User;
use Database\Seeders\DevBoardSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(DevBoardSeeder::class);
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
    $context = createHeavyRateLimitContext($token);
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
    $heavyUri = "/api/plugin/v1/repositories/{$context['repository_id']}/genesis-imports";

    $this->postJson($heavyUri, $payload, signedRateLimitHeaders($token, $context, $heavyUri, $payload))->assertOk();
    $this->postJson($heavyUri, $payload, signedRateLimitHeaders($token, $context, $heavyUri, $payload))
        ->assertTooManyRequests()
        ->assertJsonPath('error.code', 'rate_limited');

    $lightUri = '/api/plugin/v1/auth/check';
    $lightPayload = ['protocol_version' => 'v1'];
    $this->postJson($lightUri, $lightPayload, signedRateLimitHeaders($token, $context, $lightUri, $lightPayload))->assertOk();
    $this->postJson($lightUri, $lightPayload, signedRateLimitHeaders($token, $context, $lightUri, $lightPayload))->assertOk();
    $this->postJson($lightUri, $lightPayload, signedRateLimitHeaders($token, $context, $lightUri, $lightPayload))
        ->assertTooManyRequests()
        ->assertJsonPath('error.code', 'rate_limited');
});

/**
 * @return array{prefix: string, plain_token: string, user_id: int, token_id: string}
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
        'user_id' => $user->id,
        'token_id' => $id,
    ];
}

/**
 * @param  array{plain_token: string}  $token
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
 * @param  array{plain_token: string}  $token
 * @param  array{device_id: string, device_secret: string}  $context
 * @param  array<string, mixed>  $payload
 * @return array<string, string>
 */
function signedRateLimitHeaders(array $token, array $context, string $uri, array $payload): array
{
    $timestamp = time();
    $bodyHash = hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR));
    $canonical = "POST\n{$uri}\n{$timestamp}\n{$bodyHash}";
    $signingKey = hash('sha256', $context['device_secret']);

    return array_merge(rateLimitHeaders($token), [
        'X-DevBoard-Device-Id' => $context['device_id'],
        'X-DevBoard-Timestamp' => (string) $timestamp,
        'X-DevBoard-Content-SHA256' => $bodyHash,
        'X-DevBoard-Signature' => 'v1='.hash_hmac('sha256', $canonical, $signingKey),
    ]);
}

/**
 * @param  array{user_id: int, token_id: string}  $token
 * @return array{repository_id: string, local_workspace_id: string, run_id: string, device_id: string, device_secret: string}
 */
function createHeavyRateLimitContext(array $token): array
{
    $userId = $token['user_id'];
    $repositoryId = DB::table('repositories')->where('slug', 'demo-repository')->value('id');
    $projectId = DB::table('projects')->where('slug', 'demo-project')->value('id');
    $deviceId = (string) Str::ulid();
    $workspaceId = (string) Str::ulid();
    $runId = (string) Str::ulid();
    $deviceSecret = bin2hex(random_bytes(32));
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
        'signing_secret_hash' => hash('sha256', $deviceSecret),
        'status' => 'active',
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    DB::table('api_tokens')->where('id', $token['token_id'])->update([
        'device_id' => $deviceId,
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
        'device_id' => $deviceId,
        'device_secret' => $deviceSecret,
    ];
}
