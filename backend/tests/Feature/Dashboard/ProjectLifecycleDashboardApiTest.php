<?php

use App\Models\User;
use Database\Seeders\DevBoardSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

beforeEach(function () {
    Storage::fake('local');
    $this->seed(DevBoardSeeder::class);
});

it('lets admin and pm archive delete and restore projects with audit records', function () {
    $admin = projectLifecycleUserWithRole('Admin');
    $pm = projectLifecycleUserWithRole('PM');
    $projectId = projectLifecycleCreateProject('Lifecycle Project', 'lifecycle-project');

    $this->actingAs($pm)
        ->postJson("/api/dashboard/projects/{$projectId}/archive", ['reason' => 'Done for now.'])
        ->assertOk()
        ->assertJsonPath('id', $projectId)
        ->assertJsonPath('status', 'archived')
        ->assertJsonPath('key', 'lifecycle-project');

    expect(DB::table('projects')->where('id', $projectId)->value('status'))->toBe('archived')
        ->and(DB::table('projects')->where('id', $projectId)->value('archived_by_user_id'))->toBe($pm->id)
        ->and(DB::table('audit_logs')->where('target_id', $projectId)->where('action', 'project.archived')->exists())->toBeTrue();

    $this->actingAs($admin)
        ->postJson("/api/dashboard/projects/{$projectId}/restore", ['reason' => 'Active again.'])
        ->assertOk()
        ->assertJsonPath('status', 'active');

    $this->actingAs($pm)
        ->postJson("/api/dashboard/projects/{$projectId}/delete", ['reason' => 'Move to trash.'])
        ->assertOk()
        ->assertJsonPath('status', 'deleted');

    expect(DB::table('projects')->where('id', $projectId)->value('deleted_by_user_id'))->toBe($pm->id)
        ->and(DB::table('audit_logs')->where('target_id', $projectId)->where('action', 'project.deleted')->exists())->toBeTrue();

    $this->actingAs($admin)
        ->postJson("/api/dashboard/projects/{$projectId}/restore", ['reason' => 'Trash restore.'])
        ->assertOk()
        ->assertJsonPath('status', 'active');

    expect(DB::table('projects')->where('id', $projectId)->value('restored_by_user_id'))->toBe($admin->id)
        ->and(DB::table('audit_logs')->where('target_id', $projectId)->where('action', 'project.restored')->count())->toBe(2);
});

it('filters active archived and trash project lists by role', function () {
    $pm = projectLifecycleUserWithRole('PM');
    $developer = projectLifecycleUserWithRole('Developer');
    $active = projectLifecycleCreateProject('Active Project', 'active-project');
    $archived = projectLifecycleCreateProject('Archived Project', 'archived-project', 'archived');
    $deleted = projectLifecycleCreateProject('Trash Project', 'trash-project', 'deleted');

    $activeRows = $this->actingAs($developer)
        ->getJson('/api/dashboard/projects')
        ->assertOk()
        ->json();

    expect(collect($activeRows)->pluck('id')->all())->toContain($active)
        ->and(collect($activeRows)->pluck('id')->all())->not->toContain($archived, $deleted);

    $archivedRows = $this->actingAs($developer)
        ->getJson('/api/dashboard/projects?status=archived')
        ->assertOk()
        ->json();

    expect(collect($archivedRows)->pluck('id')->all())->toContain($archived)
        ->and(collect($archivedRows)->pluck('id')->all())->not->toContain($active, $deleted);

    $this->actingAs($developer)
        ->getJson('/api/dashboard/projects?status=deleted')
        ->assertForbidden();

    $trashRows = $this->actingAs($pm)
        ->getJson('/api/dashboard/projects?status=deleted')
        ->assertOk()
        ->json();

    expect(collect($trashRows)->pluck('id')->all())->toContain($deleted)
        ->and(collect($trashRows)->pluck('id')->all())->not->toContain($active, $archived);
});

it('keeps archived projects readable and blocks deleted project normal reads', function () {
    $pm = projectLifecycleUserWithRole('PM');
    $archived = projectLifecycleCreateProject('Archived Readable', 'archived-readable', 'archived');
    $deleted = projectLifecycleCreateProject('Deleted Hidden', 'deleted-hidden', 'deleted');

    $this->actingAs($pm)
        ->getJson("/api/dashboard/projects/{$archived}")
        ->assertOk()
        ->assertJsonPath('id', $archived)
        ->assertJsonPath('status', 'archived');

    foreach (['kanban', 'runs', 'wiki', 'artifacts'] as $resource) {
        $this->actingAs($pm)
            ->getJson("/api/dashboard/projects/{$archived}/{$resource}")
            ->assertOk();
    }

    $this->actingAs($pm)
        ->getJson("/api/dashboard/projects/{$deleted}")
        ->assertNotFound();

    foreach (['kanban', 'runs', 'wiki', 'artifacts'] as $resource) {
        $this->actingAs($pm)
            ->getJson("/api/dashboard/projects/{$deleted}/{$resource}")
            ->assertNotFound();
    }
});

it('hides deleted project resources from flat dashboard reads', function () {
    $pm = projectLifecycleUserWithRole('PM');
    $active = projectLifecycleCreateProject('Visible Resources', 'visible-resources');
    $deleted = projectLifecycleCreateProject('Hidden Resources', 'hidden-resources', 'deleted');
    $activeTask = projectLifecycleTask($active);
    $deletedTask = projectLifecycleTask($deleted);
    $activeRun = projectLifecycleRun($active, 'finished');
    $deletedRun = projectLifecycleRun($deleted, 'finished');
    $activeArtifact = projectLifecycleArtifact($active, $activeRun);
    $deletedArtifact = projectLifecycleArtifact($deleted, $deletedRun);
    $activeWiki = projectLifecycleWikiPage($active, $activeArtifact);
    $deletedWiki = projectLifecycleWikiPage($deleted, $deletedArtifact);

    $runs = $this->actingAs($pm)
        ->getJson('/api/dashboard/runs')
        ->assertOk()
        ->json();

    expect(collect($runs)->pluck('id')->all())->toContain($activeRun)
        ->and(collect($runs)->pluck('id')->all())->not->toContain($deletedRun);

    $wiki = $this->actingAs($pm)
        ->getJson('/api/dashboard/wiki')
        ->assertOk()
        ->json();

    expect(collect($wiki)->pluck('id')->all())->toContain($activeWiki)
        ->and(collect($wiki)->pluck('id')->all())->not->toContain($deletedWiki);

    $artifacts = $this->actingAs($pm)
        ->getJson('/api/dashboard/artifacts')
        ->assertOk()
        ->json();

    expect(collect($artifacts)->pluck('id')->all())->toContain($activeArtifact)
        ->and(collect($artifacts)->pluck('id')->all())->not->toContain($deletedArtifact);

    $this->actingAs($pm)->getJson("/api/dashboard/tasks/{$deletedTask}")->assertNotFound();
    $this->actingAs($pm)->getJson("/api/dashboard/runs/{$deletedRun}")->assertNotFound();
    $this->actingAs($pm)->getJson("/api/dashboard/wiki/pages/{$deletedWiki}")->assertNotFound();
    $this->actingAs($pm)->getJson("/api/dashboard/runs/{$deletedRun}/artifacts/{$deletedArtifact}/download")->assertNotFound();

    $this->actingAs($pm)->getJson("/api/dashboard/tasks/{$activeTask}")->assertOk();
    $this->actingAs($pm)->getJson("/api/dashboard/runs/{$activeRun}")->assertOk();
    $this->actingAs($pm)->getJson("/api/dashboard/wiki/pages/{$activeWiki}")->assertOk();
    $this->actingAs($pm)->getJson("/api/dashboard/runs/{$activeRun}/artifacts/{$activeArtifact}/download")->assertOk();
});

it('blocks archive and delete while active runs are in progress', function () {
    $pm = projectLifecycleUserWithRole('PM');
    $projectId = projectLifecycleCreateProject('Busy Project', 'busy-project');
    projectLifecycleRun($projectId, 'heartbeat');

    $this->actingAs($pm)
        ->postJson("/api/dashboard/projects/{$projectId}/archive")
        ->assertConflict()
        ->assertJsonPath('error.code', 'project_lifecycle_blocked')
        ->assertJsonPath('error.details.runs', 1);

    $this->actingAs($pm)
        ->postJson("/api/dashboard/projects/{$projectId}/delete")
        ->assertConflict()
        ->assertJsonPath('error.code', 'project_lifecycle_blocked');

    DB::table('runs')->where('project_id', $projectId)->update(['status' => 'finished', 'finished_at' => now()]);

    $this->actingAs($pm)
        ->postJson("/api/dashboard/projects/{$projectId}/archive")
        ->assertOk()
        ->assertJsonPath('status', 'archived');
});

it('blocks archive and delete while active uploads are in progress', function () {
    $pm = projectLifecycleUserWithRole('PM');
    $projectId = projectLifecycleCreateProject('Uploading Project', 'uploading-project');
    $genesisId = projectLifecycleUpload($projectId, 'genesis_imports', 'active');

    $this->actingAs($pm)
        ->postJson("/api/dashboard/projects/{$projectId}/archive")
        ->assertConflict()
        ->assertJsonPath('error.code', 'project_lifecycle_blocked')
        ->assertJsonPath('error.details.uploads', 1);

    DB::table('genesis_imports')->where('id', $genesisId)->update(['status' => 'finished', 'finished_at' => now()]);
    projectLifecycleUpload($projectId, 'delta_syncs', 'started');

    $this->actingAs($pm)
        ->postJson("/api/dashboard/projects/{$projectId}/delete")
        ->assertConflict()
        ->assertJsonPath('error.code', 'project_lifecycle_blocked')
        ->assertJsonPath('error.details.uploads', 1);
});

it('blocks lifecycle mutations for developer role and invalid transitions', function () {
    $pm = projectLifecycleUserWithRole('PM');
    $developer = projectLifecycleUserWithRole('Developer');
    $projectId = projectLifecycleCreateProject('Transition Project', 'transition-project');

    $this->actingAs($developer)
        ->postJson("/api/dashboard/projects/{$projectId}/archive")
        ->assertForbidden();

    $this->actingAs($pm)
        ->postJson("/api/dashboard/projects/{$projectId}/restore")
        ->assertConflict()
        ->assertJsonPath('error.code', 'invalid_project_lifecycle_transition');
});

it('reserves project keys while archived or deleted', function () {
    $admin = projectLifecycleUserWithRole('Admin');
    $archived = projectLifecycleCreateProject('Reserved Archive', 'reserved-archive', 'archived');
    $deleted = projectLifecycleCreateProject('Reserved Trash', 'reserved-trash', 'deleted');

    $this->actingAs($admin)
        ->postJson('/api/dashboard/projects', [
            'name' => 'Duplicate Archive',
            'key' => 'reserved-archive',
            'description' => 'Should fail.',
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['key']);

    $this->actingAs($admin)
        ->postJson('/api/dashboard/projects', [
            'name' => 'Duplicate Trash',
            'key' => 'reserved-trash',
            'description' => 'Should fail.',
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['key']);

    expect($archived)->not->toBe($deleted);
});

it('blocks operational dashboard mutations for non-active projects', function (string $status) {
    $admin = projectLifecycleUserWithRole('Admin');
    $pm = projectLifecycleUserWithRole('PM');
    $developer = projectLifecycleUserWithRole('Developer');
    $projectId = projectLifecycleCreateProject(Str::headline($status).' Project', "{$status}-mutation-project", $status);
    $taskId = projectLifecycleTask($projectId);
    $reviewRunId = projectLifecycleRun($projectId, 'failed');
    $retryRunId = projectLifecycleRetryableRun($projectId);

    $this->actingAs($admin)
        ->patchJson("/api/dashboard/projects/{$projectId}", ['description' => 'Blocked edit.'])
        ->assertConflict()
        ->assertJsonPath('error.code', 'project_not_active');

    $this->actingAs($pm)
        ->patchJson("/api/dashboard/tasks/{$taskId}", ['column' => 'done'])
        ->assertConflict()
        ->assertJsonPath('error.code', 'project_not_active');

    $this->actingAs($developer)
        ->postJson("/api/dashboard/runs/{$retryRunId}/retry-import")
        ->assertConflict()
        ->assertJsonPath('error.code', 'project_not_active');

    $this->actingAs($pm)
        ->postJson("/api/dashboard/runs/{$reviewRunId}/review")
        ->assertConflict()
        ->assertJsonPath('error.code', 'project_not_active');
})->with(['archived', 'deleted']);

function projectLifecycleUserWithRole(string $roleName): User
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

function projectLifecycleCreateProject(string $name, string $slug, string $status = 'active'): string
{
    $projectId = (string) Str::ulid();
    $boardId = (string) Str::ulid();
    $adminId = DB::table('users')->where('email', 'admin@example.com')->value('id');
    $now = now();

    $columns = Schema::getColumnListing('projects');
    $row = [
        'id' => $projectId,
        'name' => $name,
        'slug' => $slug,
        'description' => "{$name} description.",
        'status' => $status,
        'default_code_exposure_policy' => 'full_code_artifacts',
        'created_by_user_id' => $adminId,
        'created_at' => $now,
        'updated_at' => $now,
    ];

    foreach ([
        'archived_at',
        'archived_by_user_id',
        'deleted_at',
        'deleted_by_user_id',
        'restored_at',
        'restored_by_user_id',
    ] as $column) {
        if (in_array($column, $columns, true)) {
            $row[$column] = match ($column) {
                'archived_at' => $status === 'archived' ? $now : null,
                'archived_by_user_id' => $status === 'archived' ? $adminId : null,
                'deleted_at' => $status === 'deleted' ? $now : null,
                'deleted_by_user_id' => $status === 'deleted' ? $adminId : null,
                default => null,
            };
        }
    }

    DB::table('projects')->insert($row);

    DB::table('kanban_boards')->insert([
        'id' => $boardId,
        'project_id' => $projectId,
        'name' => 'Default Board',
        'is_default' => true,
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    foreach (['backlog', 'ready', 'in_progress', 'blocked', 'review', 'done'] as $index => $statusKey) {
        DB::table('kanban_columns')->insert([
            'id' => (string) Str::ulid(),
            'board_id' => $boardId,
            'name' => Str::headline($statusKey),
            'position' => $index + 1,
            'status_key' => $statusKey,
            'wip_limit' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    return $projectId;
}

function projectLifecycleTask(string $projectId): string
{
    $taskId = (string) Str::ulid();
    $columnId = DB::table('kanban_columns')
        ->join('kanban_boards', 'kanban_boards.id', '=', 'kanban_columns.board_id')
        ->where('kanban_boards.project_id', $projectId)
        ->where('kanban_columns.status_key', 'ready')
        ->value('kanban_columns.id');
    $adminId = DB::table('users')->where('email', 'admin@example.com')->value('id');

    DB::table('tasks')->insert([
        'id' => $taskId,
        'project_id' => $projectId,
        'title' => 'Lifecycle task',
        'description' => 'Task for lifecycle mutation block.',
        'status_column_id' => $columnId,
        'priority' => 'normal',
        'risk_level' => 'medium',
        'owner_user_id' => $adminId,
        'created_by_user_id' => $adminId,
        'due_at' => null,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    return $taskId;
}

function projectLifecycleRun(string $projectId, string $status): string
{
    $runId = (string) Str::ulid();
    $repositoryId = projectLifecycleRepository($projectId);
    $deviceId = projectLifecycleDevice();
    $adminId = DB::table('users')->where('email', 'admin@example.com')->value('id');

    DB::table('runs')->insert([
        'id' => $runId,
        'project_id' => $projectId,
        'repository_id' => $repositoryId,
        'local_workspace_id' => null,
        'task_id' => null,
        'device_id' => $deviceId,
        'started_by_user_id' => $adminId,
        'runtime_profile' => 'agent_plugin',
        'status' => $status,
        'branch' => 'main',
        'base_branch' => 'main',
        'base_sha' => 'abc123',
        'head_sha' => 'def456',
        'summary' => 'Lifecycle run.',
        'risk_level' => 'medium',
        'started_at' => now(),
        'finished_at' => in_array($status, ['finished', 'failed', 'aborted'], true) ? now() : null,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    return $runId;
}

function projectLifecycleRetryableRun(string $projectId): string
{
    $runId = projectLifecycleRun($projectId, 'failed');
    $run = DB::table('runs')->where('id', $runId)->first();
    $workspaceId = projectLifecycleWorkspace((string) $run->repository_id);

    DB::table('genesis_imports')->insert([
        'id' => (string) Str::ulid(),
        'project_id' => $projectId,
        'repository_id' => $run->repository_id,
        'local_workspace_id' => $workspaceId,
        'run_id' => $runId,
        'status' => 'failed',
        'manifest_artifact_id' => null,
        'snapshot_id' => null,
        'base_branch' => 'main',
        'base_sha' => 'abc123',
        'head_sha' => 'def456',
        'started_at' => now(),
        'finished_at' => now(),
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    return $runId;
}

function projectLifecycleUpload(string $projectId, string $table, string $status): string
{
    $runId = projectLifecycleRun($projectId, 'finished');
    $run = DB::table('runs')->where('id', $runId)->first();
    $workspaceId = projectLifecycleWorkspace((string) $run->repository_id);
    $id = (string) Str::ulid();
    $now = now();

    $row = [
        'id' => $id,
        'project_id' => $projectId,
        'repository_id' => $run->repository_id,
        'local_workspace_id' => $workspaceId,
        'run_id' => $runId,
        'status' => $status,
        'started_at' => $now,
        'finished_at' => in_array($status, ['finished', 'failed', 'aborted', 'imported', 'complete'], true) ? $now : null,
        'created_at' => $now,
        'updated_at' => $now,
    ];

    if ($table === 'genesis_imports') {
        $row += [
            'manifest_artifact_id' => null,
            'snapshot_id' => null,
            'base_branch' => 'main',
            'base_sha' => 'abc123',
            'head_sha' => 'def456',
        ];
    } else {
        $row += [
            'base_snapshot_id' => null,
            'new_snapshot_id' => null,
            'branch' => 'main',
            'base_sha' => 'abc123',
            'head_sha' => 'def456',
            'dirty_status' => 'clean',
            'changed_file_count' => 1,
            'risk_level' => 'medium',
        ];
    }

    DB::table($table)->insert($row);

    return $id;
}

function projectLifecycleArtifact(string $projectId, string $runId): string
{
    $run = DB::table('runs')->where('id', $runId)->first();
    $artifactId = (string) Str::ulid();
    $storagePath = "devboard/test/{$artifactId}.json";
    $payload = json_encode(['artifact_id' => $artifactId], JSON_THROW_ON_ERROR);

    Storage::disk('local')->put($storagePath, $payload);

    DB::table('artifacts')->insert([
        'id' => $artifactId,
        'project_id' => $projectId,
        'repository_id' => $run->repository_id,
        'run_id' => $runId,
        'artifact_type' => 'security_report',
        'storage_path' => $storagePath,
        'sha256' => hash('sha256', $payload),
        'size_bytes' => strlen($payload),
        'mime_type' => 'application/json',
        'schema_version' => 'v1',
        'status' => 'validated',
        'producer' => 'devboard-python-plugin',
        'metadata' => json_encode(['source_type' => 'local_plugin_snapshot'], JSON_THROW_ON_ERROR),
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    return $artifactId;
}

function projectLifecycleWikiPage(string $projectId, string $artifactId): string
{
    $artifact = DB::table('artifacts')->where('id', $artifactId)->first();
    $wikiPageId = (string) Str::ulid();
    $wikiRevisionId = (string) Str::ulid();
    $adminId = DB::table('users')->where('email', 'admin@example.com')->value('id');
    $deviceId = projectLifecycleDevice();
    $now = now();

    DB::table('wiki_pages')->insert([
        'id' => $wikiPageId,
        'project_id' => $projectId,
        'repository_id' => $artifact->repository_id,
        'slug' => 'technical/'.Str::lower((string) $wikiPageId),
        'title' => 'Lifecycle Wiki',
        'page_type' => 'technical',
        'current_revision_id' => null,
        'source_status' => 'verified_from_code',
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    DB::table('wiki_revisions')->insert([
        'id' => $wikiRevisionId,
        'wiki_page_id' => $wikiPageId,
        'author_user_id' => $adminId,
        'author_device_id' => $deviceId,
        'producer' => 'devboard-python-plugin',
        'source_type' => 'local_analyzer',
        'source_status' => 'verified_from_code',
        'content_markdown' => '# Lifecycle Wiki',
        'evidence_refs' => json_encode([
            ['type' => 'artifact', 'artifact_id' => $artifactId],
        ], JSON_THROW_ON_ERROR),
        'created_at' => $now,
    ]);

    DB::table('wiki_pages')->where('id', $wikiPageId)->update([
        'current_revision_id' => $wikiRevisionId,
        'updated_at' => $now,
    ]);

    return $wikiPageId;
}

function projectLifecycleRepository(string $projectId): string
{
    $repositoryId = DB::table('repositories')->where('project_id', $projectId)->value('id');

    if ($repositoryId) {
        return (string) $repositoryId;
    }

    $repositoryId = (string) Str::ulid();
    DB::table('repositories')->insert([
        'id' => $repositoryId,
        'project_id' => $projectId,
        'name' => 'lifecycle-repository',
        'slug' => 'lifecycle-repository',
        'default_branch' => 'main',
        'local_only' => true,
        'code_exposure_policy' => 'full_code_artifacts',
        'protected_paths' => json_encode([], JSON_THROW_ON_ERROR),
        'excluded_paths' => json_encode([], JSON_THROW_ON_ERROR),
        'stack_hints' => json_encode(['php'], JSON_THROW_ON_ERROR),
        'graph_enabled' => true,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    return $repositoryId;
}

function projectLifecycleDevice(): string
{
    $deviceId = (string) Str::ulid();
    $adminId = DB::table('users')->where('email', 'admin@example.com')->value('id');

    DB::table('devices')->insert([
        'id' => $deviceId,
        'user_id' => $adminId,
        'name' => 'Lifecycle Device',
        'fingerprint_hash' => 'sha256:'.$deviceId,
        'platform_os' => 'linux',
        'platform_arch' => 'amd64',
        'plugin_version' => '0.9.5',
        'last_seen_at' => now(),
        'status' => 'active',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    return $deviceId;
}

function projectLifecycleWorkspace(string $repositoryId): string
{
    $workspaceId = (string) Str::ulid();
    $deviceId = projectLifecycleDevice();

    DB::table('local_workspaces')->insert([
        'id' => $workspaceId,
        'repository_id' => $repositoryId,
        'device_id' => $deviceId,
        'local_root_hash' => 'sha256:'.$workspaceId,
        'display_path' => "/workspace/{$repositoryId}",
        'current_branch' => 'main',
        'last_head_sha' => 'def456',
        'dirty_status' => 'clean',
        'last_snapshot_id' => null,
        'last_seen_at' => now(),
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    return $workspaceId;
}
