<?php

use App\Models\ApiToken;
use App\Models\Artifact;
use App\Models\Device;
use App\Models\Project;
use App\Models\Repository;
use App\Models\Run;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

it('has projects and repositories relationship', function () {
    DB::table('users')->insert([
        'id' => 1,
        'name' => 'Test User',
        'email' => 'test@example.com',
        'password' => bcrypt('password'),
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $projectId = (string) Str::ulid();
    DB::table('projects')->insert([
        'id' => $projectId,
        'name' => 'Test Project',
        'slug' => 'test-project',
        'status' => 'active',
        'created_by_user_id' => 1,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $repoId = (string) Str::ulid();
    DB::table('repositories')->insert([
        'id' => $repoId,
        'project_id' => $projectId,
        'name' => 'Test Repo',
        'slug' => 'test-repo',
        'protected_paths' => json_encode([]),
        'excluded_paths' => json_encode([]),
        'stack_hints' => json_encode([]),
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $project = Project::find($projectId);
    $repository = Repository::find($repoId);

    expect($project)->not->toBeNull();
    expect($project->repositories)->toHaveCount(1);
    expect($project->repositories->first()->id)->toBe($repoId);
    expect($repository->project->id)->toBe($projectId);
});

it('has run relationships with project, repository, and device', function () {
    DB::table('users')->insert([
        'id' => 1,
        'name' => 'Test User',
        'email' => 'test@example.com',
        'password' => bcrypt('password'),
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $projectId = (string) Str::ulid();
    DB::table('projects')->insert([
        'id' => $projectId,
        'name' => 'Test Project',
        'slug' => 'test-project',
        'status' => 'active',
        'created_by_user_id' => 1,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $deviceId = (string) Str::ulid();
    DB::table('devices')->insert([
        'id' => $deviceId,
        'user_id' => 1,
        'name' => 'Test Device',
        'fingerprint_hash' => 'abc123',
        'platform_os' => 'linux',
        'platform_arch' => 'amd64',
        'plugin_version' => '1.0.0',
        'status' => 'active',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $repoId = (string) Str::ulid();
    DB::table('repositories')->insert([
        'id' => $repoId,
        'project_id' => $projectId,
        'name' => 'Test Repo',
        'slug' => 'test-repo',
        'protected_paths' => json_encode([]),
        'excluded_paths' => json_encode([]),
        'stack_hints' => json_encode([]),
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $runId = (string) Str::ulid();
    DB::table('runs')->insert([
        'id' => $runId,
        'project_id' => $projectId,
        'repository_id' => $repoId,
        'device_id' => $deviceId,
        'started_by_user_id' => 1,
        'runtime_profile' => 'default',
        'status' => 'running',
        'branch' => 'main',
        'base_branch' => 'main',
        'base_sha' => 'abc123',
        'started_at' => now(),
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $run = Run::find($runId);

    expect($run)->not->toBeNull();
    expect($run->project->id)->toBe($projectId);
    expect($run->repository->id)->toBe($repoId);
    expect($run->device->id)->toBe($deviceId);
});

it('has artifacts relationship with project, repository, and run', function () {
    DB::table('users')->insert([
        'id' => 1,
        'name' => 'Test User',
        'email' => 'test@example.com',
        'password' => bcrypt('password'),
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $projectId = (string) Str::ulid();
    DB::table('projects')->insert([
        'id' => $projectId,
        'name' => 'Test Project',
        'slug' => 'test-project',
        'status' => 'active',
        'created_by_user_id' => 1,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $repoId = (string) Str::ulid();
    DB::table('repositories')->insert([
        'id' => $repoId,
        'project_id' => $projectId,
        'name' => 'Test Repo',
        'slug' => 'test-repo',
        'protected_paths' => json_encode([]),
        'excluded_paths' => json_encode([]),
        'stack_hints' => json_encode([]),
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $deviceId = (string) Str::ulid();
    DB::table('devices')->insert([
        'id' => $deviceId,
        'user_id' => 1,
        'name' => 'Test Device',
        'fingerprint_hash' => 'abc123',
        'platform_os' => 'linux',
        'platform_arch' => 'amd64',
        'plugin_version' => '1.0.0',
        'status' => 'active',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $runId = (string) Str::ulid();
    DB::table('runs')->insert([
        'id' => $runId,
        'project_id' => $projectId,
        'device_id' => $deviceId,
        'started_by_user_id' => 1,
        'runtime_profile' => 'default',
        'status' => 'running',
        'branch' => 'main',
        'base_branch' => 'main',
        'base_sha' => 'abc123',
        'started_at' => now(),
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $artifactId = (string) Str::ulid();
    DB::table('artifacts')->insert([
        'id' => $artifactId,
        'project_id' => $projectId,
        'repository_id' => $repoId,
        'run_id' => $runId,
        'artifact_type' => 'file_inventory',
        'storage_path' => 'artifacts/test.json',
        'sha256' => str_repeat('a', 64),
        'size_bytes' => 1024,
        'mime_type' => 'application/json',
        'schema_version' => '1.0',
        'status' => 'completed',
        'producer' => 'plugin',
        'metadata' => json_encode(['key' => 'value']),
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $artifact = Artifact::find($artifactId);

    expect($artifact)->not->toBeNull();
    expect($artifact->project->id)->toBe($projectId);
    expect($artifact->repository->id)->toBe($repoId);
    expect($artifact->run->id)->toBe($runId);
});

it('has devices belonging to users', function () {
    DB::table('users')->insert([
        'id' => 1,
        'name' => 'Test User',
        'email' => 'test@example.com',
        'password' => bcrypt('password'),
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $deviceId = (string) Str::ulid();
    DB::table('devices')->insert([
        'id' => $deviceId,
        'user_id' => 1,
        'name' => 'Test Device',
        'fingerprint_hash' => 'abc123',
        'platform_os' => 'linux',
        'platform_arch' => 'amd64',
        'plugin_version' => '1.0.0',
        'status' => 'active',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $device = Device::find($deviceId);

    expect($device)->not->toBeNull();
    expect($device->user->id)->toBe(1);
    expect($device->user->name)->toBe('Test User');
});

it('has api tokens relationship with user and device', function () {
    DB::table('users')->insert([
        'id' => 1,
        'name' => 'Test User',
        'email' => 'test@example.com',
        'password' => bcrypt('password'),
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $deviceId = (string) Str::ulid();
    DB::table('devices')->insert([
        'id' => $deviceId,
        'user_id' => 1,
        'name' => 'Test Device',
        'fingerprint_hash' => 'abc123',
        'platform_os' => 'linux',
        'platform_arch' => 'amd64',
        'plugin_version' => '1.0.0',
        'status' => 'active',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $tokenId = (string) Str::ulid();
    DB::table('api_tokens')->insert([
        'id' => $tokenId,
        'token_prefix' => 'dvt_abc',
        'token_hash' => hash('sha256', 'test-token'),
        'user_id' => 1,
        'device_id' => $deviceId,
        'name' => 'Test Token',
        'scopes' => json_encode(['read', 'write']),
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $token = ApiToken::find($tokenId);

    expect($token)->not->toBeNull();
    expect($token->user->id)->toBe(1);
    expect($token->device->id)->toBe($deviceId);
});

it('casts json columns to arrays', function () {
    DB::table('users')->insert([
        'id' => 1,
        'name' => 'Test User',
        'email' => 'test@example.com',
        'password' => bcrypt('password'),
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $projectId = (string) Str::ulid();
    DB::table('projects')->insert([
        'id' => $projectId,
        'name' => 'Test Project',
        'slug' => 'test-project',
        'status' => 'active',
        'created_by_user_id' => 1,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $repoId = (string) Str::ulid();
    DB::table('repositories')->insert([
        'id' => $repoId,
        'project_id' => $projectId,
        'name' => 'Test Repo',
        'slug' => 'test-repo',
        'protected_paths' => json_encode(['path1']),
        'excluded_paths' => json_encode(['path2']),
        'stack_hints' => json_encode(['php', 'laravel']),
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $deviceId = (string) Str::ulid();
    DB::table('devices')->insert([
        'id' => $deviceId,
        'user_id' => 1,
        'name' => 'Test Device',
        'fingerprint_hash' => 'abc123',
        'platform_os' => 'linux',
        'platform_arch' => 'amd64',
        'plugin_version' => '1.0.0',
        'status' => 'active',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $tokenId = (string) Str::ulid();
    DB::table('api_tokens')->insert([
        'id' => $tokenId,
        'token_prefix' => 'dvt_abc',
        'token_hash' => hash('sha256', 'test-token'),
        'user_id' => 1,
        'name' => 'Test Token',
        'scopes' => json_encode(['read', 'write']),
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $runId = (string) Str::ulid();
    DB::table('runs')->insert([
        'id' => $runId,
        'project_id' => $projectId,
        'device_id' => $deviceId,
        'started_by_user_id' => 1,
        'runtime_profile' => 'default',
        'status' => 'running',
        'branch' => 'main',
        'base_branch' => 'main',
        'base_sha' => 'abc123',
        'started_at' => now(),
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $artifactId = (string) Str::ulid();
    DB::table('artifacts')->insert([
        'id' => $artifactId,
        'project_id' => $projectId,
        'artifact_type' => 'file_inventory',
        'storage_path' => 'artifacts/test.json',
        'sha256' => str_repeat('a', 64),
        'size_bytes' => 1024,
        'mime_type' => 'application/json',
        'schema_version' => '1.0',
        'status' => 'completed',
        'producer' => 'plugin',
        'metadata' => json_encode(['dimensions' => ['width' => 100]]),
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $repository = Repository::find($repoId);
    $apiToken = ApiToken::find($tokenId);
    $artifact = Artifact::find($artifactId);

    expect($repository->protected_paths)->toBe(['path1']);
    expect($repository->excluded_paths)->toBe(['path2']);
    expect($repository->stack_hints)->toBe(['php', 'laravel']);
    expect($apiToken->scopes)->toBe(['read', 'write']);
    expect($artifact->metadata)->toBe(['dimensions' => ['width' => 100]]);
});

it('casts boolean and datetime columns correctly', function () {
    DB::table('users')->insert([
        'id' => 1,
        'name' => 'Test User',
        'email' => 'test@example.com',
        'password' => bcrypt('password'),
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $projectId = (string) Str::ulid();
    DB::table('projects')->insert([
        'id' => $projectId,
        'name' => 'Test Project',
        'slug' => 'test-project',
        'status' => 'active',
        'created_by_user_id' => 1,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $repoId = (string) Str::ulid();
    DB::table('repositories')->insert([
        'id' => $repoId,
        'project_id' => $projectId,
        'name' => 'Test Repo',
        'slug' => 'test-repo',
        'local_only' => true,
        'graph_enabled' => false,
        'protected_paths' => json_encode([]),
        'excluded_paths' => json_encode([]),
        'stack_hints' => json_encode([]),
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $deviceId = (string) Str::ulid();
    $now = now();

    DB::table('devices')->insert([
        'id' => $deviceId,
        'user_id' => 1,
        'name' => 'Test Device',
        'fingerprint_hash' => 'abc123',
        'platform_os' => 'linux',
        'platform_arch' => 'amd64',
        'plugin_version' => '1.0.0',
        'status' => 'active',
        'last_seen_at' => $now,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $repository = Repository::find($repoId);
    $device = Device::find($deviceId);

    expect($repository->local_only)->toBeTrue();
    expect($repository->graph_enabled)->toBeFalse();
    expect($device->last_seen_at)->toBeInstanceOf(\Illuminate\Support\Carbon::class);
});
