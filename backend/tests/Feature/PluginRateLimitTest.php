<?php

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

it('rate limits plugin api requests by token prefix', function () {
    config(['services.devboard.plugin_rate_limit_per_minute' => 2]);
    $token = createRateLimitToken();

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
        'scopes' => json_encode(['projects.read', 'runs.write'], JSON_THROW_ON_ERROR),
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
