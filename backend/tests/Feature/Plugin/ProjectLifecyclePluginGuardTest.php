<?php

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

beforeEach(function () {
    Storage::fake('local');
    $this->seed(\Database\Seeders\DevBoardSeeder::class);
});

it('lists only active projects through plugin namespace', function () {
    $token = projectLifecyclePluginToken();
    projectLifecyclePluginProject('Plugin Archived', 'plugin-archived', 'archived');
    projectLifecyclePluginProject('Plugin Deleted', 'plugin-deleted', 'deleted');

    $projects = $this->getJson('/api/plugin/v1/projects', projectLifecyclePluginHeaders($token))
        ->assertOk()
        ->json('projects');

    expect(collect($projects)->pluck('status')->unique()->values()->all())->toBe(['active']);
});

it('returns conflict for archived project plugin write operations', function () {
    $token = projectLifecyclePluginToken();
    $projectId = projectLifecyclePluginProject('Archived Plugin Project', 'archived-plugin-project', 'archived');
    $repositoryId = projectLifecyclePluginRepository($projectId);

    $this->getJson("/api/plugin/v1/projects/{$projectId}/repositories", projectLifecyclePluginHeaders($token))
        ->assertConflict()
        ->assertJsonPath('error.code', 'project_archived');

    $this->postJson('/api/plugin/v1/runs', [
        'protocol_version' => 'v1',
        'project_id' => $projectId,
        'repository_id' => $repositoryId,
        'run_type' => 'delta',
        'runtime_profile' => 'agent_plugin',
        'branch' => 'main',
        'base_branch' => 'main',
        'base_sha' => 'abc123',
        'head_sha' => 'def456',
        'dirty_status' => 'clean',
    ], projectLifecyclePluginHeaders($token))
        ->assertConflict()
        ->assertJsonPath('error.code', 'project_archived');
});

it('hides deleted projects from plugin write operations', function () {
    $token = projectLifecyclePluginToken();
    $projectId = projectLifecyclePluginProject('Deleted Plugin Project', 'deleted-plugin-project', 'deleted');
    $repositoryId = projectLifecyclePluginRepository($projectId);

    $this->getJson("/api/plugin/v1/projects/{$projectId}/repositories", projectLifecyclePluginHeaders($token))
        ->assertNotFound();

    $this->getJson("/api/plugin/v1/repositories/{$repositoryId}/instructions", projectLifecyclePluginHeaders($token))
        ->assertNotFound();
});

function projectLifecyclePluginToken(): string
{
    $user = User::factory()->create(['status' => 'active']);
    $id = (string) Str::ulid();
    $secret = 'lifecycle-plugin-secret';
    $prefix = 'devb_live_'.$id;
    $now = now();

    DB::table('api_tokens')->insert([
        'id' => $id,
        'user_id' => $user->id,
        'device_id' => null,
        'name' => 'Lifecycle plugin token',
        'token_prefix' => $prefix,
        'token_hash' => hash('sha256', $secret),
        'scopes' => json_encode([
            'projects.read',
            'repositories.read',
            'policies.read',
            'runs.write',
            'artifacts.write',
            'wiki.write',
        ], JSON_THROW_ON_ERROR),
        'expires_at' => now()->addDay(),
        'revoked_at' => null,
        'last_used_at' => null,
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    return $prefix.'|'.$secret;
}

/**
 * @return array<string, string>
 */
function projectLifecyclePluginHeaders(string $token): array
{
    return [
        'Authorization' => 'Bearer '.$token,
        'X-DevBoard-Protocol' => 'v1',
        'X-DevBoard-Plugin-Version' => '0.1.0',
    ];
}

function projectLifecyclePluginProject(string $name, string $slug, string $status): string
{
    $id = (string) Str::ulid();
    $adminId = DB::table('users')->where('email', 'admin@example.com')->value('id');
    $now = now();

    DB::table('projects')->insert([
        'id' => $id,
        'name' => $name,
        'slug' => $slug,
        'description' => "{$name} description.",
        'status' => $status,
        'default_code_exposure_policy' => 'full_code_artifacts',
        'created_by_user_id' => $adminId,
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    return $id;
}

function projectLifecyclePluginRepository(string $projectId): string
{
    $id = (string) Str::ulid();
    $now = now();

    DB::table('repositories')->insert([
        'id' => $id,
        'project_id' => $projectId,
        'name' => 'plugin-repository',
        'slug' => 'plugin-repository',
        'default_branch' => 'main',
        'local_only' => true,
        'code_exposure_policy' => 'full_code_artifacts',
        'protected_paths' => json_encode([], JSON_THROW_ON_ERROR),
        'excluded_paths' => json_encode([], JSON_THROW_ON_ERROR),
        'stack_hints' => json_encode(['php'], JSON_THROW_ON_ERROR),
        'graph_enabled' => true,
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    return $id;
}
