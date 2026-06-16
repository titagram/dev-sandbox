<?php

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

it('rejects a missing plugin token', function () {
    $this->postJson('/api/plugin/v1/auth/check', ['protocol_version' => 'v1'], pluginHeaders())
        ->assertUnauthorized()
        ->assertJsonPath('error.code', 'unauthorized');
});

it('accepts a valid dashboard generated plugin token', function () {
    $token = createPluginToken();

    $this->postJson('/api/plugin/v1/auth/check', ['protocol_version' => 'v1'], pluginHeaders($token['plain_token']))
        ->assertOk()
        ->assertJsonPath('protocol_version', 'v1')
        ->assertJsonPath('authenticated', true)
        ->assertJsonPath('token.token_id', $token['id'])
        ->assertJsonPath('token.token_prefix', $token['prefix'])
        ->assertJsonPath('token.revoked', false);

    expect(DB::table('api_tokens')->where('id', $token['id'])->value('last_used_at'))->not->toBeNull();
});

it('registers a device and binds it to the active token', function () {
    $token = createPluginToken();

    $response = $this->postJson('/api/plugin/v1/devices/register', [
        'protocol_version' => 'v1',
        'name' => 'Gabriele MacBook Pro',
        'fingerprint_hash' => 'sha256:test-device',
        'platform_os' => 'darwin',
        'platform_arch' => 'arm64',
        'plugin_version' => '0.1.0',
    ], pluginHeaders($token['plain_token']));

    $response
        ->assertOk()
        ->assertJsonPath('status', 'active')
        ->assertJsonStructure(['device_id', 'server_time']);

    $deviceId = $response->json('device_id');

    expect(DB::table('devices')->where('id', $deviceId)->value('status'))->toBe('active');
    expect(DB::table('api_tokens')->where('id', $token['id'])->value('device_id'))->toBe($deviceId);
});

it('rejects a revoked plugin token with token_revoked', function () {
    $token = createPluginToken(['revoked_at' => now()]);

    $this->postJson('/api/plugin/v1/auth/check', ['protocol_version' => 'v1'], pluginHeaders($token['plain_token']))
        ->assertUnauthorized()
        ->assertJsonPath('error.code', 'token_revoked');
});

it('rejects a plugin token bound to a revoked device', function () {
    $token = createPluginToken();
    $deviceId = createPluginDevice($token['user_id'], ['status' => 'revoked']);

    DB::table('api_tokens')->where('id', $token['id'])->update([
        'device_id' => $deviceId,
        'updated_at' => now(),
    ]);

    $this->postJson('/api/plugin/v1/auth/check', ['protocol_version' => 'v1'], pluginHeaders($token['plain_token']))
        ->assertUnauthorized()
        ->assertJsonPath('error.code', 'device_required');
});

it('rejects a plugin token with the wrong secret', function () {
    $token = createPluginToken();
    $wrongToken = $token['prefix'].'|wrong-secret';

    $this->postJson('/api/plugin/v1/auth/check', ['protocol_version' => 'v1'], pluginHeaders($wrongToken))
        ->assertUnauthorized()
        ->assertJsonPath('error.code', 'unauthorized');
});

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

/**
 * @param array<string, mixed> $overrides
 * @return array{id: string, prefix: string, plain_token: string, secret: string, user_id: int}
 */
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

/**
 * @param array<string, mixed> $overrides
 */
function createPluginDevice(int $userId, array $overrides = []): string
{
    $id = (string) Str::ulid();
    $now = now();

    DB::table('devices')->insert(array_merge([
        'id' => $id,
        'user_id' => $userId,
        'name' => 'Test Device',
        'fingerprint_hash' => 'sha256:test-revoked-device',
        'platform_os' => 'darwin',
        'platform_arch' => 'arm64',
        'plugin_version' => '0.1.0',
        'last_seen_at' => $now,
        'status' => 'active',
        'created_at' => $now,
        'updated_at' => $now,
    ], $overrides));

    return $id;
}
