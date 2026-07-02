<?php

use App\Models\User;
use Database\Seeders\DevBoardSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(DevBoardSeeder::class);
});

it('lets a developer append and list project memory entries', function () {
    $developer = projectMemoryDashboardApiUserWithRole('Developer');
    $projectId = (string) DB::table('projects')->where('slug', 'demo-project')->value('id');
    $repositoryId = (string) DB::table('repositories')->where('project_id', $projectId)->value('id');
    $taskId = projectMemoryDashboardApiTask($projectId);
    $runId = projectMemoryDashboardApiRun($projectId, $repositoryId, $taskId);

    $created = $this->actingAs($developer)
        ->postJson("/api/dashboard/projects/{$projectId}/memory", [
            'repository_id' => $repositoryId,
            'task_id' => $taskId,
            'run_id' => $runId,
            'agent_key' => 'local_agent',
            'kind' => 'implementation',
            'completeness' => 'incomplete',
            'summary' => 'Added employee table pagination guard.',
            'payload' => [
                'why' => 'The task was ambiguous until Platon clarified the expected pagination behavior.',
                'changed' => [
                    [
                        'path' => 'app/Tables/EmployeeTable.php',
                        'symbols' => ['EmployeeTable::applyFilters'],
                        'change' => 'Preserves page when result count still supports it.',
                    ],
                ],
                'tests' => ['php artisan test --filter=EmployeeTableTest'],
                'skipped_checks' => [],
                'risks' => [],
            ],
        ])
        ->assertCreated()
        ->assertJsonPath('project_id', $projectId)
        ->assertJsonPath('repository_id', $repositoryId)
        ->assertJsonPath('task_id', $taskId)
        ->assertJsonPath('run_id', $runId)
        ->assertJsonPath('author_user_id', $developer->id)
        ->assertJsonPath('agent_key', 'local_agent')
        ->assertJsonPath('source', 'user_inserted')
        ->assertJsonPath('kind', 'implementation')
        ->assertJsonPath('completeness', 'incomplete')
        ->assertJsonPath('summary', 'Added employee table pagination guard.')
        ->assertJsonPath('payload.changed.0.path', 'app/Tables/EmployeeTable.php')
        ->json();

    expect(DB::table('project_memory_entries')->where('id', $created['id'])->value('source'))->toBe('user_inserted')
        ->and(DB::table('project_memory_entries')->where('id', $created['id'])->value('author_user_id'))->toBe($developer->id);

    $this->actingAs($developer)
        ->getJson("/api/dashboard/projects/{$projectId}/memory")
        ->assertOk()
        ->assertJsonPath('entries.0.id', $created['id'])
        ->assertJsonPath('entries.0.summary', 'Added employee table pagination guard.')
        ->assertJsonPath('entries.0.payload.tests.0', 'php artisan test --filter=EmployeeTableTest');
});

it('lets pm append project memory entries', function () {
    $pm = projectMemoryDashboardApiUserWithRole('PM');
    $projectId = (string) DB::table('projects')->where('slug', 'demo-project')->value('id');

    $this->actingAs($pm)
        ->postJson("/api/dashboard/projects/{$projectId}/memory", [
            'kind' => 'decision',
            'summary' => 'Use explicit project-scoped shared memory.',
            'payload' => ['why' => 'Dashboard users need durable project context.'],
        ])
        ->assertCreated()
        ->assertJsonPath('source', 'user_inserted')
        ->assertJsonPath('completeness', 'complete');
});

it('lets a developer delete project memory entries', function () {
    $developer = projectMemoryDashboardApiUserWithRole('Developer');
    $projectId = (string) DB::table('projects')->where('slug', 'demo-project')->value('id');

    $entry = $this->actingAs($developer)
        ->postJson("/api/dashboard/projects/{$projectId}/memory", [
            'kind' => 'decision',
            'summary' => 'Delete obsolete manual dashboard memory.',
            'payload' => ['why' => 'The user should control manually inserted memory.'],
        ])
        ->assertCreated()
        ->json();

    $this->actingAs($developer)
        ->deleteJson("/api/dashboard/projects/{$projectId}/memory/{$entry['id']}")
        ->assertNoContent();

    expect(DB::table('project_memory_entries')->where('id', $entry['id'])->exists())->toBeFalse()
        ->and(DB::table('audit_logs')->where('action', 'project_memory.deleted')->where('target_id', $entry['id'])->value('actor_type'))
        ->toBe('user');
});

it('rejects memory summaries shorter than eight characters', function () {
    $developer = projectMemoryDashboardApiUserWithRole('Developer');
    $projectId = (string) DB::table('projects')->where('slug', 'demo-project')->value('id');

    $this->actingAs($developer)
        ->postJson("/api/dashboard/projects/{$projectId}/memory", [
            'kind' => 'decision',
            'summary' => 'Short',
            'payload' => ['why' => 'Summary must be meaningful enough for shared memory.'],
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['summary']);
});

it('keeps memory project scoped', function () {
    $developer = projectMemoryDashboardApiUserWithRole('Developer');
    $primaryProjectId = (string) DB::table('projects')->where('slug', 'demo-project')->value('id');
    $secondary = projectMemoryDashboardApiProject('Memory Second Project', 'memory-second-project');

    $this->actingAs($developer)->postJson("/api/dashboard/projects/{$primaryProjectId}/memory", [
        'kind' => 'decision',
        'summary' => 'Primary project decision.',
        'payload' => ['why' => 'Primary only.'],
    ])->assertCreated();

    $this->actingAs($developer)
        ->getJson("/api/dashboard/projects/{$secondary['project_id']}/memory")
        ->assertOk()
        ->assertJsonPath('entries', []);
});

it('keeps memory deletion project scoped', function () {
    $developer = projectMemoryDashboardApiUserWithRole('Developer');
    $primaryProjectId = (string) DB::table('projects')->where('slug', 'demo-project')->value('id');
    $secondary = projectMemoryDashboardApiProject('Memory Delete Second Project', 'memory-delete-second-project');
    $entryId = (string) Str::ulid();

    DB::table('project_memory_entries')->insert([
        'id' => $entryId,
        'project_id' => $primaryProjectId,
        'repository_id' => null,
        'task_id' => null,
        'run_id' => null,
        'author_user_id' => $developer->id,
        'agent_key' => null,
        'source' => 'user_inserted',
        'kind' => 'decision',
        'completeness' => 'complete',
        'summary' => 'Primary project deletion scope entry.',
        'payload' => json_encode(['why' => 'Delete route must stay project scoped.'], JSON_THROW_ON_ERROR),
        'occurred_at' => now(),
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $this->actingAs($developer)
        ->deleteJson("/api/dashboard/projects/{$secondary['project_id']}/memory/{$entryId}")
        ->assertNotFound();

    expect(DB::table('project_memory_entries')->where('id', $entryId)->exists())->toBeTrue();
});

it('returns newest project memory entries first and limits the list', function () {
    $pm = projectMemoryDashboardApiUserWithRole('PM');
    $projectId = (string) DB::table('projects')->where('slug', 'demo-project')->value('id');
    $now = now()->subHours(2);

    foreach (range(1, 101) as $index) {
        DB::table('project_memory_entries')->insert([
            'id' => (string) Str::ulid(),
            'project_id' => $projectId,
            'repository_id' => null,
            'task_id' => null,
            'run_id' => null,
            'author_user_id' => null,
            'agent_key' => null,
            'source' => 'dashboard_user',
            'kind' => 'agent_note',
            'completeness' => 'complete',
            'summary' => "Memory entry {$index}",
            'payload' => json_encode(['index' => $index], JSON_THROW_ON_ERROR),
            'occurred_at' => $now->copy()->addMinutes($index),
            'created_at' => $now->copy()->addMinutes($index),
            'updated_at' => $now->copy()->addMinutes($index),
        ]);
    }

    $entries = $this->actingAs($pm)
        ->getJson("/api/dashboard/projects/{$projectId}/memory")
        ->assertOk()
        ->json('entries');

    expect($entries)->toHaveCount(100)
        ->and($entries[0]['summary'])->toBe('Memory entry 101')
        ->and($entries[99]['summary'])->toBe('Memory entry 2');
});

it('returns the created memory entry even when it is outside the newest list page', function () {
    $developer = projectMemoryDashboardApiUserWithRole('Developer');
    $projectId = (string) DB::table('projects')->where('slug', 'demo-project')->value('id');
    $future = now()->addHour();

    foreach (range(1, 100) as $index) {
        DB::table('project_memory_entries')->insert([
            'id' => (string) Str::ulid(),
            'project_id' => $projectId,
            'repository_id' => null,
            'task_id' => null,
            'run_id' => null,
            'author_user_id' => null,
            'agent_key' => null,
            'source' => 'dashboard_user',
            'kind' => 'agent_note',
            'completeness' => 'complete',
            'summary' => "Future memory entry {$index}",
            'payload' => json_encode(['index' => $index], JSON_THROW_ON_ERROR),
            'occurred_at' => $future->copy()->addMinutes($index),
            'created_at' => $future->copy()->addMinutes($index),
            'updated_at' => $future->copy()->addMinutes($index),
        ]);
    }

    $created = $this->actingAs($developer)
        ->postJson("/api/dashboard/projects/{$projectId}/memory", [
            'kind' => 'verification',
            'summary' => 'Created entry is returned by direct lookup.',
            'payload' => ['why' => 'The created row can fall outside the newest memory page.'],
        ])
        ->assertCreated()
        ->assertJsonPath('summary', 'Created entry is returned by direct lookup.')
        ->json();

    expect($created)->toBeArray()
        ->and($created['id'] ?? null)->not->toBeNull()
        ->and(DB::table('project_memory_entries')->where('id', $created['id'])->value('summary'))
        ->toBe('Created entry is returned by direct lookup.');
});

it('rejects repository task and run ids from another project', function () {
    $developer = projectMemoryDashboardApiUserWithRole('Developer');
    $projectId = (string) DB::table('projects')->where('slug', 'demo-project')->value('id');
    $other = projectMemoryDashboardApiProject('Other Memory Project', 'other-memory-project');
    $otherTaskId = projectMemoryDashboardApiTask($other['project_id']);
    $otherRunId = projectMemoryDashboardApiRun($other['project_id'], $other['repository_id'], $otherTaskId);

    $this->actingAs($developer)
        ->postJson("/api/dashboard/projects/{$projectId}/memory", [
            'repository_id' => $other['repository_id'],
            'task_id' => $otherTaskId,
            'run_id' => $otherRunId,
            'kind' => 'risk',
            'summary' => 'Cross-project memory references must fail.',
            'payload' => ['why' => 'Memory references must stay within the selected project.'],
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['repository_id', 'task_id', 'run_id']);
});

it('blocks writes to archived and deleted projects', function (string $status) {
    $developer = projectMemoryDashboardApiUserWithRole('Developer');
    $project = projectMemoryDashboardApiProject(Str::headline($status).' Memory Project', "{$status}-memory-project", $status);

    $this->actingAs($developer)
        ->postJson("/api/dashboard/projects/{$project['project_id']}/memory", [
            'kind' => 'handoff',
            'summary' => 'This write should be blocked by lifecycle policy.',
            'payload' => ['why' => 'Non-active projects are read-only.'],
        ])
        ->assertConflict()
        ->assertJsonPath('error.code', 'project_not_active');
})->with(['archived', 'deleted']);

it('allows sysadmin to read memory but not append it', function () {
    $sysadmin = projectMemoryDashboardApiUserWithRole('Sysadmin');
    $projectId = (string) DB::table('projects')->where('slug', 'demo-project')->value('id');
    $memoryId = (string) Str::ulid();

    DB::table('project_memory_entries')->insert([
        'id' => $memoryId,
        'project_id' => $projectId,
        'repository_id' => null,
        'task_id' => null,
        'run_id' => null,
        'author_user_id' => null,
        'agent_key' => 'socrates',
        'source' => 'dashboard_user',
        'kind' => 'clarification',
        'completeness' => 'complete',
        'summary' => 'Clarified dashboard memory reader permissions.',
        'payload' => json_encode(['why' => 'Sysadmin is a reader role.'], JSON_THROW_ON_ERROR),
        'occurred_at' => now(),
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $this->actingAs($sysadmin)
        ->getJson("/api/dashboard/projects/{$projectId}/memory")
        ->assertOk()
        ->assertJsonPath('entries.0.summary', 'Clarified dashboard memory reader permissions.');

    $this->actingAs($sysadmin)
        ->postJson("/api/dashboard/projects/{$projectId}/memory", [
            'kind' => 'clarification',
            'summary' => 'Sysadmin cannot write memory.',
            'payload' => ['why' => 'Sysadmin is not a mutator role.'],
        ])
        ->assertForbidden();

    $this->actingAs($sysadmin)
        ->deleteJson("/api/dashboard/projects/{$projectId}/memory/{$memoryId}")
        ->assertForbidden();
});

it('queries separated logbook wiki and agent note memory domains', function () {
    $developer = projectMemoryDashboardApiUserWithRole('Developer');
    $projectId = (string) DB::table('projects')->where('slug', 'demo-project')->value('id');
    $now = now();
    $logbookId = (string) Str::ulid();
    $agentNoteId = (string) Str::ulid();
    $pageId = (string) Str::ulid();
    $revisionId = (string) Str::ulid();

    DB::table('project_memory_entries')->insert([
        [
            'id' => $logbookId,
            'project_id' => $projectId,
            'repository_id' => null,
            'task_id' => null,
            'run_id' => null,
            'author_user_id' => $developer->id,
            'agent_key' => null,
            'source' => 'user_inserted',
            'kind' => 'decision',
            'completeness' => 'complete',
            'summary' => 'Logbook decision about payments.',
            'payload' => json_encode(['domain' => 'logbook', 'topic' => 'payments'], JSON_THROW_ON_ERROR),
            'occurred_at' => $now->copy()->subMinutes(2),
            'created_at' => $now->copy()->subMinutes(2),
            'updated_at' => $now->copy()->subMinutes(2),
        ],
        [
            'id' => $agentNoteId,
            'project_id' => $projectId,
            'repository_id' => null,
            'task_id' => null,
            'run_id' => null,
            'author_user_id' => null,
            'agent_key' => 'socrates',
            'source' => 'server_agent',
            'kind' => 'agent_note',
            'completeness' => 'complete',
            'summary' => 'Socrates note about backlog latency.',
            'payload' => json_encode(['domain' => 'agent_notes', 'topic' => 'latency'], JSON_THROW_ON_ERROR),
            'occurred_at' => $now->copy()->subMinute(),
            'created_at' => $now->copy()->subMinute(),
            'updated_at' => $now->copy()->subMinute(),
        ],
    ]);

    DB::table('wiki_pages')->insert([
        'id' => $pageId,
        'project_id' => $projectId,
        'repository_id' => null,
        'slug' => 'payments-runbook',
        'title' => 'Payments Runbook',
        'page_type' => 'technical',
        'current_revision_id' => $revisionId,
        'source_status' => 'verified_from_code',
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    DB::table('wiki_revisions')->insert([
        'id' => $revisionId,
        'wiki_page_id' => $pageId,
        'author_user_id' => null,
        'author_device_id' => null,
        'producer' => 'test',
        'source_type' => 'manual',
        'source_status' => 'verified_from_code',
        'content_markdown' => 'Payments runbook contains deployment and rollback notes.',
        'evidence_refs' => json_encode([['type' => 'file_ref', 'path' => 'docs/payments.md']], JSON_THROW_ON_ERROR),
        'created_at' => $now,
    ]);

    $this->actingAs($developer)
        ->getJson("/api/dashboard/projects/{$projectId}/memory?domain=agent_notes&q=latency")
        ->assertOk()
        ->assertJsonPath('domain', 'agent_notes')
        ->assertJsonPath('query', 'latency')
        ->assertJsonPath('entries.0.id', $agentNoteId)
        ->assertJsonPath('entries.0.domain', 'agent_notes')
        ->assertJsonCount(1, 'entries')
        ->assertJsonPath('domains.agent_notes', 1)
        ->assertJsonPath('domains.logbook', 1)
        ->assertJsonPath('domains.wiki', 1);

    $this->actingAs($developer)
        ->getJson("/api/dashboard/projects/{$projectId}/memory?domain=wiki&q=rollback")
        ->assertOk()
        ->assertJsonPath('domain', 'wiki')
        ->assertJsonPath('entries.0.id', $revisionId)
        ->assertJsonPath('entries.0.domain', 'wiki')
        ->assertJsonPath('entries.0.payload.page_slug', 'payments-runbook')
        ->assertJsonPath('entries.0.payload.evidence_refs.0.path', 'docs/payments.md');
});

function projectMemoryDashboardApiUserWithRole(string $roleName): User
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
 * @return array{project_id: string, repository_id: string}
 */
function projectMemoryDashboardApiProject(string $name, string $slug, string $status = 'active'): array
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

    foreach (['archived_at', 'archived_by_user_id', 'deleted_at', 'deleted_by_user_id'] as $column) {
        if (in_array($column, $projectColumns, true)) {
            $projectRow[$column] = match ($column) {
                'archived_at' => $status === 'archived' ? $now : null,
                'archived_by_user_id' => $status === 'archived' ? $adminId : null,
                'deleted_at' => $status === 'deleted' ? $now : null,
                'deleted_by_user_id' => $status === 'deleted' ? $adminId : null,
            };
        }
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

function projectMemoryDashboardApiTask(string $projectId): string
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
        'title' => 'Memory-linked task',
        'description' => 'Task referenced by a memory entry.',
        'acceptance_criteria' => json_encode(['Memory entry references this task.'], JSON_THROW_ON_ERROR),
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

function projectMemoryDashboardApiRun(string $projectId, string $repositoryId, ?string $taskId = null): string
{
    $runId = (string) Str::ulid();
    $adminId = (int) DB::table('users')->where('email', 'admin@example.com')->value('id');
    $deviceId = projectMemoryDashboardApiDevice();
    $now = now();

    DB::table('runs')->insert([
        'id' => $runId,
        'project_id' => $projectId,
        'repository_id' => $repositoryId,
        'local_workspace_id' => null,
        'task_id' => $taskId,
        'device_id' => $deviceId,
        'started_by_user_id' => $adminId,
        'runtime_profile' => 'agent_plugin',
        'status' => 'finished',
        'branch' => 'main',
        'base_branch' => 'main',
        'base_sha' => 'abc123',
        'head_sha' => 'def456',
        'summary' => 'Memory-linked run.',
        'risk_level' => 'medium',
        'started_at' => $now,
        'finished_at' => $now,
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    return $runId;
}

function projectMemoryDashboardApiDevice(): string
{
    $deviceId = (string) Str::ulid();
    $adminId = (int) DB::table('users')->where('email', 'admin@example.com')->value('id');

    DB::table('devices')->insert([
        'id' => $deviceId,
        'user_id' => $adminId,
        'name' => 'Memory Dashboard Device',
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
