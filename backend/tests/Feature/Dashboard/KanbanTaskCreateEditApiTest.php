<?php

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(\Database\Seeders\DevBoardSeeder::class);
});

it('lets a pm create a project-scoped task with acceptance criteria and repository scope', function () {
    $pm = kanbanTaskCreateEditApiUserWithRole('PM');
    $owner = User::factory()->create(['status' => 'active']);
    $projectId = (string) DB::table('projects')->where('slug', 'demo-project')->value('id');
    $repositoryId = (string) DB::table('repositories')->where('project_id', $projectId)->value('id');

    $response = $this->actingAs($pm)->postJson("/api/dashboard/projects/{$projectId}/tasks", [
        'title' => 'Fix employee table pagination',
        'description' => 'The employee table resets to page one after changing filters.',
        'priority' => 'high',
        'risk' => 'medium',
        'owner_user_id' => $owner->id,
        'repository_ids' => [$repositoryId],
        'acceptance_criteria' => [
            'Filtering keeps the selected page when the result count still supports it.',
            'Filtering returns to page one when the selected page no longer exists.',
        ],
    ]);

    $taskId = $response->assertCreated()
        ->assertJsonPath('title', 'Fix employee table pagination')
        ->assertJsonPath('project_id', $projectId)
        ->assertJsonPath('risk', 'medium')
        ->assertJsonPath('owner', $owner->name)
        ->assertJsonPath('acceptance_criteria.0', 'Filtering keeps the selected page when the result count still supports it.')
        ->assertJsonPath('repositories.0.id', $repositoryId)
        ->json('id');

    expect(DB::table('tasks')->where('id', $taskId)->value('priority'))->toBe('high')
        ->and(DB::table('tasks')->where('id', $taskId)->value('owner_user_id'))->toBe($owner->id)
        ->and(DB::table('repository_task')->where('task_id', $taskId)->pluck('repository_id')->all())->toBe([$repositoryId]);

    $this->actingAs($pm)
        ->getJson("/api/dashboard/tasks/{$taskId}")
        ->assertOk()
        ->assertJsonPath('acceptance_criteria.1', 'Filtering returns to page one when the selected page no longer exists.')
        ->assertJsonPath('repositories.0.name', 'demo-repository');
});


it('queues local agent work when explicitly requested from a kanban task', function () {
    $pm = kanbanTaskCreateEditApiUserWithRole('PM');
    $projectId = (string) DB::table('projects')->where('slug', 'demo-project')->value('id');
    $repositoryId = (string) DB::table('repositories')->where('project_id', $projectId)->value('id');

    $taskId = $this->actingAs($pm)
        ->postJson("/api/dashboard/projects/{$projectId}/tasks", [
            'title' => 'Diagnose intermittent checkout failure',
            'description' => 'Checkout sometimes returns HTTP 500 after coupon validation.',
            'priority' => 'high',
            'risk' => 'high',
            'repository_ids' => [$repositoryId],
            'acceptance_criteria' => ['Root cause is identified with evidence refs.'],
            'assign_to_local_agent' => true,
        ])
        ->assertCreated()
        ->json('id');

    $workItem = DB::table('agent_work_items')->where('task_id', $taskId)->first();
    $payload = json_decode((string) $workItem->payload, true, flags: JSON_THROW_ON_ERROR);

    expect($workItem)->not->toBeNull()
        ->and($workItem->assigned_agent_key)->toBe('local_agent')
        ->and($workItem->status)->toBe('queued')
        ->and($workItem->repository_id)->toBe($repositoryId)
        ->and($workItem->requires_memory_entry)->toBe(1)
        ->and($payload['schema'])->toBe('hades.kanban_task_work.v1')
        ->and($payload['task_id'])->toBe($taskId)
        ->and($payload['task_type'])->toBe('bug')
        ->and($payload['normalized_problem'])->toContain('Checkout sometimes returns HTTP 500')
        ->and($payload['clarification_status'])->toBe('ready')
        ->and($payload['ready_for_agent_work'])->toBeTrue()
        ->and($payload['project_awareness_required'])->toBeTrue()
        ->and($payload['source_access_policy']['mode'])->toBe('source_free_first')
        ->and($payload['bug_intake']['status'])->toBe('missing_workspace_binding')
        ->and($payload['acceptance_criteria'][0])->toBe('Root cause is identified with evidence refs.')
        ->and(DB::table('agent_work_item_events')->where('agent_work_item_id', $workItem->id)->where('event_type', 'queued_from_kanban_task')->exists())->toBeTrue();

    $this->actingAs($pm)
        ->patchJson("/api/dashboard/tasks/{$taskId}", ['assign_to_local_agent' => true])
        ->assertOk();

    expect(DB::table('agent_work_items')->where('task_id', $taskId)->where('assigned_agent_key', 'local_agent')->count())
        ->toBe(1);
});

it('does not queue ambiguous local agent work before clarification', function () {
    $pm = kanbanTaskCreateEditApiUserWithRole('PM');
    $projectId = (string) DB::table('projects')->where('slug', 'demo-project')->value('id');

    $taskId = $this->actingAs($pm)
        ->postJson("/api/dashboard/projects/{$projectId}/tasks", [
            'title' => 'Fix thing',
            'assign_to_local_agent' => true,
        ])
        ->assertCreated()
        ->json('id');

    expect(DB::table('agent_work_items')->where('task_id', $taskId)->count())->toBe(0);
});

it('creates idempotent Hades bug intake when a ready bug task is assigned locally', function () {
    $pm = kanbanTaskCreateEditApiUserWithRole('PM');
    $projectId = (string) DB::table('projects')->where('slug', 'demo-project')->value('id');
    $repositoryId = (string) DB::table('repositories')->where('project_id', $projectId)->value('id');
    $binding = kanbanTaskCreateEditApiHadesWorkspaceBinding($projectId);

    $taskId = $this->actingAs($pm)
        ->postJson("/api/dashboard/projects/{$projectId}/tasks", [
            'title' => 'Fix invoice export HTTP 500',
            'description' => 'Invoice export returns HTTP 500 after the totals service receives a missing customer tax code.',
            'priority' => 'urgent',
            'risk' => 'critical',
            'repository_ids' => [$repositoryId],
            'acceptance_criteria' => ['Root cause and regression check are documented with Hades evidence refs.'],
            'assign_to_local_agent' => true,
        ])
        ->assertCreated()
        ->json('id');

    $workItem = DB::table('agent_work_items')->where('task_id', $taskId)->first();
    $payload = json_decode((string) $workItem->payload, true, flags: JSON_THROW_ON_ERROR);
    $bugReportId = $payload['bug_report_id'];
    $evidenceId = $payload['evidence_refs'][0]['id'];

    expect($payload['workspace_binding_id'])->toBe($binding['workspace_binding_id'])
        ->and($payload['bug_intake']['status'])->toBe('created')
        ->and($payload['bug_report_id'])->toBeString()
        ->and($payload['evidence_refs'][0]['type'])->toBe('bug_evidence')
        ->and(DB::table('hades_bug_reports')->where('id', $bugReportId)->where('workspace_binding_id', $binding['workspace_binding_id'])->where('severity', 'critical')->exists())->toBeTrue()
        ->and(DB::table('hades_bug_evidence_items')->where('id', $evidenceId)->where('bug_report_id', $bugReportId)->where('source', 'dashboard_kanban_task:'.$taskId)->exists())->toBeTrue()
        ->and(DB::table('hades_search_documents')->where('source_table', 'hades_bug_evidence_items')->where('source_id', $evidenceId)->exists())->toBeTrue();

    $this->actingAs($pm)
        ->patchJson("/api/dashboard/tasks/{$taskId}", ['assign_to_local_agent' => true])
        ->assertOk();

    expect(DB::table('agent_work_items')->where('task_id', $taskId)->where('assigned_agent_key', 'local_agent')->count())->toBe(1)
        ->and(DB::table('hades_bug_reports')->where('project_id', $projectId)->count())->toBe(1)
        ->and(DB::table('hades_bug_evidence_items')->where('project_id', $projectId)->count())->toBe(1);
});

it('edits task detail fields without moving the card when column is omitted', function () {
    $pm = kanbanTaskCreateEditApiUserWithRole('PM');
    $owner = User::factory()->create(['status' => 'active']);
    $projectId = (string) DB::table('projects')->where('slug', 'demo-project')->value('id');
    $repositoryId = (string) DB::table('repositories')->where('project_id', $projectId)->value('id');
    $taskId = kanbanTaskCreateEditApiTask($projectId, 'ready');
    $originalColumnId = DB::table('tasks')->where('id', $taskId)->value('status_column_id');

    $this->actingAs($pm)
        ->patchJson("/api/dashboard/tasks/{$taskId}", [
            'title' => 'Clarified employee table bug',
            'description' => 'Pagination state must be stable while filters are changed.',
            'priority' => 'normal',
            'risk' => 'low',
            'owner_user_id' => $owner->id,
            'repository_ids' => [$repositoryId],
            'acceptance_criteria' => ['Stable pagination is verified manually.'],
        ])
        ->assertOk()
        ->assertJsonPath('title', 'Clarified employee table bug')
        ->assertJsonPath('description', 'Pagination state must be stable while filters are changed.')
        ->assertJsonPath('risk', 'low')
        ->assertJsonPath('owner', $owner->name)
        ->assertJsonPath('acceptance_criteria.0', 'Stable pagination is verified manually.')
        ->assertJsonPath('repositories.0.id', $repositoryId);

    $task = DB::table('tasks')->where('id', $taskId)->first();

    expect($task->status_column_id)->toBe($originalColumnId)
        ->and($task->priority)->toBe('normal')
        ->and($task->owner_user_id)->toBe($owner->id)
        ->and(DB::table('repository_task')->where('task_id', $taskId)->pluck('repository_id')->all())->toBe([$repositoryId]);
});

it('rejects repository scope from another project', function () {
    $pm = kanbanTaskCreateEditApiUserWithRole('PM');
    $projectId = (string) DB::table('projects')->where('slug', 'demo-project')->value('id');
    $otherProject = kanbanTaskCreateEditApiProject('Other Project', 'other-project');

    $this->actingAs($pm)
        ->postJson("/api/dashboard/projects/{$projectId}/tasks", [
            'title' => 'Cross project scope must fail',
            'repository_ids' => [$otherProject['repository_id']],
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['repository_ids.0']);
});

it('moves a task to the matching column on its own project board', function () {
    $pm = kanbanTaskCreateEditApiUserWithRole('PM');
    $project = kanbanTaskCreateEditApiProject('Secondary Kanban Project', 'secondary-kanban-project');
    $taskId = kanbanTaskCreateEditApiTask($project['project_id'], 'blocked');

    $this->actingAs($pm)
        ->patchJson("/api/dashboard/tasks/{$taskId}", ['column' => 'ready'])
        ->assertOk()
        ->assertJsonPath('column', 'ready');

    expect(DB::table('tasks')->where('id', $taskId)->value('status_column_id'))->toBe($project['columns']['ready']);
});

it('moves a task to the visible default board column when another same-project board has the same status', function () {
    $pm = kanbanTaskCreateEditApiUserWithRole('PM');
    $project = kanbanTaskCreateEditApiProjectWithNonDefaultBoardFirst();
    $taskId = kanbanTaskCreateEditApiTask($project['project_id'], 'blocked');

    $kanban = $this->actingAs($pm)
        ->patchJson("/api/dashboard/tasks/{$taskId}", ['column' => 'ready'])
        ->assertOk()
        ->assertJsonPath('column', 'ready')
        ->json();

    expect(DB::table('tasks')->where('id', $taskId)->value('status_column_id'))->toBe($project['default_columns']['ready'])
        ->and($kanban['id'])->toBe($taskId);

    $board = $this->actingAs($pm)
        ->getJson("/api/dashboard/projects/{$project['project_id']}/kanban")
        ->assertOk()
        ->json();

    expect($board['tasks'])->toHaveKey($taskId);
});

it('updates task timestamp when only repository scope changes', function () {
    $pm = kanbanTaskCreateEditApiUserWithRole('PM');
    $projectId = (string) DB::table('projects')->where('slug', 'demo-project')->value('id');
    $repositoryId = (string) DB::table('repositories')->where('project_id', $projectId)->value('id');
    $taskId = kanbanTaskCreateEditApiTask($projectId, 'ready');
    $oldUpdatedAt = now()->subHour()->toDateTimeString();

    DB::table('tasks')->where('id', $taskId)->update(['updated_at' => $oldUpdatedAt]);

    $this->actingAs($pm)
        ->patchJson("/api/dashboard/tasks/{$taskId}", [
            'repository_ids' => [$repositoryId],
        ])
        ->assertOk()
        ->assertJsonPath('repositories.0.id', $repositoryId);

    expect(DB::table('tasks')->where('id', $taskId)->value('updated_at'))->not->toBe($oldUpdatedAt);
});

it('excludes malformed cross-project repository pivots from task reader output', function () {
    $pm = kanbanTaskCreateEditApiUserWithRole('PM');
    $projectId = (string) DB::table('projects')->where('slug', 'demo-project')->value('id');
    $repositoryId = (string) DB::table('repositories')->where('project_id', $projectId)->value('id');
    $otherProject = kanbanTaskCreateEditApiProject('Malformed Pivot Project', 'malformed-pivot-project');
    $taskId = kanbanTaskCreateEditApiTask($projectId, 'ready');
    $now = now();

    DB::table('repository_task')->insert([
        [
            'id' => (string) Str::ulid(),
            'task_id' => $taskId,
            'repository_id' => $repositoryId,
            'created_at' => $now,
            'updated_at' => $now,
        ],
        [
            'id' => (string) Str::ulid(),
            'task_id' => $taskId,
            'repository_id' => $otherProject['repository_id'],
            'created_at' => $now,
            'updated_at' => $now,
        ],
    ]);

    $repositories = $this->actingAs($pm)
        ->getJson("/api/dashboard/tasks/{$taskId}")
        ->assertOk()
        ->json('repositories');

    expect($repositories)->toHaveCount(1)
        ->and($repositories[0]['id'])->toBe($repositoryId);
});

function kanbanTaskCreateEditApiUserWithRole(string $roleName): User
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
 * @return array{project_id: string, repository_id: string, columns: array<string, string>}
 */
function kanbanTaskCreateEditApiProject(string $name, string $slug): array
{
    $projectId = (string) Str::ulid();
    $repositoryId = (string) Str::ulid();
    $boardId = (string) Str::ulid();
    $adminId = (int) DB::table('users')->where('email', 'admin@example.com')->value('id');
    $now = now();

    DB::table('projects')->insert([
        'id' => $projectId,
        'name' => $name,
        'slug' => $slug,
        'description' => "{$name} description.",
        'status' => 'active',
        'default_code_exposure_policy' => 'full_code_artifacts',
        'created_by_user_id' => $adminId,
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    DB::table('repositories')->insert([
        'id' => $repositoryId,
        'project_id' => $projectId,
        'name' => "{$slug}-repository",
        'slug' => "{$slug}-repository",
        'default_branch' => 'main',
        'local_only' => true,
        'code_exposure_policy' => 'full_code_artifacts',
        'protected_paths' => json_encode([], JSON_THROW_ON_ERROR),
        'excluded_paths' => json_encode([], JSON_THROW_ON_ERROR),
        'stack_hints' => json_encode([], JSON_THROW_ON_ERROR),
        'graph_enabled' => true,
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    DB::table('kanban_boards')->insert([
        'id' => $boardId,
        'project_id' => $projectId,
        'name' => 'Default Board',
        'is_default' => true,
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    $columns = [];
    foreach (['backlog', 'ready', 'in_progress', 'blocked', 'review', 'done'] as $position => $statusKey) {
        $columns[$statusKey] = (string) Str::ulid();

        DB::table('kanban_columns')->insert([
            'id' => $columns[$statusKey],
            'board_id' => $boardId,
            'name' => Str::headline($statusKey),
            'position' => $position + 1,
            'status_key' => $statusKey,
            'wip_limit' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    return [
        'project_id' => $projectId,
        'repository_id' => $repositoryId,
        'columns' => $columns,
    ];
}

/**
 * @return array{project_id: string, repository_id: string, default_columns: array<string, string>, other_columns: array<string, string>}
 */
function kanbanTaskCreateEditApiProjectWithNonDefaultBoardFirst(): array
{
    $projectId = (string) Str::ulid();
    $repositoryId = (string) Str::ulid();
    $otherBoardId = (string) Str::ulid();
    $defaultBoardId = (string) Str::ulid();
    $adminId = (int) DB::table('users')->where('email', 'admin@example.com')->value('id');
    $now = now();

    DB::table('projects')->insert([
        'id' => $projectId,
        'name' => 'Board Collision Project',
        'slug' => 'board-collision-project',
        'description' => 'Project with multiple boards sharing status keys.',
        'status' => 'active',
        'default_code_exposure_policy' => 'full_code_artifacts',
        'created_by_user_id' => $adminId,
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    DB::table('repositories')->insert([
        'id' => $repositoryId,
        'project_id' => $projectId,
        'name' => 'board-collision-repository',
        'slug' => 'board-collision-repository',
        'default_branch' => 'main',
        'local_only' => true,
        'code_exposure_policy' => 'full_code_artifacts',
        'protected_paths' => json_encode([], JSON_THROW_ON_ERROR),
        'excluded_paths' => json_encode([], JSON_THROW_ON_ERROR),
        'stack_hints' => json_encode([], JSON_THROW_ON_ERROR),
        'graph_enabled' => true,
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    DB::table('kanban_boards')->insert([
        [
            'id' => $otherBoardId,
            'project_id' => $projectId,
            'name' => 'Planning Board',
            'is_default' => false,
            'created_at' => $now,
            'updated_at' => $now,
        ],
        [
            'id' => $defaultBoardId,
            'project_id' => $projectId,
            'name' => 'Default Board',
            'is_default' => true,
            'created_at' => $now,
            'updated_at' => $now,
        ],
    ]);

    $otherColumns = kanbanTaskCreateEditApiInsertColumns($otherBoardId, $now);
    $defaultColumns = kanbanTaskCreateEditApiInsertColumns($defaultBoardId, $now);

    return [
        'project_id' => $projectId,
        'repository_id' => $repositoryId,
        'default_columns' => $defaultColumns,
        'other_columns' => $otherColumns,
    ];
}

function kanbanTaskCreateEditApiTask(string $projectId, string $column): string
{
    $taskId = (string) Str::ulid();
    $adminId = (int) DB::table('users')->where('email', 'admin@example.com')->value('id');
    $columnId = kanbanTaskCreateEditApiColumnId($projectId, $column);
    $now = now();

    DB::table('tasks')->insert([
        'id' => $taskId,
        'project_id' => $projectId,
        'title' => 'Existing kanban task',
        'description' => 'Existing task description.',
        'acceptance_criteria' => json_encode(['Existing acceptance criterion.'], JSON_THROW_ON_ERROR),
        'status_column_id' => $columnId,
        'priority' => 'high',
        'risk_level' => 'medium',
        'owner_user_id' => null,
        'created_by_user_id' => $adminId,
        'due_at' => null,
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    return $taskId;
}

function kanbanTaskCreateEditApiColumnId(string $projectId, string $statusKey): string
{
    return (string) DB::table('kanban_columns')
        ->join('kanban_boards', 'kanban_boards.id', '=', 'kanban_columns.board_id')
        ->where('kanban_boards.project_id', $projectId)
        ->where('kanban_boards.is_default', true)
        ->where('kanban_columns.status_key', $statusKey)
        ->value('kanban_columns.id');
}

/**
 * @return array<string, string>
 */
function kanbanTaskCreateEditApiInsertColumns(string $boardId, mixed $now): array
{
    $columns = [];

    foreach (['backlog', 'ready', 'in_progress', 'blocked', 'review', 'done'] as $position => $statusKey) {
        $columns[$statusKey] = (string) Str::ulid();

        DB::table('kanban_columns')->insert([
            'id' => $columns[$statusKey],
            'board_id' => $boardId,
            'name' => Str::headline($statusKey),
            'position' => $position + 1,
            'status_key' => $statusKey,
            'wip_limit' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    return $columns;
}

/**
 * @return array{backend_agent_id: string, workspace_binding_id: string}
 */
function kanbanTaskCreateEditApiHadesWorkspaceBinding(string $projectId): array
{
    $agentId = (string) Str::ulid();
    $bindingId = (string) Str::ulid();
    $now = now();

    DB::table('hades_agents')->insert([
        'id' => $agentId,
        'project_id' => $projectId,
        'external_agent_id' => 'kanban-test-local-agent-'.Str::lower(Str::random(8)),
        'label' => 'Kanban Test Local Agent',
        'platform' => 'testing',
        'version' => 'test',
        'declared_capabilities' => json_encode(['shared_memory', 'bug_evidence'], JSON_THROW_ON_ERROR),
        'effective_capabilities' => json_encode(['shared_memory', 'bug_evidence'], JSON_THROW_ON_ERROR),
        'last_seen_at' => $now,
        'status' => 'active',
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    DB::table('hades_workspace_bindings')->insert([
        'id' => $bindingId,
        'project_id' => $projectId,
        'hades_agent_id' => $agentId,
        'external_agent_id' => 'kanban-test-local-agent',
        'local_project_id' => null,
        'workspace_fingerprint' => 'wf_kanban_'.Str::lower(Str::random(12)),
        'display_path' => '~/Code/kanban-test',
        'git_remote_display' => 'github.com/acme/kanban-test.git',
        'git_remote_hash' => hash('sha256', 'github.com/acme/kanban-test.git'),
        'head_commit' => str_repeat('a', 40),
        'platform' => 'testing',
        'status' => 'linked',
        'linked_at' => $now,
        'unlinked_at' => null,
        'last_seen_at' => $now,
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    return [
        'backend_agent_id' => $agentId,
        'workspace_binding_id' => $bindingId,
    ];
}
