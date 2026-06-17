<?php

require __DIR__.'/../backend/vendor/autoload.php';
$app = require __DIR__.'/../backend/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

config(['queue.default' => 'database']);

$userId = DB::table('users')->where('email', 'admin@example.com')->value('id');
$projectId = DB::table('projects')->where('slug', 'demo-project')->value('id');
$repositoryId = DB::table('repositories')->where('slug', 'demo-repository')->value('id');
$now = now();
$deviceId = (string) Str::ulid();
$workspaceId = (string) Str::ulid();
$runId = (string) Str::ulid();
$artifactId = (string) Str::ulid();
$snapshotId = (string) Str::ulid();
$importId = (string) Str::ulid();
$storagePath = "devboard/artifacts/genesis/{$importId}/{$artifactId}/artifact";

DB::table('devices')->insert([
    'id' => $deviceId,
    'user_id' => $userId,
    'name' => 'Fault Harness Device',
    'fingerprint_hash' => 'sha256:fault-harness-device',
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
    'local_root_hash' => 'sha256:fault-harness-workspace',
    'display_path' => '/tmp/fault-harness-workspace',
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

Storage::disk('local')->put($storagePath, json_encode([
    'nodes' => [
        ['id' => 'file:app.py', 'labels' => ['File'], 'properties' => ['path' => 'app.py']],
        ['id' => 'function:health', 'labels' => ['Function'], 'properties' => ['name' => 'health']],
    ],
    'relationships' => [
        ['id' => 'rel_1', 'type' => 'DECLARES', 'source_id' => 'file:app.py', 'target_id' => 'function:health', 'properties' => []],
    ],
], JSON_THROW_ON_ERROR));

DB::table('artifacts')->insert([
    'id' => $artifactId,
    'project_id' => $projectId,
    'repository_id' => $repositoryId,
    'run_id' => $runId,
    'artifact_type' => 'graph_snapshot',
    'storage_path' => $storagePath,
    'sha256' => str_repeat('a', 64),
    'size_bytes' => 1,
    'mime_type' => 'application/json',
    'schema_version' => 'v1',
    'status' => 'stored',
    'producer' => 'devboard-python-plugin',
    'metadata' => '{}',
    'created_at' => $now,
    'updated_at' => $now,
]);

DB::table('snapshots')->insert([
    'id' => $snapshotId,
    'project_id' => $projectId,
    'repository_id' => $repositoryId,
    'local_workspace_id' => $workspaceId,
    'source_type' => 'local_plugin_snapshot',
    'branch' => 'main',
    'base_sha' => 'abc123',
    'head_sha' => 'abc123',
    'dirty_status' => 'clean',
    'file_inventory_artifact_id' => null,
    'graph_snapshot_artifact_id' => $artifactId,
    'created_by_run_id' => $runId,
    'created_at' => $now,
]);

DB::table('genesis_imports')->insert([
    'id' => $importId,
    'project_id' => $projectId,
    'repository_id' => $repositoryId,
    'local_workspace_id' => $workspaceId,
    'run_id' => $runId,
    'status' => 'active',
    'manifest_artifact_id' => null,
    'snapshot_id' => $snapshotId,
    'base_branch' => 'main',
    'base_sha' => 'abc123',
    'head_sha' => 'abc123',
    'started_at' => $now,
    'finished_at' => null,
    'created_at' => $now,
    'updated_at' => $now,
]);

\App\Jobs\ImportGenesisGraphToNeo4j::dispatch($importId);

echo json_encode([
    'import_id' => $importId,
    'run_id' => $runId,
], JSON_THROW_ON_ERROR);
