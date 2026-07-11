<?php

use Database\Seeders\DevBoardSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(DevBoardSeeder::class);
});

it('lets an authenticated plugin list projects', function () {
    $token = createRepositoryApiToken();

    $this->getJson('/api/plugin/v1/projects', repositoryApiHeaders($token))
        ->assertOk()
        ->assertJsonPath('protocol_version', 'v1')
        ->assertJsonPath('projects.0.slug', 'demo-project');
});

it('lets an authenticated plugin list repositories for a project', function () {
    $token = createRepositoryApiToken();
    $projectId = seededProjectId();

    $this->getJson("/api/plugin/v1/projects/{$projectId}/repositories", repositoryApiHeaders($token))
        ->assertOk()
        ->assertJsonPath('protocol_version', 'v1')
        ->assertJsonPath('repositories.0.slug', 'demo-repository');
});

it('lets an authenticated plugin register a local workspace', function () {
    $token = createRepositoryApiTokenWithDevice();
    $repositoryId = seededRepositoryId();

    $response = $this->postJson("/api/plugin/v1/repositories/{$repositoryId}/local-workspaces", [
        'protocol_version' => 'v1',
        'local_root_hash' => 'sha256:local-root',
        'display_path' => '/Users/gabriele/Dev/demo-repository',
        'current_branch' => 'main',
        'last_head_sha' => 'abc123',
        'dirty_status' => 'clean',
    ], repositoryApiHeaders($token));

    $response
        ->assertOk()
        ->assertJsonPath('status', 'linked')
        ->assertJsonStructure(['local_workspace_id']);

    $workspaceId = $response->json('local_workspace_id');

    expect(DB::table('local_workspaces')->where('id', $workspaceId)->value('display_path'))
        ->toBe('/Users/gabriele/Dev/demo-repository');
});

it('returns the repository policy required by v1', function () {
    $token = createRepositoryApiToken();
    $repositoryId = seededRepositoryId();

    $this->getJson("/api/plugin/v1/repositories/{$repositoryId}/policy", repositoryApiHeaders($token))
        ->assertOk()
        ->assertJsonPath('protocol_version', 'v1')
        ->assertJsonPath('repository_id', $repositoryId)
        ->assertJsonPath('git_mode', 'local_only')
        ->assertJsonPath('code_exposure', 'full_code_artifacts')
        ->assertJsonPath('graph_required', true)
        ->assertJsonPath('secret_scan.mode', 'hybrid_block_warn');
});

it('returns repository instructions without leaking token-like content', function () {
    $token = createRepositoryApiToken();
    $repositoryId = seededRepositoryId();

    $response = $this->getJson("/api/plugin/v1/repositories/{$repositoryId}/instructions", repositoryApiHeaders($token))
        ->assertOk()
        ->assertJsonPath('protocol_version', 'v1')
        ->assertJsonPath('repository_id', $repositoryId);

    $body = json_encode($response->json(), JSON_THROW_ON_ERROR);

    expect($body)
        ->not->toContain('devb_live_')
        ->not->toContain($token['secret']);
});

it('returns scope_missing when the plugin token lacks the required scope', function () {
    $token = createRepositoryApiToken(['runs.write']);

    $this->getJson('/api/plugin/v1/projects', repositoryApiHeaders($token))
        ->assertForbidden()
        ->assertJsonPath('error.code', 'scope_missing');
});

/**
 * @param  list<string>|null  $scopes
 * @return array{id: string, prefix: string, plain_token: string, secret: string, device_id?: string}
 */
function createRepositoryApiToken(?array $scopes = null): array
{
    $userId = DB::table('users')->where('email', 'admin@example.com')->value('id');
    $id = (string) Str::ulid();
    $secret = 'repository-api-secret';
    $prefix = 'devb_live_'.$id;
    $now = now();

    DB::table('api_tokens')->insert([
        'id' => $id,
        'token_prefix' => $prefix,
        'token_hash' => hash('sha256', $secret),
        'user_id' => $userId,
        'device_id' => null,
        'name' => 'Repository API Test Token',
        'scopes' => json_encode($scopes ?? [
            'projects.read',
            'repositories.read',
            'policies.read',
            'runs.write',
            'artifacts.write',
            'wiki.write',
            'graph.write',
        ], JSON_THROW_ON_ERROR),
        'expires_at' => now()->addMonth(),
        'revoked_at' => null,
        'last_used_at' => null,
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    return [
        'id' => $id,
        'prefix' => $prefix,
        'plain_token' => $prefix.'|'.$secret,
        'secret' => $secret,
    ];
}

/**
 * @return array{id: string, prefix: string, plain_token: string, secret: string, device_id: string}
 */
function createRepositoryApiTokenWithDevice(): array
{
    $token = createRepositoryApiToken();
    $deviceId = (string) Str::ulid();
    $now = now();
    $userId = DB::table('api_tokens')->where('id', $token['id'])->value('user_id');

    DB::table('devices')->insert([
        'id' => $deviceId,
        'user_id' => $userId,
        'name' => 'Repository API Device',
        'fingerprint_hash' => 'sha256:repository-api-device',
        'platform_os' => 'darwin',
        'platform_arch' => 'arm64',
        'plugin_version' => '0.1.0',
        'last_seen_at' => $now,
        'status' => 'active',
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    DB::table('api_tokens')->where('id', $token['id'])->update([
        'device_id' => $deviceId,
        'updated_at' => $now,
    ]);

    $token['device_id'] = $deviceId;

    return $token;
}

/**
 * @param  array{id: string, prefix: string, plain_token: string, secret: string, device_id?: string}  $token
 * @return array<string, string>
 */
function repositoryApiHeaders(array $token): array
{
    $headers = [
        'Authorization' => 'Bearer '.$token['plain_token'],
        'X-DevBoard-Protocol' => 'v1',
        'X-DevBoard-Plugin-Version' => '0.1.0',
    ];

    if (array_key_exists('device_id', $token)) {
        $headers['X-DevBoard-Device-Id'] = $token['device_id'];
    }

    return $headers;
}

function seededProjectId(): string
{
    return DB::table('projects')->where('slug', 'demo-project')->value('id');
}

function seededRepositoryId(): string
{
    return DB::table('repositories')->where('slug', 'demo-repository')->value('id');
}
