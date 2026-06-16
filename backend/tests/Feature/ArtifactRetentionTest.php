<?php

use App\Services\ArtifactRetentionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

beforeEach(function () {
    Storage::fake('local');
    $this->seed(\Database\Seeders\DevBoardSeeder::class);
});

it('purges old finalized artifact contents and writes an audit record', function () {
    $artifact = createRetentionArtifact([
        'status' => 'imported',
        'updated_at' => now()->subDays(45),
    ]);

    $result = app(ArtifactRetentionService::class)->purgeOlderThan(30);

    expect($result)->toMatchArray([
        'scanned' => 1,
        'purged' => 1,
        'skipped' => 0,
        'failed' => 0,
    ]);

    Storage::disk('local')->assertMissing($artifact['storage_path']);
    expect(DB::table('artifacts')->where('id', $artifact['id'])->value('status'))->toBe('purged');
    expect(DB::table('audit_logs')
        ->where('action', 'artifact.purged')
        ->where('target_type', 'artifact')
        ->where('target_id', $artifact['id'])
        ->exists())->toBeTrue();
});

it('does not purge artifacts pinned by the current workspace snapshot', function () {
    $artifact = createRetentionArtifact([
        'status' => 'imported',
        'updated_at' => now()->subDays(45),
    ]);
    createCurrentSnapshotForArtifact($artifact);

    $result = app(ArtifactRetentionService::class)->purgeOlderThan(30);

    expect($result)->toMatchArray([
        'scanned' => 1,
        'purged' => 0,
        'skipped' => 1,
        'failed' => 0,
    ]);

    Storage::disk('local')->assertExists($artifact['storage_path']);
    expect(DB::table('artifacts')->where('id', $artifact['id'])->value('status'))->toBe('imported');
});

it('exposes an artisan command for dry-run artifact retention', function () {
    $artifact = createRetentionArtifact([
        'status' => 'validated',
        'updated_at' => now()->subDays(45),
    ]);

    $exitCode = Artisan::call('devboard:artifacts-retain', [
        '--days' => 30,
        '--dry-run' => true,
    ]);

    expect($exitCode)->toBe(0);
    expect(Artisan::output())->toContain('Would purge 1 artifact');
    Storage::disk('local')->assertExists($artifact['storage_path']);
    expect(DB::table('artifacts')->where('id', $artifact['id'])->value('status'))->toBe('validated');
});

/**
 * @param array<string, mixed> $overrides
 * @return array{id: string, project_id: string, repository_id: string, run_id: string, storage_path: string}
 */
function createRetentionArtifact(array $overrides = []): array
{
    $projectId = DB::table('projects')->where('slug', 'demo-project')->value('id');
    $repositoryId = DB::table('repositories')->where('slug', 'demo-repository')->value('id');
    $userId = DB::table('users')->where('email', 'admin@example.com')->value('id');
    $deviceId = (string) Str::ulid();
    $runId = (string) Str::ulid();
    $artifactId = (string) Str::ulid();
    $storagePath = "devboard/artifacts/retention/{$artifactId}.json";
    $now = now();

    DB::table('devices')->insert([
        'id' => $deviceId,
        'user_id' => $userId,
        'name' => 'Retention Artifact Device',
        'fingerprint_hash' => 'sha256:retention-artifact-device-'.$artifactId,
        'platform_os' => 'linux',
        'platform_arch' => 'amd64',
        'plugin_version' => '0.1.0',
        'last_seen_at' => $now,
        'status' => 'active',
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    DB::table('runs')->insert([
        'id' => $runId,
        'project_id' => $projectId,
        'repository_id' => $repositoryId,
        'local_workspace_id' => null,
        'task_id' => null,
        'device_id' => $deviceId,
        'started_by_user_id' => $userId,
        'runtime_profile' => 'agent_plugin',
        'status' => 'finished',
        'branch' => 'main',
        'base_branch' => 'main',
        'base_sha' => 'abc123',
        'head_sha' => 'abc123',
        'summary' => null,
        'risk_level' => 'low',
        'started_at' => $now->copy()->subDays(46),
        'finished_at' => $now->copy()->subDays(45),
        'created_at' => $now->copy()->subDays(46),
        'updated_at' => $now->copy()->subDays(45),
    ]);

    Storage::disk('local')->put($storagePath, '{"nodes":[],"relationships":[]}');

    DB::table('artifacts')->insert(array_merge([
        'id' => $artifactId,
        'project_id' => $projectId,
        'repository_id' => $repositoryId,
        'run_id' => $runId,
        'artifact_type' => 'graph_snapshot',
        'storage_path' => $storagePath,
        'sha256' => hash('sha256', '{"nodes":[],"relationships":[]}'),
        'size_bytes' => 30,
        'mime_type' => 'application/json',
        'schema_version' => 'v1',
        'status' => 'imported',
        'producer' => 'devboard-python-plugin',
        'metadata' => '{}',
        'created_at' => $now->copy()->subDays(46),
        'updated_at' => $now->copy()->subDays(45),
    ], $overrides));

    return [
        'id' => $artifactId,
        'project_id' => $projectId,
        'repository_id' => $repositoryId,
        'run_id' => $runId,
        'storage_path' => $storagePath,
    ];
}

/**
 * @param array{id: string, project_id: string, repository_id: string, run_id: string} $artifact
 */
function createCurrentSnapshotForArtifact(array $artifact): void
{
    $userId = DB::table('users')->where('email', 'admin@example.com')->value('id');
    $deviceId = (string) Str::ulid();
    $workspaceId = (string) Str::ulid();
    $snapshotId = (string) Str::ulid();
    $now = now();

    DB::table('devices')->insert([
        'id' => $deviceId,
        'user_id' => $userId,
        'name' => 'Retention Device',
        'fingerprint_hash' => 'sha256:retention-device',
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
        'repository_id' => $artifact['repository_id'],
        'device_id' => $deviceId,
        'local_root_hash' => 'sha256:retention-workspace',
        'display_path' => '/tmp/retention-workspace',
        'current_branch' => 'main',
        'last_head_sha' => 'abc123',
        'dirty_status' => 'clean',
        'last_snapshot_id' => $snapshotId,
        'last_seen_at' => $now,
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    DB::table('snapshots')->insert([
        'id' => $snapshotId,
        'project_id' => $artifact['project_id'],
        'repository_id' => $artifact['repository_id'],
        'local_workspace_id' => $workspaceId,
        'source_type' => 'local_plugin_snapshot',
        'branch' => 'main',
        'base_sha' => 'abc123',
        'head_sha' => 'abc123',
        'dirty_status' => 'clean',
        'file_inventory_artifact_id' => null,
        'graph_snapshot_artifact_id' => $artifact['id'],
        'created_by_run_id' => $artifact['run_id'],
        'created_at' => $now,
    ]);
}
