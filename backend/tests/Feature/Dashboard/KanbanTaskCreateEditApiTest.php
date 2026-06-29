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
