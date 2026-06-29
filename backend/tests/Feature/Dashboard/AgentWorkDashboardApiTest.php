<?php

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(\Database\Seeders\DevBoardSeeder::class);
});

it('lets a developer create and list local agent work with repository and task scope', function () {
    $developer = agentWorkDashboardApiUserWithRole('Developer');
    $projectId = (string) DB::table('projects')->where('slug', 'demo-project')->value('id');
    $repositoryId = (string) DB::table('repositories')->where('project_id', $projectId)->value('id');
    $taskId = agentWorkDashboardApiTask($projectId);

    $workItem = $this->actingAs($developer)
        ->postJson("/api/dashboard/projects/{$projectId}/agent-work", [
            'assigned_agent_key' => 'local_agent',
            'priority' => 'high',
            'title' => 'Inspect task before implementation',
            'prompt' => 'Read shared memory and report conflicts before changing code.',
            'repository_id' => $repositoryId,
            'task_id' => $taskId,
            'payload' => [
                'request' => 'Preflight sync for this task.',
                'expected_output' => 'Conflicts or a clear ready signal.',
            ],
            'requires_memory_entry' => false,
        ])
        ->assertCreated()
        ->assertJsonStructure([
            'id',
            'project_id',
            'repository_id',
            'task_id',
            'requested_by_user_id',
            'assigned_agent_key',
            'status',
            'priority',
            'title',
            'prompt',
            'payload',
            'requires_memory_entry',
            'result_memory_entry_id',
            'claimed_by_device_id',
            'claimed_at',
            'heartbeat_at',
            'completed_at',
            'failed_at',
            'canceled_at',
            'failure_reason',
            'created_at',
            'updated_at',
        ])
        ->assertJsonPath('project_id', $projectId)
        ->assertJsonPath('repository_id', $repositoryId)
        ->assertJsonPath('task_id', $taskId)
        ->assertJsonPath('requested_by_user_id', $developer->id)
        ->assertJsonPath('assigned_agent_key', 'local_agent')
        ->assertJsonPath('status', 'queued')
        ->assertJsonPath('priority', 'high')
        ->assertJsonPath('payload.request', 'Preflight sync for this task.')
        ->assertJsonPath('requires_memory_entry', false)
        ->json();

    expect(DB::table('agent_work_items')->where('id', $workItem['id'])->value('status'))->toBe('queued')
        ->and(DB::table('agent_work_items')->where('id', $workItem['id'])->value('requested_by_user_id'))->toBe($developer->id)
        ->and(DB::table('agent_work_item_events')->where('agent_work_item_id', $workItem['id'])->where('event_type', 'queued')->exists())->toBeTrue();

    $this->actingAs($developer)
        ->getJson("/api/dashboard/projects/{$projectId}/agent-work")
        ->assertOk()
        ->assertJsonPath('items.0.id', $workItem['id'])
        ->assertJsonPath('items.0.title', 'Inspect task before implementation')
        ->assertJsonPath('items.0.repository_id', $repositoryId)
        ->assertJsonPath('items.0.task_id', $taskId);
});

it('lets pm cancel queued work before the local agent claims it', function () {
    $pm = agentWorkDashboardApiUserWithRole('PM');
    $projectId = (string) DB::table('projects')->where('slug', 'demo-project')->value('id');
    $workItemId = agentWorkDashboardApiWorkItem($projectId);

    $this->actingAs($pm)
        ->postJson("/api/dashboard/agent-work/{$workItemId}/cancel", [
            'message' => 'The task was rewritten.',
        ])
        ->assertOk()
        ->assertJsonPath('id', $workItemId)
        ->assertJsonPath('status', 'canceled');

    expect(DB::table('agent_work_items')->where('id', $workItemId)->value('canceled_at'))->not->toBeNull()
        ->and(DB::table('agent_work_item_events')->where('agent_work_item_id', $workItemId)->where('event_type', 'canceled')->value('message'))
        ->toBe('The task was rewritten.');
});

it('allows sysadmin to read but not create or cancel agent work', function () {
    $sysadmin = agentWorkDashboardApiUserWithRole('Sysadmin');
    $projectId = (string) DB::table('projects')->where('slug', 'demo-project')->value('id');
    $workItemId = agentWorkDashboardApiWorkItem($projectId, ['title' => 'Sysadmin readable work']);

    $this->actingAs($sysadmin)
        ->getJson("/api/dashboard/projects/{$projectId}/agent-work")
        ->assertOk()
        ->assertJsonPath('items.0.id', $workItemId);

    $this->actingAs($sysadmin)
        ->postJson("/api/dashboard/projects/{$projectId}/agent-work", [
            'assigned_agent_key' => 'local_agent',
            'title' => 'Sysadmin cannot create',
            'prompt' => 'Sysadmin has read access only.',
        ])
        ->assertForbidden();

    $this->actingAs($sysadmin)
        ->postJson("/api/dashboard/agent-work/{$workItemId}/cancel")
        ->assertForbidden();

    expect(DB::table('agent_work_items')->where('id', $workItemId)->value('status'))->toBe('queued');
});

it('rejects repository and task references from another project', function () {
    $developer = agentWorkDashboardApiUserWithRole('Developer');
    $projectId = (string) DB::table('projects')->where('slug', 'demo-project')->value('id');
    $other = agentWorkDashboardApiProject('Other Agent Work Project', 'other-agent-work-project');
    $otherTaskId = agentWorkDashboardApiTask($other['project_id']);

    $this->actingAs($developer)
        ->postJson("/api/dashboard/projects/{$projectId}/agent-work", [
            'assigned_agent_key' => 'local_agent',
            'priority' => 'normal',
            'title' => 'Cross project work references',
            'prompt' => 'This payload references another project and must be rejected.',
            'repository_id' => $other['repository_id'],
            'task_id' => $otherTaskId,
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['repository_id', 'task_id']);
});

it('blocks writes to archived and deleted projects', function (string $status) {
    $developer = agentWorkDashboardApiUserWithRole('Developer');
    $project = agentWorkDashboardApiProject(Str::headline($status).' Agent Work Project', "{$status}-agent-work-project", $status);
    $workItemId = agentWorkDashboardApiWorkItem($project['project_id']);

    $this->actingAs($developer)
        ->postJson("/api/dashboard/projects/{$project['project_id']}/agent-work", [
            'assigned_agent_key' => 'local_agent',
            'title' => 'Lifecycle blocked work',
            'prompt' => 'This write should be blocked by lifecycle policy.',
        ])
        ->assertConflict()
        ->assertJsonPath('error.code', 'project_not_active');

    $this->actingAs($developer)
        ->postJson("/api/dashboard/agent-work/{$workItemId}/cancel")
        ->assertConflict()
        ->assertJsonPath('error.code', 'project_not_active');
})->with(['archived', 'deleted']);

it('does not cancel completed work', function () {
    $developer = agentWorkDashboardApiUserWithRole('Developer');
    $projectId = (string) DB::table('projects')->where('slug', 'demo-project')->value('id');
    $workItemId = agentWorkDashboardApiWorkItem($projectId, [
        'status' => 'completed',
        'completed_at' => now(),
    ]);

    $this->actingAs($developer)
        ->postJson("/api/dashboard/agent-work/{$workItemId}/cancel")
        ->assertConflict();

    expect(DB::table('agent_work_items')->where('id', $workItemId)->value('status'))->toBe('completed')
        ->and(DB::table('agent_work_items')->where('id', $workItemId)->value('canceled_at'))->toBeNull()
        ->and(DB::table('agent_work_item_events')->where('agent_work_item_id', $workItemId)->where('event_type', 'canceled')->exists())->toBeFalse();
});

it('does not cancel failed work', function () {
    $developer = agentWorkDashboardApiUserWithRole('Developer');
    $projectId = (string) DB::table('projects')->where('slug', 'demo-project')->value('id');
    $failedAt = now()->subMinute();
    $workItemId = agentWorkDashboardApiWorkItem($projectId, [
        'status' => 'failed',
        'failed_at' => $failedAt,
        'failure_reason' => 'The agent run failed.',
    ]);

    $this->actingAs($developer)
        ->postJson("/api/dashboard/agent-work/{$workItemId}/cancel")
        ->assertConflict();

    expect(DB::table('agent_work_items')->where('id', $workItemId)->value('status'))->toBe('failed')
        ->and(DB::table('agent_work_items')->where('id', $workItemId)->value('failed_at'))->not->toBeNull()
        ->and(DB::table('agent_work_items')->where('id', $workItemId)->value('canceled_at'))->toBeNull()
        ->and(DB::table('agent_work_item_events')->where('agent_work_item_id', $workItemId)->where('event_type', 'canceled')->exists())->toBeFalse();
});

it('does not cancel already canceled work again', function () {
    $developer = agentWorkDashboardApiUserWithRole('Developer');
    $projectId = (string) DB::table('projects')->where('slug', 'demo-project')->value('id');
    $canceledAt = now()->subMinute();
    $workItemId = agentWorkDashboardApiWorkItem($projectId, [
        'status' => 'canceled',
        'canceled_at' => $canceledAt,
    ]);
    $storedCanceledAt = DB::table('agent_work_items')->where('id', $workItemId)->value('canceled_at');

    DB::table('agent_work_item_events')->insert([
        'id' => (string) Str::ulid(),
        'agent_work_item_id' => $workItemId,
        'actor_user_id' => $developer->id,
        'actor_device_id' => null,
        'event_type' => 'canceled',
        'message' => 'Already canceled.',
        'payload' => json_encode([], JSON_THROW_ON_ERROR),
        'created_at' => $canceledAt,
        'updated_at' => $canceledAt,
    ]);

    $this->actingAs($developer)
        ->postJson("/api/dashboard/agent-work/{$workItemId}/cancel", [
            'message' => 'Cancel again.',
        ])
        ->assertConflict();

    expect(DB::table('agent_work_items')->where('id', $workItemId)->value('status'))->toBe('canceled')
        ->and(DB::table('agent_work_items')->where('id', $workItemId)->value('canceled_at'))->toBe($storedCanceledAt)
        ->and(DB::table('agent_work_item_events')->where('agent_work_item_id', $workItemId)->where('event_type', 'canceled')->count())->toBe(1)
        ->and(DB::table('agent_work_item_events')->where('agent_work_item_id', $workItemId)->where('event_type', 'canceled')->value('message'))->toBe('Already canceled.');
});

it('blocks cancellation after work has been claimed', function () {
    $developer = agentWorkDashboardApiUserWithRole('Developer');
    $projectId = (string) DB::table('projects')->where('slug', 'demo-project')->value('id');
    $deviceId = agentWorkDashboardApiDevice();
    $workItemId = agentWorkDashboardApiWorkItem($projectId, [
        'status' => 'claimed',
        'claimed_by_device_id' => $deviceId,
        'claimed_at' => now(),
    ]);

    $this->actingAs($developer)
        ->postJson("/api/dashboard/agent-work/{$workItemId}/cancel")
        ->assertConflict();

    expect(DB::table('agent_work_items')->where('id', $workItemId)->value('status'))->toBe('claimed')
        ->and(DB::table('agent_work_item_events')->where('agent_work_item_id', $workItemId)->where('event_type', 'canceled')->exists())->toBeFalse();
});

it('does not cancel work that becomes claimed after the initial cancel read', function () {
    $developer = agentWorkDashboardApiUserWithRole('Developer');
    $projectId = (string) DB::table('projects')->where('slug', 'demo-project')->value('id');
    $deviceId = agentWorkDashboardApiDevice();
    $workItemId = agentWorkDashboardApiWorkItem($projectId);
    $claimedDuringCancel = false;

    DB::listen(function (Illuminate\Database\Events\QueryExecuted $query) use (&$claimedDuringCancel, $workItemId, $deviceId): void {
        if ($claimedDuringCancel || ! str_contains($query->sql, 'from "agent_work_items"')) {
            return;
        }

        if (($query->bindings[0] ?? null) !== $workItemId) {
            return;
        }

        $claimedDuringCancel = true;

        DB::table('agent_work_items')->where('id', $workItemId)->update([
            'status' => 'claimed',
            'claimed_by_device_id' => $deviceId,
            'claimed_at' => now(),
            'updated_at' => now(),
        ]);
    });

    $this->actingAs($developer)
        ->postJson("/api/dashboard/agent-work/{$workItemId}/cancel")
        ->assertConflict();

    expect($claimedDuringCancel)->toBeTrue()
        ->and(DB::table('agent_work_items')->where('id', $workItemId)->value('status'))->toBe('claimed')
        ->and(DB::table('agent_work_item_events')->where('agent_work_item_id', $workItemId)->where('event_type', 'canceled')->exists())->toBeFalse();
});

it('keeps agent work project scoped', function () {
    $developer = agentWorkDashboardApiUserWithRole('Developer');
    $primaryProjectId = (string) DB::table('projects')->where('slug', 'demo-project')->value('id');
    $secondary = agentWorkDashboardApiProject('Scoped Agent Work Project', 'scoped-agent-work-project');
    $primaryWorkItemId = agentWorkDashboardApiWorkItem($primaryProjectId, ['title' => 'Primary work item']);
    $secondaryWorkItemId = agentWorkDashboardApiWorkItem($secondary['project_id'], ['title' => 'Secondary work item']);

    $primaryItems = $this->actingAs($developer)
        ->getJson("/api/dashboard/projects/{$primaryProjectId}/agent-work")
        ->assertOk()
        ->json('items');

    $secondaryItems = $this->actingAs($developer)
        ->getJson("/api/dashboard/projects/{$secondary['project_id']}/agent-work")
        ->assertOk()
        ->json('items');

    expect(collect($primaryItems)->pluck('id')->all())->toContain($primaryWorkItemId)
        ->and(collect($primaryItems)->pluck('id')->all())->not->toContain($secondaryWorkItemId)
        ->and(collect($secondaryItems)->pluck('id')->all())->toContain($secondaryWorkItemId)
        ->and(collect($secondaryItems)->pluck('id')->all())->not->toContain($primaryWorkItemId);
});

it('orders project work by priority and limits the list', function () {
    $pm = agentWorkDashboardApiUserWithRole('PM');
    $projectId = (string) DB::table('projects')->where('slug', 'demo-project')->value('id');
    $oldLow = agentWorkDashboardApiWorkItem($projectId, [
        'priority' => 'low',
        'title' => 'Old low priority work',
        'created_at' => now()->subHours(3),
        'updated_at' => now()->subHours(3),
    ]);
    $high = agentWorkDashboardApiWorkItem($projectId, [
        'priority' => 'high',
        'title' => 'High priority work',
        'created_at' => now()->subHours(2),
        'updated_at' => now()->subHours(2),
    ]);
    $urgent = agentWorkDashboardApiWorkItem($projectId, [
        'priority' => 'urgent',
        'title' => 'Urgent priority work',
        'created_at' => now()->subHour(),
        'updated_at' => now()->subHour(),
    ]);

    foreach (range(1, 98) as $index) {
        agentWorkDashboardApiWorkItem($projectId, [
            'priority' => 'low',
            'title' => "Low priority work {$index}",
            'created_at' => now()->addMinutes($index),
            'updated_at' => now()->addMinutes($index),
        ]);
    }

    $items = $this->actingAs($pm)
        ->getJson("/api/dashboard/projects/{$projectId}/agent-work")
        ->assertOk()
        ->json('items');

    expect($items)->toHaveCount(100)
        ->and($items[0]['id'])->toBe($urgent)
        ->and($items[1]['id'])->toBe($high)
        ->and(collect($items)->pluck('id')->all())->not->toContain($oldLow);
});

function agentWorkDashboardApiUserWithRole(string $roleName, string $status = 'active'): User
{
    $user = User::factory()->create(['status' => $status]);
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
 * @return array{project_id: string, repository_id: string}
 */
function agentWorkDashboardApiProject(string $name, string $slug, string $status = 'active'): array
{
    $projectId = (string) Str::ulid();
    $repositoryId = (string) Str::ulid();
    $boardId = (string) Str::ulid();
    $adminId = (int) DB::table('users')->where('email', 'admin@example.com')->value('id');
    $now = now();
    $projectColumns = Schema::getColumnListing('projects');
    $projectRow = [
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

    foreach (['archived_at', 'archived_by_user_id', 'deleted_at', 'deleted_by_user_id', 'restored_at', 'restored_by_user_id'] as $column) {
        if (! in_array($column, $projectColumns, true)) {
            continue;
        }

        $projectRow[$column] = match ($column) {
            'archived_at' => $status === 'archived' ? $now : null,
            'archived_by_user_id' => $status === 'archived' ? $adminId : null,
            'deleted_at' => $status === 'deleted' ? $now : null,
            'deleted_by_user_id' => $status === 'deleted' ? $adminId : null,
            default => null,
        };
    }

    DB::table('projects')->insert($projectRow);

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

    foreach (['backlog', 'ready', 'in_progress', 'blocked', 'review', 'done'] as $position => $statusKey) {
        DB::table('kanban_columns')->insert([
            'id' => (string) Str::ulid(),
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
    ];
}

function agentWorkDashboardApiTask(string $projectId): string
{
    $taskId = (string) Str::ulid();
    $adminId = (int) DB::table('users')->where('email', 'admin@example.com')->value('id');
    $columnId = (string) DB::table('kanban_columns')
        ->join('kanban_boards', 'kanban_boards.id', '=', 'kanban_columns.board_id')
        ->where('kanban_boards.project_id', $projectId)
        ->where('kanban_boards.is_default', true)
        ->where('kanban_columns.status_key', 'ready')
        ->value('kanban_columns.id');

    DB::table('tasks')->insert([
        'id' => $taskId,
        'project_id' => $projectId,
        'title' => 'Agent work linked task',
        'description' => 'Task referenced by an agent work item.',
        'acceptance_criteria' => json_encode(['Agent work references this task.'], JSON_THROW_ON_ERROR),
        'status_column_id' => $columnId,
        'priority' => 'normal',
        'risk_level' => 'medium',
        'owner_user_id' => null,
        'created_by_user_id' => $adminId,
        'due_at' => null,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    return $taskId;
}

/**
 * @param array<string, mixed> $overrides
 */
function agentWorkDashboardApiWorkItem(string $projectId, array $overrides = []): string
{
    $workItemId = (string) Str::ulid();
    $adminId = (int) DB::table('users')->where('email', 'admin@example.com')->value('id');
    $now = now();

    DB::table('agent_work_items')->insert([
        ...[
            'id' => $workItemId,
            'project_id' => $projectId,
            'repository_id' => null,
            'task_id' => null,
            'requested_by_user_id' => $adminId,
            'assigned_agent_key' => 'local_agent',
            'status' => 'queued',
            'priority' => 'normal',
            'title' => 'Queued local agent work',
            'prompt' => 'Inspect this project workspace before implementation starts.',
            'payload' => json_encode(['request' => 'Inspect the project workspace.'], JSON_THROW_ON_ERROR),
            'requires_memory_entry' => true,
            'result_memory_entry_id' => null,
            'claimed_by_device_id' => null,
            'claimed_at' => null,
            'heartbeat_at' => null,
            'completed_at' => null,
            'failed_at' => null,
            'canceled_at' => null,
            'failure_reason' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ],
        ...$overrides,
    ]);

    return $workItemId;
}

function agentWorkDashboardApiDevice(): string
{
    $deviceId = (string) Str::ulid();
    $adminId = (int) DB::table('users')->where('email', 'admin@example.com')->value('id');

    DB::table('devices')->insert([
        'id' => $deviceId,
        'user_id' => $adminId,
        'name' => 'Agent Work Dashboard Device',
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
