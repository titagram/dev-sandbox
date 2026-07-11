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

it('persists local agent reported git workspace metadata and exposes it to dashboard readers', function () {
    $admin = gitStateUserWithRole('Admin');
    $pm = gitStateUserWithRole('PM');

    $project = $this->actingAs($admin)
        ->postJson('/api/dashboard/projects', [
            'name' => 'Git State Project',
            'key' => 'git-state-project',
            'description' => 'Project used to verify local agent Git state sync.',
        ])
        ->assertCreated()
        ->json();

    $projectId = (string) $project['id'];

    $repository = $this->actingAs($pm)
        ->postJson("/api/dashboard/projects/{$projectId}/repositories", [
            'name' => 'Git State Repository',
            'key' => 'git-state-repository',
            'default_branch' => 'main',
        ])
        ->assertCreated()
        ->json('repositories.0');

    $repositoryId = (string) $repository['id'];
    $token = gitStatePluginTokenWithDevice();
    $remoteUrl = 'https://token@example.test/org/repo.git';
    $remoteHash = 'sha256:'.hash('sha256', $remoteUrl);

    $this->postJson("/api/plugin/v1/repositories/{$repositoryId}/local-workspaces", [
        'protocol_version' => 'v1',
        'local_root_hash' => 'sha256:git-state-root',
        'display_path' => '/Users/gabriele/Dev/sinervis/carnovali',
        'current_branch' => 'feature/git-sync',
        'last_head_sha' => 'abc123',
        'dirty_status' => 'dirty',
        'remote_name' => 'origin',
        'remote_url_host' => 'example.test',
        'remote_url_hash' => $remoteHash,
        'remote_url' => $remoteUrl,
        'upstream_branch' => 'origin/feature/git-sync',
        'ahead_count' => 2,
        'behind_count' => 1,
        'git_state_observed_at' => '2026-06-25T16:30:00Z',
    ], gitStatePluginHeaders($token))->assertOk();

    $workspace = DB::table('local_workspaces')
        ->where('repository_id', $repositoryId)
        ->where('device_id', $token['device_id'])
        ->first();

    expect($workspace)->not->toBeNull()
        ->and($workspace->remote_name)->toBe('origin')
        ->and($workspace->remote_url_host)->toBe('example.test')
        ->and($workspace->remote_url_hash)->toBe($remoteHash)
        ->and($workspace->upstream_branch)->toBe('origin/feature/git-sync')
        ->and((int) $workspace->ahead_count)->toBe(2)
        ->and((int) $workspace->behind_count)->toBe(1)
        ->and((string) $workspace->git_state_observed_at)->toContain('2026-06-25');

    $this->actingAs($pm)
        ->getJson("/api/dashboard/projects/{$projectId}")
        ->assertOk()
        ->assertJsonPath('repositories.0.local_workspace.current_branch', 'feature/git-sync')
        ->assertJsonPath('repositories.0.local_workspace.dirty_status', 'dirty')
        ->assertJsonPath('repositories.0.local_workspace.remote_name', 'origin')
        ->assertJsonPath('repositories.0.local_workspace.remote_url_host', 'example.test')
        ->assertJsonPath('repositories.0.local_workspace.remote_url_hash', $remoteHash)
        ->assertJsonPath('repositories.0.local_workspace.upstream_branch', 'origin/feature/git-sync')
        ->assertJsonPath('repositories.0.local_workspace.ahead_count', 2)
        ->assertJsonPath('repositories.0.local_workspace.behind_count', 1)
        ->assertJsonPath('repositories.0.local_workspace.git_state_observed_at', '2026-06-25T16:30:00Z')
        ->assertJsonPath('repositories.0.local_workspace.source_truth', 'local_agent_reported')
        ->assertJsonMissingPath('repositories.0.local_workspace.remote_url');
});

function gitStateUserWithRole(string $roleName): User
{
    $user = User::factory()->create(['status' => 'active']);
    $roleId = DB::table('roles')->where('name', $roleName)->value('id');

    DB::table('role_user')->insert([
        'user_id' => $user->id,
        'role_id' => $roleId,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    return $user;
}

/**
 * @return array{id: string, plain_token: string, secret: string, device_id: string}
 */
function gitStatePluginTokenWithDevice(): array
{
    $userId = DB::table('users')->where('email', 'admin@example.com')->value('id');
    $tokenId = (string) Str::ulid();
    $deviceId = (string) Str::ulid();
    $secret = 'git-state-plugin-secret';
    $prefix = 'devb_live_'.$tokenId;
    $now = now();

    DB::table('devices')->insert([
        'id' => $deviceId,
        'user_id' => $userId,
        'name' => 'Git State Test Device',
        'fingerprint_hash' => 'sha256:git-state-device',
        'platform_os' => 'linux',
        'platform_arch' => 'amd64',
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
        'name' => 'Git State Test Token',
        'scopes' => json_encode([
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
        'id' => $tokenId,
        'plain_token' => $prefix.'|'.$secret,
        'secret' => $secret,
        'device_id' => $deviceId,
    ];
}

/**
 * @param  array{plain_token: string, device_id: string}  $token
 * @return array<string, string>
 */
function gitStatePluginHeaders(array $token): array
{
    return [
        'Authorization' => 'Bearer '.$token['plain_token'],
        'X-DevBoard-Protocol' => 'v1',
        'X-DevBoard-Plugin-Version' => '0.1.0',
        'X-DevBoard-Device-Id' => $token['device_id'],
    ];
}
