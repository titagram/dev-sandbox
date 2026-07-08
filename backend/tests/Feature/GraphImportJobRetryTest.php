<?php

use App\Jobs\ImportGenesisGraphToNeo4j;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(\Database\Seeders\DevBoardSeeder::class);
});

it('declares bounded retries and backoff for graph imports', function () {
    $job = new ImportGenesisGraphToNeo4j((string) Str::ulid());

    expect($job->tries)->toBe(3);
    expect($job->maxExceptions)->toBe(3);
    expect($job->backoff())->toBe([10, 60, 300]);
});

it('marks the genesis import failed when graph import retries are exhausted', function () {
    $context = createRetryImportContext();
    $job = new ImportGenesisGraphToNeo4j($context['import_id']);

    $job->failed(new RuntimeException('neo4j unavailable'));

    expect(DB::table('genesis_imports')->where('id', $context['import_id'])->value('status'))->toBe('failed');
    expect(DB::table('run_events')
        ->where('run_id', $context['run_id'])
        ->where('event_type', 'graph.import_failed')
        ->where('severity', 'error')
        ->exists())->toBeTrue();
});

/**
 * @return array{import_id: string, run_id: string}
 */
function createRetryImportContext(): array
{
    $userId = DB::table('users')->where('email', 'admin@example.com')->value('id');
    $projectId = DB::table('projects')->where('slug', 'demo-project')->value('id');
    $repositoryId = DB::table('repositories')->where('slug', 'demo-repository')->value('id');
    $deviceId = (string) Str::ulid();
    $workspaceId = (string) Str::ulid();
    $runId = (string) Str::ulid();
    $importId = (string) Str::ulid();
    $now = now();

    DB::table('devices')->insert([
        'id' => $deviceId,
        'user_id' => $userId,
        'name' => 'Retry Device',
        'fingerprint_hash' => 'sha256:retry-device',
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
        'local_root_hash' => 'sha256:retry-workspace',
        'display_path' => '/tmp/retry-workspace',
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

    DB::table('genesis_imports')->insert([
        'id' => $importId,
        'project_id' => $projectId,
        'repository_id' => $repositoryId,
        'local_workspace_id' => $workspaceId,
        'run_id' => $runId,
        'status' => 'importing',
        'manifest_artifact_id' => null,
        'snapshot_id' => null,
        'base_branch' => 'main',
        'base_sha' => 'abc123',
        'head_sha' => 'abc123',
        'started_at' => $now,
        'finished_at' => null,
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    return [
        'import_id' => $importId,
        'run_id' => $runId,
    ];
}
