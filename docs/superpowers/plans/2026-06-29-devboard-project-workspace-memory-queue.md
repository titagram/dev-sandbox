# DevBoard Project Workspace Memory Queue Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Build the first usable DevBoard workspace slice: project-scoped Kanban task creation/editing, Shared Memory, Socrates/Platon/Aristoteles work queue, plugin polling endpoints, and React surfaces that make project ownership explicit.

**Architecture:** Laravel remains the authoritative server for dashboard reads and writes, while `/api/plugin/v1` remains the only contract used by the local developer agent. React talks only through the existing `DevboardApi` adapter boundary. The local agent never receives direct shell instructions from the server; it reads leased work items and writes exhaustive memory entries when it completes them.

**Tech Stack:** Laravel 13, Pest/PHPUnit, PostgreSQL-compatible migrations with SQLite test support, React/TypeScript, Python plugin client/MCP tools, Docker Compose.

---

## Source Status

- `verified_from_code`: Existing dashboard routes, `DashboardApiReader`, `DashboardResourceController`, plugin auth middleware, plugin client, MCP registry, React routes, React API adapters, and current database schema were inspected.
- `developer_provided`: Socrates is the cross-workspace interface, Platon clarifies Kanban tasks with yes/no questions, Aristoteles analyzes memory/KPI patterns, and the server exposes a work queue consumed by the local agent.
- `inferred`: The first production-value slice should prioritize project context, task creation, memory, and server-to-local work coordination before deeper graph or artifact redesign.

## Boundaries

- Preserve the current Laravel dashboard API style under `backend/routes/web.php`.
- Preserve the current plugin API style under `backend/routes/api.php`, including `protocol_version: v1` for POST requests and `X-DevBoard-Protocol: v1` for GET requests.
- Preserve the React adapter boundary in `/home/ubuntu/emergent_devboard_frontend/frontend/src/api`.
- Do not rename `artifacts` tables or existing routes in this slice. Reframe them in navigation copy as Evidence/Engineering only after the core workspace surfaces exist.
- Keep PM users able to create and edit Kanban task details. Keep project create/update restricted to Admin unless the codebase policy changes in a separate task.
- Each implementation task must update `ai-sandbox/logbooks/LOGBOOK_PROJECT.md` with its own verification results and must stage only the lines it owns, because the logbook can contain unrelated user edits.

## File Structure

### Backend Laravel

- Create `backend/database/migrations/2026_06_29_000001_create_project_workspace_memory_queue_tables.php`
  - Adds `tasks.acceptance_criteria`.
  - Adds `repository_task` for explicit task repository scope.
  - Adds `project_memory_entries` as the Shared Memory logbook.
  - Adds `project_memory_links` for task/run/repository/artifact references.
  - Adds `agent_work_items`, `agent_work_item_events`, and `agent_work_item_leases`.
- Modify `backend/routes/web.php`
  - Adds dashboard task creation, memory, and agent work routes.
- Modify `backend/routes/api.php`
  - Adds plugin shared memory pack and work queue routes.
- Modify `backend/app/Dashboard/DashboardApiReader.php`
  - Returns task acceptance criteria, explicit repository scope, memory entries, and agent work item rows.
- Modify `backend/app/Http/Controllers/DashboardResourceController.php`
  - Extends task create/update behavior while preserving column moves.
- Create `backend/app/Http/Controllers/DashboardMemoryController.php`
  - Lists and appends project memory entries from the dashboard.
- Create `backend/app/Http/Controllers/DashboardAgentWorkController.php`
  - Lets dashboard users create, approve, cancel, and inspect agent work items.
- Create `backend/app/Http/Controllers/Plugin/SharedMemoryPackController.php`
  - Serves the local agent a compact preflight memory pack.
- Create `backend/app/Http/Controllers/Plugin/AgentWorkItemController.php`
  - Lists, claims, heartbeats, completes, and fails local-agent work items.
- Test with:
  - `backend/tests/Feature/Dashboard/ProjectWorkspaceMemoryQueueSchemaTest.php`
  - `backend/tests/Feature/Dashboard/KanbanTaskCreateEditApiTest.php`
  - `backend/tests/Feature/Dashboard/ProjectMemoryDashboardApiTest.php`
  - `backend/tests/Feature/Dashboard/AgentWorkDashboardApiTest.php`
  - `backend/tests/Feature/Plugin/PluginSharedMemoryAndWorkQueueTest.php`

### Python Plugin

- Modify `plugin/src/devboard_plugin/client.py`
  - Adds methods for shared memory pack and work item lifecycle.
- Modify `plugin/src/devboard_plugin/mcp_tools.py`
  - Adds MCP-callable tools for shared memory pack and work queue operations.
- Modify `plugin/src/devboard_plugin/mcp_server.py`
  - No behavioral change expected if it already registers `TOOL_REGISTRY`; verify after tools are added.
- Modify `plugin/src/devboard_plugin/cli.py`
  - Adds human-readable CLI commands for manual polling and completion.
- Test with:
  - Existing plugin tests if present.
  - New `plugin/tests/test_work_queue_tools.py`.

### React Frontend

- Modify `/home/ubuntu/emergent_devboard_frontend/frontend/src/types/devboard.ts`
  - Adds task input, memory, agent work, shared memory, and agent identity types.
- Modify `/home/ubuntu/emergent_devboard_frontend/frontend/src/api/devboardApi.ts`
  - Adds adapter methods for task create/update details, memory, and agent work.
- Modify `/home/ubuntu/emergent_devboard_frontend/frontend/src/api/httpApi.ts`
  - Implements the new HTTP calls.
- Modify `/home/ubuntu/emergent_devboard_frontend/frontend/src/api/mockApi.ts`
  - Keeps development mode usable without a backend server.
- Modify `/home/ubuntu/emergent_devboard_frontend/frontend/src/api/mockData.ts`
  - Adds realistic project-scoped memory and agent work examples.
- Modify `/home/ubuntu/emergent_devboard_frontend/frontend/src/App.tsx`
  - Adds project-scoped routes for Memory, Agent Work, Ask, and Engineering entry points.
- Modify `/home/ubuntu/emergent_devboard_frontend/frontend/src/lib/nav.ts`
  - Replaces ambiguous global navigation with workspace-oriented labels.
- Modify `/home/ubuntu/emergent_devboard_frontend/frontend/src/components/devboard/AppShell.tsx`
  - Adds explicit project context and scoped navigation.
- Modify `/home/ubuntu/emergent_devboard_frontend/frontend/src/pages/KanbanPage.tsx`
  - Adds create/edit task flow and Platon clarity panel entry state.
- Modify `/home/ubuntu/emergent_devboard_frontend/frontend/src/pages/TaskDetailPage.tsx`
  - Shows editable detail fields and task-level agent questions.
- Create `/home/ubuntu/emergent_devboard_frontend/frontend/src/pages/ProjectMemoryPage.tsx`
  - Displays Shared Memory entries with filters and append form.
- Create `/home/ubuntu/emergent_devboard_frontend/frontend/src/pages/AgentWorkPage.tsx`
  - Displays local-agent requested work, lifecycle, and completion memory status.
- Create `/home/ubuntu/emergent_devboard_frontend/frontend/src/pages/AskAgentsPage.tsx`
  - Provides direct chat entry surfaces for Socrates, Platon, and Aristoteles using persisted work items in this slice.
- Create `/home/ubuntu/emergent_devboard_frontend/frontend/src/pages/EngineeringPage.tsx`
  - Reframes Runs/Graph/Artifacts as technical evidence and diagnostics links.

## Shared Data Contracts

Use these string values consistently across PHP, TypeScript, and Python:

```text
agent_key: socrates | platon | aristoteles | local_agent
agent_work_status: draft | queued | claimed | running | completed | completed_with_incomplete_memory | failed | canceled
agent_work_priority: low | normal | high | urgent
memory_entry_kind: decision | implementation | clarification | risk | verification | handoff | incident | agent_note
memory_source: dashboard_user | server_agent | local_agent | system_event
memory_completeness: complete | incomplete
```

`project_memory_entries.payload` must accept this shape:

```json
{
  "summary": "Added task creation API and React dialog.",
  "why": "PM users need a clear way to write actionable work.",
  "changed": [
    {
      "path": "backend/app/Http/Controllers/DashboardResourceController.php",
      "symbols": ["DashboardResourceController::storeProjectTask"],
      "change": "Creates project-scoped Kanban tasks with acceptance criteria."
    }
  ],
  "tests": [
    "php artisan test --filter=KanbanTaskCreateEditApiTest"
  ],
  "skipped_checks": [],
  "risks": [
    "Repository scope is explicit but branch-level scope is not in this slice."
  ]
}
```

`agent_work_items.payload` must accept this shape:

```json
{
  "request": "Inspect this task and ask yes/no questions before development starts.",
  "expected_output": "Task comments/questions for the PM.",
  "repository_ids": ["01J..."],
  "task_id": "01J...",
  "requires_memory_entry": true
}
```

## Task 1: Backend Schema For Tasks, Memory, And Work Queue

**Files:**
- Create: `backend/database/migrations/2026_06_29_000001_create_project_workspace_memory_queue_tables.php`
- Create: `backend/tests/Feature/Dashboard/ProjectWorkspaceMemoryQueueSchemaTest.php`
- Modify: `ai-sandbox/logbooks/LOGBOOK_PROJECT.md`

- [ ] **Step 1: Write the failing schema test**

Create `backend/tests/Feature/Dashboard/ProjectWorkspaceMemoryQueueSchemaTest.php`:

```php
<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;

uses(RefreshDatabase::class);

it('creates project workspace memory and work queue schema', function () {
    expect(Schema::hasColumn('tasks', 'acceptance_criteria'))->toBeTrue()
        ->and(Schema::hasTable('repository_task'))->toBeTrue()
        ->and(Schema::hasColumns('repository_task', ['id', 'task_id', 'repository_id', 'created_at', 'updated_at']))->toBeTrue()
        ->and(Schema::hasTable('project_memory_entries'))->toBeTrue()
        ->and(Schema::hasColumns('project_memory_entries', [
            'id',
            'project_id',
            'repository_id',
            'task_id',
            'run_id',
            'author_user_id',
            'agent_key',
            'source',
            'kind',
            'completeness',
            'summary',
            'payload',
            'occurred_at',
            'created_at',
            'updated_at',
        ]))->toBeTrue()
        ->and(Schema::hasTable('project_memory_links'))->toBeTrue()
        ->and(Schema::hasColumns('project_memory_links', ['id', 'memory_entry_id', 'target_type', 'target_id', 'created_at', 'updated_at']))->toBeTrue()
        ->and(Schema::hasTable('agent_work_items'))->toBeTrue()
        ->and(Schema::hasColumns('agent_work_items', [
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
        ]))->toBeTrue()
        ->and(Schema::hasTable('agent_work_item_events'))->toBeTrue()
        ->and(Schema::hasColumns('agent_work_item_events', ['id', 'agent_work_item_id', 'actor_user_id', 'actor_device_id', 'event_type', 'message', 'payload', 'created_at', 'updated_at']))->toBeTrue()
        ->and(Schema::hasTable('agent_work_item_leases'))->toBeTrue()
        ->and(Schema::hasColumns('agent_work_item_leases', ['id', 'agent_work_item_id', 'device_id', 'lease_token_hash', 'expires_at', 'released_at', 'created_at', 'updated_at']))->toBeTrue();
});
```

- [ ] **Step 2: Run the failing schema test**

Run:

```bash
cd backend && php artisan test --filter=ProjectWorkspaceMemoryQueueSchemaTest
```

Expected: FAIL because `repository_task`, `project_memory_entries`, `agent_work_items`, and related tables do not exist.

- [ ] **Step 3: Add the migration**

Create `backend/database/migrations/2026_06_29_000001_create_project_workspace_memory_queue_tables.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('tasks', 'acceptance_criteria')) {
            Schema::table('tasks', function (Blueprint $table): void {
                $table->json('acceptance_criteria')->nullable()->after('description');
            });
        }

        Schema::create('repository_task', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('task_id')->constrained('tasks')->cascadeOnDelete();
            $table->foreignUlid('repository_id')->constrained('repositories')->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['task_id', 'repository_id']);
            $table->index('repository_id');
        });

        Schema::create('project_memory_entries', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('project_id')->constrained('projects')->cascadeOnDelete();
            $table->foreignUlid('repository_id')->nullable()->constrained('repositories')->nullOnDelete();
            $table->foreignUlid('task_id')->nullable()->constrained('tasks')->nullOnDelete();
            $table->foreignUlid('run_id')->nullable()->constrained('runs')->nullOnDelete();
            $table->foreignId('author_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('agent_key')->nullable();
            $table->string('source');
            $table->string('kind');
            $table->string('completeness')->default('complete');
            $table->string('summary');
            $table->json('payload');
            $table->timestamp('occurred_at');
            $table->timestamps();

            $table->index(['project_id', 'occurred_at']);
            $table->index(['project_id', 'kind']);
            $table->index(['project_id', 'agent_key']);
        });

        Schema::create('project_memory_links', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('memory_entry_id')->constrained('project_memory_entries')->cascadeOnDelete();
            $table->string('target_type');
            $table->string('target_id');
            $table->timestamps();

            $table->index(['target_type', 'target_id']);
        });

        Schema::create('agent_work_items', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('project_id')->constrained('projects')->cascadeOnDelete();
            $table->foreignUlid('repository_id')->nullable()->constrained('repositories')->nullOnDelete();
            $table->foreignUlid('task_id')->nullable()->constrained('tasks')->nullOnDelete();
            $table->foreignId('requested_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('assigned_agent_key');
            $table->string('status')->default('draft');
            $table->string('priority')->default('normal');
            $table->string('title');
            $table->text('prompt');
            $table->json('payload');
            $table->boolean('requires_memory_entry')->default(true);
            $table->foreignUlid('result_memory_entry_id')->nullable()->constrained('project_memory_entries')->nullOnDelete();
            $table->foreignUlid('claimed_by_device_id')->nullable()->constrained('devices')->nullOnDelete();
            $table->timestamp('claimed_at')->nullable();
            $table->timestamp('heartbeat_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('failed_at')->nullable();
            $table->timestamp('canceled_at')->nullable();
            $table->text('failure_reason')->nullable();
            $table->timestamps();

            $table->index(['project_id', 'status']);
            $table->index(['assigned_agent_key', 'status']);
            $table->index(['repository_id', 'status']);
        });

        Schema::create('agent_work_item_events', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('agent_work_item_id')->constrained('agent_work_items')->cascadeOnDelete();
            $table->foreignId('actor_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignUlid('actor_device_id')->nullable()->constrained('devices')->nullOnDelete();
            $table->string('event_type');
            $table->text('message')->nullable();
            $table->json('payload');
            $table->timestamps();

            $table->index(['agent_work_item_id', 'created_at']);
        });

        Schema::create('agent_work_item_leases', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('agent_work_item_id')->constrained('agent_work_items')->cascadeOnDelete();
            $table->foreignUlid('device_id')->constrained('devices')->cascadeOnDelete();
            $table->string('lease_token_hash');
            $table->timestamp('expires_at');
            $table->timestamp('released_at')->nullable();
            $table->timestamps();

            $table->index(['device_id', 'released_at']);
            $table->index(['agent_work_item_id', 'released_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('agent_work_item_leases');
        Schema::dropIfExists('agent_work_item_events');
        Schema::dropIfExists('agent_work_items');
        Schema::dropIfExists('project_memory_links');
        Schema::dropIfExists('project_memory_entries');
        Schema::dropIfExists('repository_task');

        if (Schema::hasColumn('tasks', 'acceptance_criteria')) {
            Schema::table('tasks', function (Blueprint $table): void {
                $table->dropColumn('acceptance_criteria');
            });
        }
    }
};
```

- [ ] **Step 4: Run schema test and full dashboard smoke test**

Run:

```bash
cd backend && php artisan test --filter=ProjectWorkspaceMemoryQueueSchemaTest
cd backend && php artisan test --filter=MultiprojectDashboardApiTest
```

Expected: both commands PASS.

- [ ] **Step 5: Commit schema slice**

Run:

```bash
git diff -- backend/database/migrations/2026_06_29_000001_create_project_workspace_memory_queue_tables.php backend/tests/Feature/Dashboard/ProjectWorkspaceMemoryQueueSchemaTest.php
git add backend/database/migrations/2026_06_29_000001_create_project_workspace_memory_queue_tables.php backend/tests/Feature/Dashboard/ProjectWorkspaceMemoryQueueSchemaTest.php
git commit -m "feat: add project memory and work queue schema"
```

Expected: commit includes only the migration and schema test. If `ai-sandbox/logbooks/LOGBOOK_PROJECT.md` contains unrelated unstaged lines, leave it unstaged until the final documentation task.

## Task 2: Kanban Task Create/Edit Dashboard API

**Files:**
- Modify: `backend/routes/web.php`
- Modify: `backend/app/Http/Controllers/DashboardResourceController.php`
- Modify: `backend/app/Dashboard/DashboardApiReader.php`
- Create: `backend/tests/Feature/Dashboard/KanbanTaskCreateEditApiTest.php`
- Modify: `ai-sandbox/logbooks/LOGBOOK_PROJECT.md`

- [ ] **Step 1: Write failing dashboard API tests**

Create `backend/tests/Feature/Dashboard/KanbanTaskCreateEditApiTest.php`:

```php
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
    $pm = kanbanTaskApiUserWithRole('PM');
    $projectId = (string) DB::table('projects')->where('slug', 'demo-project')->value('id');
    $repositoryId = (string) DB::table('repositories')->where('project_id', $projectId)->value('id');

    $response = $this->actingAs($pm)->postJson("/api/dashboard/projects/{$projectId}/tasks", [
        'title' => 'Fix employee table pagination',
        'description' => 'The employee table resets to page one after changing filters.',
        'priority' => 'high',
        'risk' => 'medium',
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
        ->json('id');

    $this->actingAs($pm)
        ->getJson("/api/dashboard/tasks/{$taskId}")
        ->assertOk()
        ->assertJsonPath('acceptance_criteria.0', 'Filtering keeps the selected page when the result count still supports it.')
        ->assertJsonPath('repositories.0.id', $repositoryId);
});

it('edits task detail fields without moving the card when column is omitted', function () {
    $pm = kanbanTaskApiUserWithRole('PM');
    $projectId = (string) DB::table('projects')->where('slug', 'demo-project')->value('id');
    $taskId = (string) DB::table('tasks')->where('project_id', $projectId)->value('id');

    $this->actingAs($pm)
        ->patchJson("/api/dashboard/tasks/{$taskId}", [
            'title' => 'Clarified employee table bug',
            'description' => 'Pagination state must be stable while filters are changed.',
            'priority' => 'normal',
            'risk' => 'low',
            'acceptance_criteria' => ['Stable pagination is verified manually.'],
        ])
        ->assertOk()
        ->assertJsonPath('title', 'Clarified employee table bug')
        ->assertJsonPath('acceptance_criteria.0', 'Stable pagination is verified manually.');
});

it('rejects repository scope from another project', function () {
    $pm = kanbanTaskApiUserWithRole('PM');
    $projectId = (string) DB::table('projects')->where('slug', 'demo-project')->value('id');
    $otherProjectId = (string) Str::ulid();
    $otherRepositoryId = (string) Str::ulid();
    $adminId = (int) DB::table('users')->where('email', 'admin@example.com')->value('id');
    $now = now();

    DB::table('projects')->insert([
        'id' => $otherProjectId,
        'name' => 'Other Project',
        'slug' => 'other-project',
        'description' => null,
        'status' => 'active',
        'default_code_exposure_policy' => 'full_code_artifacts',
        'created_by_user_id' => $adminId,
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    DB::table('repositories')->insert([
        'id' => $otherRepositoryId,
        'project_id' => $otherProjectId,
        'name' => 'other-repo',
        'slug' => 'other-repo',
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

    $this->actingAs($pm)
        ->postJson("/api/dashboard/projects/{$projectId}/tasks", [
            'title' => 'Cross project scope must fail',
            'repository_ids' => [$otherRepositoryId],
        ])
        ->assertJsonValidationErrors(['repository_ids.0']);
});

function kanbanTaskApiUserWithRole(string $roleName): User
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
```

- [ ] **Step 2: Run failing task API tests**

Run:

```bash
cd backend && php artisan test --filter=KanbanTaskCreateEditApiTest
```

Expected: FAIL because `POST /api/dashboard/projects/{project}/tasks` does not exist and task details do not return persisted acceptance criteria or explicit repository objects.

- [ ] **Step 3: Add route**

In `backend/routes/web.php`, add this line inside the `/api/dashboard` authenticated group near the existing task routes:

```php
Route::post('/projects/{project}/tasks', [DashboardResourceController::class, 'storeProjectTask']);
```

- [ ] **Step 4: Extend task create/update controller behavior**

In `backend/app/Http/Controllers/DashboardResourceController.php`, add imports if missing:

```php
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
```

Add `storeProjectTask` and replace `updateTask` with a version that delegates validation and repository syncing:

```php
public function storeProjectTask(Request $request, string $project): JsonResponse
{
    $this->abortUnlessDashboardMutator($request);
    $this->abortUnlessProjectReadable($project);

    $validated = $this->validateTaskPayload($request, $project, creating: true);
    $columnId = $this->defaultTaskColumnId($project);
    $taskId = (string) Str::ulid();
    $now = now();

    DB::table('tasks')->insert([
        'id' => $taskId,
        'project_id' => $project,
        'title' => $validated['title'],
        'description' => $validated['description'] ?? null,
        'acceptance_criteria' => json_encode($validated['acceptance_criteria'] ?? [], JSON_THROW_ON_ERROR),
        'status_column_id' => $columnId,
        'priority' => $validated['priority'] ?? 'normal',
        'risk_level' => $validated['risk'] ?? 'low',
        'owner_user_id' => $validated['owner_user_id'] ?? null,
        'created_by_user_id' => $request->user()->id,
        'due_at' => null,
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    $this->syncTaskRepositories($taskId, $project, $validated['repository_ids'] ?? []);

    return response()->json($this->reader->task($taskId), 201);
}

public function updateTask(Request $request, string $task): JsonResponse
{
    $this->abortUnlessDashboardMutator($request);

    $row = DB::table('tasks')->where('id', $task)->first();
    abort_unless($row, 404);

    $validated = $this->validateTaskPayload($request, (string) $row->project_id, creating: false);
    $updates = [];

    if (array_key_exists('column', $validated)) {
        $columnId = DB::table('kanban_columns')
            ->join('kanban_boards', 'kanban_boards.id', '=', 'kanban_columns.board_id')
            ->where('kanban_boards.project_id', $row->project_id)
            ->where('kanban_columns.status_key', $validated['column'])
            ->value('kanban_columns.id');

        abort_unless($columnId, 422, 'Unknown kanban column.');
        $updates['status_column_id'] = $columnId;
    }

    foreach (['title', 'description', 'priority', 'owner_user_id'] as $field) {
        if (array_key_exists($field, $validated)) {
            $updates[$field] = $validated[$field];
        }
    }

    if (array_key_exists('risk', $validated)) {
        $updates['risk_level'] = $validated['risk'];
    }

    if (array_key_exists('acceptance_criteria', $validated)) {
        $updates['acceptance_criteria'] = json_encode($validated['acceptance_criteria'], JSON_THROW_ON_ERROR);
    }

    if ($updates !== []) {
        $updates['updated_at'] = now();
        DB::table('tasks')->where('id', $task)->update($updates);
    }

    if (array_key_exists('repository_ids', $validated)) {
        $this->syncTaskRepositories($task, (string) $row->project_id, $validated['repository_ids']);
    }

    return response()->json($this->reader->task($task));
}
```

Add helper methods in the same controller:

```php
private function validateTaskPayload(Request $request, string $projectId, bool $creating): array
{
    $repositoryRule = Rule::exists('repositories', 'id')->where(fn ($query) => $query->where('project_id', $projectId));

    return $request->validate([
        'title' => [$creating ? 'required' : 'sometimes', 'string', 'min:3', 'max:180'],
        'description' => ['sometimes', 'nullable', 'string', 'max:5000'],
        'column' => ['sometimes', 'string', Rule::in(['backlog', 'ready', 'in_progress', 'review', 'done', 'blocked'])],
        'priority' => ['sometimes', 'string', Rule::in(['low', 'normal', 'high', 'urgent'])],
        'risk' => ['sometimes', 'string', Rule::in(['low', 'medium', 'high'])],
        'owner_user_id' => ['sometimes', 'nullable', 'integer', Rule::exists('users', 'id')],
        'repository_ids' => ['sometimes', 'array'],
        'repository_ids.*' => ['string', $repositoryRule],
        'acceptance_criteria' => ['sometimes', 'array', 'max:20'],
        'acceptance_criteria.*' => ['string', 'min:3', 'max:500'],
    ]);
}

private function defaultTaskColumnId(string $projectId): string
{
    $columnId = DB::table('kanban_columns')
        ->join('kanban_boards', 'kanban_boards.id', '=', 'kanban_columns.board_id')
        ->where('kanban_boards.project_id', $projectId)
        ->where('kanban_columns.status_key', 'backlog')
        ->value('kanban_columns.id');

    abort_unless($columnId, 422, 'Project kanban board has no backlog column.');

    return (string) $columnId;
}

private function syncTaskRepositories(string $taskId, string $projectId, array $repositoryIds): void
{
    DB::table('repository_task')->where('task_id', $taskId)->delete();

    $validRepositoryIds = DB::table('repositories')
        ->where('project_id', $projectId)
        ->whereIn('id', $repositoryIds)
        ->pluck('id')
        ->map(fn (mixed $id): string => (string) $id)
        ->all();

    $now = now();
    foreach (array_values(array_unique($validRepositoryIds)) as $repositoryId) {
        DB::table('repository_task')->insert([
            'id' => (string) Str::ulid(),
            'task_id' => $taskId,
            'repository_id' => $repositoryId,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }
}
```

- [ ] **Step 5: Extend task reader output**

In `backend/app/Dashboard/DashboardApiReader.php`, change `task()` so `acceptance_criteria` is decoded from the task row:

```php
'acceptance_criteria' => $this->jsonList($task->acceptance_criteria ?? null),
```

Replace the `repositories` value in `taskCard()` with explicit repository scope plus latest-run fallback:

```php
$repositories = $this->taskRepositories((string) $task->id, $latestRun?->repository_id ? (string) $latestRun->repository_id : null);
```

and return:

```php
'repositories' => $repositories,
```

Add these private helpers:

```php
/**
 * @return list<string>
 */
private function jsonList(mixed $value): array
{
    if ($value === null || $value === '') {
        return [];
    }

    $decoded = is_string($value) ? json_decode($value, true) : $value;

    if (! is_array($decoded)) {
        return [];
    }

    return array_values(array_filter(array_map(
        fn (mixed $item): string => trim((string) $item),
        $decoded,
    ), fn (string $item): bool => $item !== ''));
}

/**
 * @return list<array{id: string, name: string}>
 */
private function taskRepositories(string $taskId, ?string $latestRunRepositoryId): array
{
    $repositoryIds = DB::table('repository_task')
        ->where('task_id', $taskId)
        ->pluck('repository_id')
        ->map(fn (mixed $id): string => (string) $id)
        ->all();

    if ($repositoryIds === [] && $latestRunRepositoryId !== null) {
        $repositoryIds = [$latestRunRepositoryId];
    }

    if ($repositoryIds === []) {
        return [];
    }

    return DB::table('repositories')
        ->whereIn('id', $repositoryIds)
        ->orderBy('name')
        ->get(['id', 'name'])
        ->map(fn (object $repository): array => [
            'id' => (string) $repository->id,
            'name' => (string) $repository->name,
        ])
        ->all();
}
```

- [ ] **Step 6: Run dashboard API tests**

Run:

```bash
cd backend && php artisan test --filter=KanbanTaskCreateEditApiTest
cd backend && php artisan test --filter=DashboardApiContractTest
cd backend && php artisan test --filter=MultiprojectDashboardApiTest
```

Expected: all commands PASS.

- [ ] **Step 7: Commit Kanban API slice**

Run:

```bash
git diff -- backend/routes/web.php backend/app/Http/Controllers/DashboardResourceController.php backend/app/Dashboard/DashboardApiReader.php backend/tests/Feature/Dashboard/KanbanTaskCreateEditApiTest.php
git add backend/routes/web.php backend/app/Http/Controllers/DashboardResourceController.php backend/app/Dashboard/DashboardApiReader.php backend/tests/Feature/Dashboard/KanbanTaskCreateEditApiTest.php
git commit -m "feat: add project kanban task create edit api"
```

Expected: commit contains only Kanban API and reader changes.

## Task 3: Dashboard Shared Memory API

**Files:**
- Modify: `backend/routes/web.php`
- Create: `backend/app/Http/Controllers/DashboardMemoryController.php`
- Modify: `backend/app/Dashboard/DashboardApiReader.php`
- Create: `backend/tests/Feature/Dashboard/ProjectMemoryDashboardApiTest.php`
- Modify: `ai-sandbox/logbooks/LOGBOOK_PROJECT.md`

- [ ] **Step 1: Write failing memory dashboard tests**

Create `backend/tests/Feature/Dashboard/ProjectMemoryDashboardApiTest.php`:

```php
<?php

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(\Database\Seeders\DevBoardSeeder::class);
});

it('lists and appends project memory entries', function () {
    $developer = projectMemoryApiUserWithRole('Developer');
    $projectId = (string) DB::table('projects')->where('slug', 'demo-project')->value('id');

    $created = $this->actingAs($developer)
        ->postJson("/api/dashboard/projects/{$projectId}/memory", [
            'kind' => 'implementation',
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
        ->assertJsonPath('kind', 'implementation')
        ->assertJsonPath('source', 'dashboard_user')
        ->json();

    $this->actingAs($developer)
        ->getJson("/api/dashboard/projects/{$projectId}/memory")
        ->assertOk()
        ->assertJsonPath('entries.0.id', $created['id'])
        ->assertJsonPath('entries.0.summary', 'Added employee table pagination guard.');
});

it('keeps memory project scoped', function () {
    $developer = projectMemoryApiUserWithRole('Developer');
    $primaryProjectId = (string) DB::table('projects')->where('slug', 'demo-project')->value('id');
    $secondaryProjectId = projectMemoryApiCreateSecondProject();

    $this->actingAs($developer)->postJson("/api/dashboard/projects/{$primaryProjectId}/memory", [
        'kind' => 'decision',
        'summary' => 'Primary project decision.',
        'payload' => ['why' => 'Primary only.'],
    ])->assertCreated();

    $this->actingAs($developer)
        ->getJson("/api/dashboard/projects/{$secondaryProjectId}/memory")
        ->assertOk()
        ->assertJsonPath('entries', []);
});

function projectMemoryApiUserWithRole(string $roleName): User
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

function projectMemoryApiCreateSecondProject(): string
{
    $projectId = (string) \Illuminate\Support\Str::ulid();
    $adminId = (int) DB::table('users')->where('email', 'admin@example.com')->value('id');
    $now = now();

    DB::table('projects')->insert([
        'id' => $projectId,
        'name' => 'Memory Second Project',
        'slug' => 'memory-second-project',
        'description' => null,
        'status' => 'active',
        'default_code_exposure_policy' => 'full_code_artifacts',
        'created_by_user_id' => $adminId,
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    return $projectId;
}
```

- [ ] **Step 2: Run failing memory tests**

Run:

```bash
cd backend && php artisan test --filter=ProjectMemoryDashboardApiTest
```

Expected: FAIL because memory dashboard routes and controller do not exist.

- [ ] **Step 3: Add memory dashboard routes**

In `backend/routes/web.php`, import the controller:

```php
use App\Http\Controllers\DashboardMemoryController;
```

Add routes inside the `/api/dashboard` group:

```php
Route::get('/projects/{project}/memory', [DashboardMemoryController::class, 'index']);
Route::post('/projects/{project}/memory', [DashboardMemoryController::class, 'store']);
```

- [ ] **Step 4: Add memory reader methods**

In `backend/app/Dashboard/DashboardApiReader.php`, add:

```php
/**
 * @return array{entries: list<array<string, mixed>>}
 */
public function projectMemory(string $projectId): array
{
    $this->abortUnlessProjectReadable($projectId);

    $entries = DB::table('project_memory_entries')
        ->where('project_id', $projectId)
        ->orderByDesc('occurred_at')
        ->orderByDesc('created_at')
        ->limit(100)
        ->get()
        ->map(fn (object $entry): array => $this->memoryEntry($entry))
        ->all();

    return ['entries' => $entries];
}

private function memoryEntry(object $entry): array
{
    return [
        'id' => (string) $entry->id,
        'project_id' => (string) $entry->project_id,
        'repository_id' => $entry->repository_id ? (string) $entry->repository_id : null,
        'task_id' => $entry->task_id ? (string) $entry->task_id : null,
        'run_id' => $entry->run_id ? (string) $entry->run_id : null,
        'author_user_id' => $entry->author_user_id === null ? null : (int) $entry->author_user_id,
        'agent_key' => $entry->agent_key ? (string) $entry->agent_key : null,
        'source' => (string) $entry->source,
        'kind' => (string) $entry->kind,
        'completeness' => (string) $entry->completeness,
        'summary' => (string) $entry->summary,
        'payload' => json_decode((string) $entry->payload, true, flags: JSON_THROW_ON_ERROR),
        'occurred_at' => (string) $entry->occurred_at,
        'created_at' => (string) $entry->created_at,
    ];
}
```

- [ ] **Step 5: Add memory controller**

Create `backend/app/Http/Controllers/DashboardMemoryController.php`:

```php
<?php

namespace App\Http\Controllers;

use App\Dashboard\DashboardApiReader;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class DashboardMemoryController extends Controller
{
    public function __construct(private readonly DashboardApiReader $reader)
    {
    }

    public function index(Request $request, string $project): JsonResponse
    {
        $this->abortUnlessDashboardReader($request);

        return response()->json($this->reader->projectMemory($project));
    }

    public function store(Request $request, string $project): JsonResponse
    {
        $this->abortUnlessDashboardMutator($request);
        abort_unless(DB::table('projects')->where('id', $project)->where('status', '!=', 'deleted')->exists(), 404);

        $validated = $request->validate([
            'repository_id' => ['sometimes', 'nullable', 'string', Rule::exists('repositories', 'id')->where(fn ($query) => $query->where('project_id', $project))],
            'task_id' => ['sometimes', 'nullable', 'string', Rule::exists('tasks', 'id')->where(fn ($query) => $query->where('project_id', $project))],
            'run_id' => ['sometimes', 'nullable', 'string', Rule::exists('runs', 'id')->where(fn ($query) => $query->where('project_id', $project))],
            'agent_key' => ['sometimes', 'nullable', 'string', Rule::in(['socrates', 'platon', 'aristoteles', 'local_agent'])],
            'kind' => ['required', 'string', Rule::in(['decision', 'implementation', 'clarification', 'risk', 'verification', 'handoff', 'incident', 'agent_note'])],
            'completeness' => ['sometimes', 'string', Rule::in(['complete', 'incomplete'])],
            'summary' => ['required', 'string', 'min:8', 'max:240'],
            'payload' => ['required', 'array'],
        ]);

        $memoryId = (string) Str::ulid();
        $now = now();

        DB::table('project_memory_entries')->insert([
            'id' => $memoryId,
            'project_id' => $project,
            'repository_id' => $validated['repository_id'] ?? null,
            'task_id' => $validated['task_id'] ?? null,
            'run_id' => $validated['run_id'] ?? null,
            'author_user_id' => $request->user()->id,
            'agent_key' => $validated['agent_key'] ?? null,
            'source' => 'dashboard_user',
            'kind' => $validated['kind'],
            'completeness' => $validated['completeness'] ?? 'complete',
            'summary' => $validated['summary'],
            'payload' => json_encode($validated['payload'], JSON_THROW_ON_ERROR),
            'occurred_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return response()->json($this->reader->projectMemory($project)['entries'][0], 201);
    }

    private function abortUnlessDashboardReader(Request $request): void
    {
        abort_unless($request->user()?->status === 'active', 403);
    }

    private function abortUnlessDashboardMutator(Request $request): void
    {
        abort_unless($request->user()?->status === 'active', 403);
    }
}
```

- [ ] **Step 6: Run memory tests**

Run:

```bash
cd backend && php artisan test --filter=ProjectMemoryDashboardApiTest
cd backend && php artisan test --filter=MultiprojectDashboardApiTest
```

Expected: both commands PASS.

- [ ] **Step 7: Commit memory API slice**

Run:

```bash
git diff -- backend/routes/web.php backend/app/Http/Controllers/DashboardMemoryController.php backend/app/Dashboard/DashboardApiReader.php backend/tests/Feature/Dashboard/ProjectMemoryDashboardApiTest.php
git add backend/routes/web.php backend/app/Http/Controllers/DashboardMemoryController.php backend/app/Dashboard/DashboardApiReader.php backend/tests/Feature/Dashboard/ProjectMemoryDashboardApiTest.php
git commit -m "feat: add project shared memory api"
```

Expected: commit contains only dashboard memory API changes.

## Task 4: Dashboard Agent Work Queue API

**Files:**
- Modify: `backend/routes/web.php`
- Create: `backend/app/Http/Controllers/DashboardAgentWorkController.php`
- Modify: `backend/app/Dashboard/DashboardApiReader.php`
- Create: `backend/tests/Feature/Dashboard/AgentWorkDashboardApiTest.php`
- Modify: `ai-sandbox/logbooks/LOGBOOK_PROJECT.md`

- [ ] **Step 1: Write failing dashboard work queue tests**

Create `backend/tests/Feature/Dashboard/AgentWorkDashboardApiTest.php`:

```php
<?php

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(\Database\Seeders\DevBoardSeeder::class);
});

it('lets a dashboard user create and queue local agent work', function () {
    $developer = agentWorkDashboardUserWithRole('Developer');
    $projectId = (string) DB::table('projects')->where('slug', 'demo-project')->value('id');
    $repositoryId = (string) DB::table('repositories')->where('project_id', $projectId)->value('id');
    $taskId = (string) DB::table('tasks')->where('project_id', $projectId)->value('id');

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
                'requires_memory_entry' => true,
            ],
        ])
        ->assertCreated()
        ->assertJsonPath('status', 'queued')
        ->assertJsonPath('assigned_agent_key', 'local_agent')
        ->json();

    $this->actingAs($developer)
        ->getJson("/api/dashboard/projects/{$projectId}/agent-work")
        ->assertOk()
        ->assertJsonPath('items.0.id', $workItem['id'])
        ->assertJsonPath('items.0.title', 'Inspect task before implementation');
});

it('cancels queued work before the local agent claims it', function () {
    $developer = agentWorkDashboardUserWithRole('Developer');
    $projectId = (string) DB::table('projects')->where('slug', 'demo-project')->value('id');

    $workItem = $this->actingAs($developer)->postJson("/api/dashboard/projects/{$projectId}/agent-work", [
        'assigned_agent_key' => 'local_agent',
        'priority' => 'normal',
        'title' => 'Cancel me',
        'prompt' => 'This request will be canceled.',
        'payload' => ['request' => 'No execution needed.'],
    ])->assertCreated()->json();

    $this->actingAs($developer)
        ->postJson("/api/dashboard/agent-work/{$workItem['id']}/cancel", [
            'message' => 'The task was rewritten.',
        ])
        ->assertOk()
        ->assertJsonPath('status', 'canceled');
});

function agentWorkDashboardUserWithRole(string $roleName): User
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
```

- [ ] **Step 2: Run failing dashboard work queue tests**

Run:

```bash
cd backend && php artisan test --filter=AgentWorkDashboardApiTest
```

Expected: FAIL because dashboard agent work routes and controller do not exist.

- [ ] **Step 3: Add dashboard work routes**

In `backend/routes/web.php`, import:

```php
use App\Http\Controllers\DashboardAgentWorkController;
```

Add routes inside the `/api/dashboard` group:

```php
Route::get('/projects/{project}/agent-work', [DashboardAgentWorkController::class, 'index']);
Route::post('/projects/{project}/agent-work', [DashboardAgentWorkController::class, 'store']);
Route::post('/agent-work/{workItem}/cancel', [DashboardAgentWorkController::class, 'cancel']);
```

- [ ] **Step 4: Add agent work reader methods**

In `backend/app/Dashboard/DashboardApiReader.php`, add:

```php
/**
 * @return array{items: list<array<string, mixed>>}
 */
public function projectAgentWork(string $projectId): array
{
    $this->abortUnlessProjectReadable($projectId);

    $items = DB::table('agent_work_items')
        ->where('project_id', $projectId)
        ->orderByRaw("case priority when 'urgent' then 1 when 'high' then 2 when 'normal' then 3 else 4 end")
        ->orderByDesc('created_at')
        ->limit(100)
        ->get()
        ->map(fn (object $item): array => $this->agentWorkItem($item))
        ->all();

    return ['items' => $items];
}

public function agentWorkItemById(string $workItemId): array
{
    $item = DB::table('agent_work_items')->where('id', $workItemId)->first();
    abort_unless($item, 404);

    return $this->agentWorkItem($item);
}

private function agentWorkItem(object $item): array
{
    return [
        'id' => (string) $item->id,
        'project_id' => (string) $item->project_id,
        'repository_id' => $item->repository_id ? (string) $item->repository_id : null,
        'task_id' => $item->task_id ? (string) $item->task_id : null,
        'requested_by_user_id' => $item->requested_by_user_id === null ? null : (int) $item->requested_by_user_id,
        'assigned_agent_key' => (string) $item->assigned_agent_key,
        'status' => (string) $item->status,
        'priority' => (string) $item->priority,
        'title' => (string) $item->title,
        'prompt' => (string) $item->prompt,
        'payload' => json_decode((string) $item->payload, true, flags: JSON_THROW_ON_ERROR),
        'requires_memory_entry' => (bool) $item->requires_memory_entry,
        'result_memory_entry_id' => $item->result_memory_entry_id ? (string) $item->result_memory_entry_id : null,
        'claimed_by_device_id' => $item->claimed_by_device_id ? (string) $item->claimed_by_device_id : null,
        'claimed_at' => $item->claimed_at ? (string) $item->claimed_at : null,
        'heartbeat_at' => $item->heartbeat_at ? (string) $item->heartbeat_at : null,
        'completed_at' => $item->completed_at ? (string) $item->completed_at : null,
        'failed_at' => $item->failed_at ? (string) $item->failed_at : null,
        'canceled_at' => $item->canceled_at ? (string) $item->canceled_at : null,
        'failure_reason' => $item->failure_reason ? (string) $item->failure_reason : null,
        'created_at' => (string) $item->created_at,
        'updated_at' => (string) $item->updated_at,
    ];
}
```

- [ ] **Step 5: Add dashboard work controller**

Create `backend/app/Http/Controllers/DashboardAgentWorkController.php`:

```php
<?php

namespace App\Http\Controllers;

use App\Dashboard\DashboardApiReader;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class DashboardAgentWorkController extends Controller
{
    public function __construct(private readonly DashboardApiReader $reader)
    {
    }

    public function index(Request $request, string $project): JsonResponse
    {
        $this->abortUnlessActiveUser($request);

        return response()->json($this->reader->projectAgentWork($project));
    }

    public function store(Request $request, string $project): JsonResponse
    {
        $this->abortUnlessActiveUser($request);
        abort_unless(DB::table('projects')->where('id', $project)->where('status', '!=', 'deleted')->exists(), 404);

        $validated = $request->validate([
            'repository_id' => ['sometimes', 'nullable', 'string', Rule::exists('repositories', 'id')->where(fn ($query) => $query->where('project_id', $project))],
            'task_id' => ['sometimes', 'nullable', 'string', Rule::exists('tasks', 'id')->where(fn ($query) => $query->where('project_id', $project))],
            'assigned_agent_key' => ['required', 'string', Rule::in(['socrates', 'platon', 'aristoteles', 'local_agent'])],
            'priority' => ['sometimes', 'string', Rule::in(['low', 'normal', 'high', 'urgent'])],
            'title' => ['required', 'string', 'min:4', 'max:180'],
            'prompt' => ['required', 'string', 'min:8', 'max:8000'],
            'payload' => ['sometimes', 'array'],
            'requires_memory_entry' => ['sometimes', 'boolean'],
        ]);

        $workItemId = (string) Str::ulid();
        $now = now();

        DB::table('agent_work_items')->insert([
            'id' => $workItemId,
            'project_id' => $project,
            'repository_id' => $validated['repository_id'] ?? null,
            'task_id' => $validated['task_id'] ?? null,
            'requested_by_user_id' => $request->user()->id,
            'assigned_agent_key' => $validated['assigned_agent_key'],
            'status' => 'queued',
            'priority' => $validated['priority'] ?? 'normal',
            'title' => $validated['title'],
            'prompt' => $validated['prompt'],
            'payload' => json_encode($validated['payload'] ?? [], JSON_THROW_ON_ERROR),
            'requires_memory_entry' => $validated['requires_memory_entry'] ?? true,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $this->recordEvent($workItemId, 'queued', $request->user()->id, null, 'Dashboard user queued work for an agent.', []);

        return response()->json($this->reader->agentWorkItemById($workItemId), 201);
    }

    public function cancel(Request $request, string $workItem): JsonResponse
    {
        $this->abortUnlessActiveUser($request);

        $item = DB::table('agent_work_items')->where('id', $workItem)->first();
        abort_unless($item, 404);
        abort_if(in_array($item->status, ['completed', 'completed_with_incomplete_memory'], true), 409, 'Completed work cannot be canceled.');

        DB::table('agent_work_items')->where('id', $workItem)->update([
            'status' => 'canceled',
            'canceled_at' => now(),
            'updated_at' => now(),
        ]);

        $this->recordEvent($workItem, 'canceled', $request->user()->id, null, $request->string('message')->toString(), []);

        return response()->json($this->reader->agentWorkItemById($workItem));
    }

    private function recordEvent(string $workItemId, string $eventType, ?int $userId, ?string $deviceId, ?string $message, array $payload): void
    {
        DB::table('agent_work_item_events')->insert([
            'id' => (string) Str::ulid(),
            'agent_work_item_id' => $workItemId,
            'actor_user_id' => $userId,
            'actor_device_id' => $deviceId,
            'event_type' => $eventType,
            'message' => $message,
            'payload' => json_encode($payload, JSON_THROW_ON_ERROR),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function abortUnlessActiveUser(Request $request): void
    {
        abort_unless($request->user()?->status === 'active', 403);
    }
}
```

- [ ] **Step 6: Run dashboard work queue tests**

Run:

```bash
cd backend && php artisan test --filter=AgentWorkDashboardApiTest
cd backend && php artisan test --filter=ProjectMemoryDashboardApiTest
```

Expected: both commands PASS.

- [ ] **Step 7: Commit dashboard work queue slice**

Run:

```bash
git diff -- backend/routes/web.php backend/app/Http/Controllers/DashboardAgentWorkController.php backend/app/Dashboard/DashboardApiReader.php backend/tests/Feature/Dashboard/AgentWorkDashboardApiTest.php
git add backend/routes/web.php backend/app/Http/Controllers/DashboardAgentWorkController.php backend/app/Dashboard/DashboardApiReader.php backend/tests/Feature/Dashboard/AgentWorkDashboardApiTest.php
git commit -m "feat: add dashboard agent work queue api"
```

Expected: commit contains only dashboard agent work changes.

## Task 5: Plugin Shared Memory Pack And Work Queue API

**Files:**
- Modify: `backend/routes/api.php`
- Create: `backend/app/Http/Controllers/Plugin/SharedMemoryPackController.php`
- Create: `backend/app/Http/Controllers/Plugin/AgentWorkItemController.php`
- Create: `backend/tests/Feature/Plugin/PluginSharedMemoryAndWorkQueueTest.php`
- Modify: `ai-sandbox/logbooks/LOGBOOK_PROJECT.md`

- [ ] **Step 1: Write failing plugin API tests**

Create `backend/tests/Feature/Plugin/PluginSharedMemoryAndWorkQueueTest.php`:

```php
<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(\Database\Seeders\DevBoardSeeder::class);
});

it('serves shared memory pack and lets the local agent claim and complete work', function () {
    $fixture = pluginWorkQueueFixture();

    DB::table('project_memory_entries')->insert([
        'id' => (string) Str::ulid(),
        'project_id' => $fixture['project_id'],
        'repository_id' => $fixture['repository_id'],
        'task_id' => null,
        'run_id' => null,
        'author_user_id' => null,
        'agent_key' => 'socrates',
        'source' => 'server_agent',
        'kind' => 'decision',
        'completeness' => 'complete',
        'summary' => 'Use shared memory before local work starts.',
        'payload' => json_encode(['why' => 'Avoid duplicate or conflicting local edits.'], JSON_THROW_ON_ERROR),
        'occurred_at' => now(),
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $workItemId = (string) Str::ulid();
    DB::table('agent_work_items')->insert([
        'id' => $workItemId,
        'project_id' => $fixture['project_id'],
        'repository_id' => $fixture['repository_id'],
        'task_id' => null,
        'requested_by_user_id' => null,
        'assigned_agent_key' => 'local_agent',
        'status' => 'queued',
        'priority' => 'high',
        'title' => 'Preflight memory sync',
        'prompt' => 'Fetch shared memory before editing files.',
        'payload' => json_encode(['request' => 'Check recent changes.'], JSON_THROW_ON_ERROR),
        'requires_memory_entry' => true,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $this->getJson(
        "/api/plugin/v1/projects/{$fixture['project_id']}/shared-memory-pack?repository_id={$fixture['repository_id']}",
        pluginWorkQueueHeaders($fixture),
    )
        ->assertOk()
        ->assertJsonPath('project_id', $fixture['project_id'])
        ->assertJsonPath('entries.0.summary', 'Use shared memory before local work starts.');

    $lease = $this->postJson("/api/plugin/v1/agent-work-items/{$workItemId}/claim", [
            'protocol_version' => 'v1',
            'local_workspace_id' => $fixture['workspace_id'],
        ], pluginWorkQueueHeaders($fixture))
        ->assertOk()
        ->assertJsonPath('item.status', 'claimed')
        ->json('lease_token');

    $this->postJson("/api/plugin/v1/agent-work-items/{$workItemId}/complete", [
            'protocol_version' => 'v1',
            'lease_token' => $lease,
            'memory_entry' => [
                'kind' => 'implementation',
                'summary' => 'Completed preflight sync.',
                'payload' => [
                    'why' => 'The local agent checked memory before changing code.',
                    'changed' => [],
                    'tests' => [],
                    'skipped_checks' => [],
                    'risks' => [],
                ],
            ],
        ], pluginWorkQueueHeaders($fixture))
        ->assertOk()
        ->assertJsonPath('item.status', 'completed')
        ->assertJsonPath('memory_entry.summary', 'Completed preflight sync.');
});

function pluginWorkQueueFixture(): array
{
    $adminId = (int) DB::table('users')->where('email', 'admin@example.com')->value('id');
    $projectId = (string) DB::table('projects')->where('slug', 'demo-project')->value('id');
    $repositoryId = (string) DB::table('repositories')->where('project_id', $projectId)->value('id');
    $deviceId = (string) Str::ulid();
    $workspaceId = (string) Str::ulid();
    $tokenId = (string) Str::ulid();
    $secret = 'plugin-work-queue-secret';
    $prefix = 'devb_live_'.$tokenId;
    $plainToken = $prefix.'|'.$secret;
    $now = now();

    DB::table('devices')->insert([
        'id' => $deviceId,
        'user_id' => $adminId,
        'name' => 'Plugin Work Queue Device',
        'fingerprint_hash' => 'sha256:plugin-work-queue-device',
        'platform_os' => 'linux',
        'platform_arch' => 'amd64',
        'plugin_version' => '0.9.5',
        'last_seen_at' => $now,
        'status' => 'active',
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    DB::table('api_tokens')->insert([
        'id' => $tokenId,
        'token_prefix' => $prefix,
        'token_hash' => hash('sha256', $secret),
        'user_id' => $adminId,
        'device_id' => $deviceId,
        'name' => 'Plugin work queue token',
        'scopes' => json_encode(['projects.read', 'repositories.read', 'runs.write'], JSON_THROW_ON_ERROR),
        'expires_at' => null,
        'revoked_at' => null,
        'last_used_at' => null,
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    DB::table('local_workspaces')->insert([
        'id' => $workspaceId,
        'repository_id' => $repositoryId,
        'device_id' => $deviceId,
        'local_root_hash' => 'sha256:work-queue-root',
        'display_path' => '/repo',
        'current_branch' => 'main',
        'last_head_sha' => 'abc123',
        'dirty_status' => 'clean',
        'last_snapshot_id' => null,
        'last_seen_at' => $now,
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    return [
        'project_id' => $projectId,
        'repository_id' => $repositoryId,
        'device_id' => $deviceId,
        'workspace_id' => $workspaceId,
        'plain_token' => $plainToken,
    ];
}

function pluginWorkQueueHeaders(array $fixture): array
{
    return [
        'Authorization' => 'Bearer '.$fixture['plain_token'],
        'X-DevBoard-Protocol' => 'v1',
        'X-DevBoard-Plugin-Version' => '0.9.5',
        'X-DevBoard-Device-Id' => $fixture['device_id'],
    ];
}
```

- [ ] **Step 2: Run failing plugin API tests**

Run:

```bash
cd backend && php artisan test --filter=PluginSharedMemoryAndWorkQueueTest
```

Expected: FAIL because plugin memory/work routes do not exist.

- [ ] **Step 3: Add plugin routes**

In `backend/routes/api.php`, import:

```php
use App\Http\Controllers\Plugin\AgentWorkItemController;
use App\Http\Controllers\Plugin\SharedMemoryPackController;
```

Inside the plugin `auth.plugin` route group, add:

```php
Route::get('/projects/{project}/shared-memory-pack', SharedMemoryPackController::class)->middleware('plugin.scope:projects.read');
Route::get('/agent-work-items', [AgentWorkItemController::class, 'index'])->middleware('plugin.scope:projects.read');
Route::post('/agent-work-items/{workItem}/claim', [AgentWorkItemController::class, 'claim'])->middleware('plugin.scope:runs.write');
Route::post('/agent-work-items/{workItem}/heartbeat', [AgentWorkItemController::class, 'heartbeat'])->middleware('plugin.scope:runs.write');
Route::post('/agent-work-items/{workItem}/complete', [AgentWorkItemController::class, 'complete'])->middleware('plugin.scope:runs.write');
Route::post('/agent-work-items/{workItem}/fail', [AgentWorkItemController::class, 'fail'])->middleware('plugin.scope:runs.write');
```

- [ ] **Step 4: Add shared memory pack controller**

Create `backend/app/Http/Controllers/Plugin/SharedMemoryPackController.php`:

```php
<?php

namespace App\Http\Controllers\Plugin;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class SharedMemoryPackController extends Controller
{
    public function __invoke(Request $request, string $project): JsonResponse
    {
        abort_unless(DB::table('projects')->where('id', $project)->where('status', '!=', 'deleted')->exists(), 404);

        $repositoryId = $request->query('repository_id');
        if ($repositoryId !== null) {
            abort_unless(DB::table('repositories')->where('id', $repositoryId)->where('project_id', $project)->exists(), 404);
        }

        $query = DB::table('project_memory_entries')->where('project_id', $project);
        if ($repositoryId !== null) {
            $query->where(function ($inner) use ($repositoryId): void {
                $inner->whereNull('repository_id')->orWhere('repository_id', $repositoryId);
            });
        }

        $entries = $query
            ->orderByDesc('occurred_at')
            ->orderByDesc('created_at')
            ->limit(50)
            ->get()
            ->map(fn (object $entry): array => [
                'id' => (string) $entry->id,
                'project_id' => (string) $entry->project_id,
                'repository_id' => $entry->repository_id ? (string) $entry->repository_id : null,
                'task_id' => $entry->task_id ? (string) $entry->task_id : null,
                'run_id' => $entry->run_id ? (string) $entry->run_id : null,
                'agent_key' => $entry->agent_key ? (string) $entry->agent_key : null,
                'source' => (string) $entry->source,
                'kind' => (string) $entry->kind,
                'completeness' => (string) $entry->completeness,
                'summary' => (string) $entry->summary,
                'payload' => json_decode((string) $entry->payload, true, flags: JSON_THROW_ON_ERROR),
                'occurred_at' => (string) $entry->occurred_at,
            ])
            ->all();

        return response()->json([
            'project_id' => $project,
            'repository_id' => $repositoryId,
            'entries' => $entries,
            'generated_at' => now()->toIso8601String(),
        ]);
    }
}
```

- [ ] **Step 5: Add plugin work controller**

Create `backend/app/Http/Controllers/Plugin/AgentWorkItemController.php` with lifecycle methods:

```php
<?php

namespace App\Http\Controllers\Plugin;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class AgentWorkItemController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $auth = $request->attributes->get('plugin_auth');
        $device = $auth['device'];

        $items = DB::table('agent_work_items')
            ->whereIn('status', ['queued', 'claimed', 'running'])
            ->where('assigned_agent_key', 'local_agent')
            ->where(function ($query) use ($device): void {
                $query->whereNull('claimed_by_device_id')->orWhere('claimed_by_device_id', $device->id);
            })
            ->orderByRaw("case priority when 'urgent' then 1 when 'high' then 2 when 'normal' then 3 else 4 end")
            ->orderBy('created_at')
            ->limit(50)
            ->get()
            ->map(fn (object $item): array => $this->item($item))
            ->all();

        return response()->json(['items' => $items]);
    }

    public function claim(Request $request, string $workItem): JsonResponse
    {
        $auth = $request->attributes->get('plugin_auth');
        $device = $auth['device'];
        $validated = $request->validate([
            'local_workspace_id' => ['required', 'string', Rule::exists('local_workspaces', 'id')->where(fn ($query) => $query->where('device_id', $device->id))],
        ]);

        $item = DB::table('agent_work_items')->where('id', $workItem)->lockForUpdate()->first();
        abort_unless($item, 404);
        abort_if(! in_array($item->status, ['queued', 'claimed', 'running'], true), 409, 'Work item cannot be claimed.');
        abort_if($item->claimed_by_device_id !== null && (string) $item->claimed_by_device_id !== (string) $device->id, 409, 'Work item is already claimed by another device.');

        $leaseToken = Str::random(48);
        $now = now();

        DB::table('agent_work_items')->where('id', $workItem)->update([
            'status' => 'claimed',
            'claimed_by_device_id' => $device->id,
            'claimed_at' => $item->claimed_at ?? $now,
            'heartbeat_at' => $now,
            'updated_at' => $now,
        ]);

        DB::table('agent_work_item_leases')->insert([
            'id' => (string) Str::ulid(),
            'agent_work_item_id' => $workItem,
            'device_id' => $device->id,
            'lease_token_hash' => hash('sha256', $leaseToken),
            'expires_at' => $now->copy()->addMinutes(30),
            'released_at' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $this->event($workItem, null, $device->id, 'claimed', 'Local agent claimed work.', ['local_workspace_id' => $validated['local_workspace_id']]);

        return response()->json([
            'item' => $this->item(DB::table('agent_work_items')->where('id', $workItem)->first()),
            'lease_token' => $leaseToken,
        ]);
    }

    public function heartbeat(Request $request, string $workItem): JsonResponse
    {
        $auth = $request->attributes->get('plugin_auth');
        $device = $auth['device'];
        $this->assertClaimedByDevice($workItem, (string) $device->id);

        DB::table('agent_work_items')->where('id', $workItem)->update([
            'status' => 'running',
            'heartbeat_at' => now(),
            'updated_at' => now(),
        ]);

        $this->event($workItem, null, $device->id, 'heartbeat', $request->input('message'), []);

        return response()->json(['item' => $this->item(DB::table('agent_work_items')->where('id', $workItem)->first())]);
    }

    public function complete(Request $request, string $workItem): JsonResponse
    {
        $auth = $request->attributes->get('plugin_auth');
        $device = $auth['device'];
        $item = $this->assertClaimedByDevice($workItem, (string) $device->id);
        $validated = $request->validate([
            'lease_token' => ['required', 'string'],
            'memory_entry' => ['sometimes', 'array'],
            'memory_entry.kind' => ['required_with:memory_entry', 'string', Rule::in(['decision', 'implementation', 'clarification', 'risk', 'verification', 'handoff', 'incident', 'agent_note'])],
            'memory_entry.summary' => ['required_with:memory_entry', 'string', 'min:8', 'max:240'],
            'memory_entry.payload' => ['required_with:memory_entry', 'array'],
        ]);

        $this->assertLease($workItem, (string) $device->id, $validated['lease_token']);

        $memoryEntry = null;
        if (isset($validated['memory_entry'])) {
            $memoryEntry = $this->createMemoryEntry($item, $validated['memory_entry']);
        }

        $completeStatus = $item->requires_memory_entry && $memoryEntry === null ? 'completed_with_incomplete_memory' : 'completed';
        DB::table('agent_work_items')->where('id', $workItem)->update([
            'status' => $completeStatus,
            'result_memory_entry_id' => $memoryEntry['id'] ?? null,
            'completed_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('agent_work_item_leases')->where('agent_work_item_id', $workItem)->whereNull('released_at')->update([
            'released_at' => now(),
            'updated_at' => now(),
        ]);

        $this->event($workItem, null, $device->id, 'completed', 'Local agent completed work.', ['memory_entry_id' => $memoryEntry['id'] ?? null]);

        return response()->json([
            'item' => $this->item(DB::table('agent_work_items')->where('id', $workItem)->first()),
            'memory_entry' => $memoryEntry,
        ]);
    }

    public function fail(Request $request, string $workItem): JsonResponse
    {
        $auth = $request->attributes->get('plugin_auth');
        $device = $auth['device'];
        $this->assertClaimedByDevice($workItem, (string) $device->id);
        $validated = $request->validate([
            'lease_token' => ['required', 'string'],
            'failure_reason' => ['required', 'string', 'min:4', 'max:2000'],
        ]);
        $this->assertLease($workItem, (string) $device->id, $validated['lease_token']);

        DB::table('agent_work_items')->where('id', $workItem)->update([
            'status' => 'failed',
            'failed_at' => now(),
            'failure_reason' => $validated['failure_reason'],
            'updated_at' => now(),
        ]);

        DB::table('agent_work_item_leases')->where('agent_work_item_id', $workItem)->whereNull('released_at')->update([
            'released_at' => now(),
            'updated_at' => now(),
        ]);

        $this->event($workItem, null, $device->id, 'failed', $validated['failure_reason'], []);

        return response()->json(['item' => $this->item(DB::table('agent_work_items')->where('id', $workItem)->first())]);
    }

    private function assertClaimedByDevice(string $workItem, string $deviceId): object
    {
        $item = DB::table('agent_work_items')->where('id', $workItem)->first();
        abort_unless($item, 404);
        abort_unless((string) $item->claimed_by_device_id === $deviceId, 409, 'Work item is not claimed by this device.');

        return $item;
    }

    private function assertLease(string $workItem, string $deviceId, string $leaseToken): void
    {
        $valid = DB::table('agent_work_item_leases')
            ->where('agent_work_item_id', $workItem)
            ->where('device_id', $deviceId)
            ->where('lease_token_hash', hash('sha256', $leaseToken))
            ->whereNull('released_at')
            ->where('expires_at', '>', now())
            ->exists();

        abort_unless($valid, 409, 'Work item lease is invalid or expired.');
    }

    private function createMemoryEntry(object $item, array $memory): array
    {
        $memoryId = (string) Str::ulid();
        $now = now();

        DB::table('project_memory_entries')->insert([
            'id' => $memoryId,
            'project_id' => $item->project_id,
            'repository_id' => $item->repository_id,
            'task_id' => $item->task_id,
            'run_id' => null,
            'author_user_id' => null,
            'agent_key' => 'local_agent',
            'source' => 'local_agent',
            'kind' => $memory['kind'],
            'completeness' => 'complete',
            'summary' => $memory['summary'],
            'payload' => json_encode($memory['payload'], JSON_THROW_ON_ERROR),
            'occurred_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $entry = DB::table('project_memory_entries')->where('id', $memoryId)->first();

        return [
            'id' => (string) $entry->id,
            'project_id' => (string) $entry->project_id,
            'repository_id' => $entry->repository_id ? (string) $entry->repository_id : null,
            'task_id' => $entry->task_id ? (string) $entry->task_id : null,
            'source' => (string) $entry->source,
            'kind' => (string) $entry->kind,
            'summary' => (string) $entry->summary,
            'payload' => json_decode((string) $entry->payload, true, flags: JSON_THROW_ON_ERROR),
        ];
    }

    private function item(object $item): array
    {
        return [
            'id' => (string) $item->id,
            'project_id' => (string) $item->project_id,
            'repository_id' => $item->repository_id ? (string) $item->repository_id : null,
            'task_id' => $item->task_id ? (string) $item->task_id : null,
            'assigned_agent_key' => (string) $item->assigned_agent_key,
            'status' => (string) $item->status,
            'priority' => (string) $item->priority,
            'title' => (string) $item->title,
            'prompt' => (string) $item->prompt,
            'payload' => json_decode((string) $item->payload, true, flags: JSON_THROW_ON_ERROR),
            'requires_memory_entry' => (bool) $item->requires_memory_entry,
            'result_memory_entry_id' => $item->result_memory_entry_id ? (string) $item->result_memory_entry_id : null,
            'claimed_by_device_id' => $item->claimed_by_device_id ? (string) $item->claimed_by_device_id : null,
            'heartbeat_at' => $item->heartbeat_at ? (string) $item->heartbeat_at : null,
            'created_at' => (string) $item->created_at,
            'updated_at' => (string) $item->updated_at,
        ];
    }

    private function event(string $workItemId, ?int $userId, ?string $deviceId, string $eventType, ?string $message, array $payload): void
    {
        DB::table('agent_work_item_events')->insert([
            'id' => (string) Str::ulid(),
            'agent_work_item_id' => $workItemId,
            'actor_user_id' => $userId,
            'actor_device_id' => $deviceId,
            'event_type' => $eventType,
            'message' => $message,
            'payload' => json_encode($payload, JSON_THROW_ON_ERROR),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
```

- [ ] **Step 6: Run plugin API tests**

Run:

```bash
cd backend && php artisan test --filter=PluginSharedMemoryAndWorkQueueTest
cd backend && php artisan test --filter=PluginRepositoryApiTest
```

Expected: both commands PASS.

- [ ] **Step 7: Commit plugin API slice**

Run:

```bash
git diff -- backend/routes/api.php backend/app/Http/Controllers/Plugin/SharedMemoryPackController.php backend/app/Http/Controllers/Plugin/AgentWorkItemController.php backend/tests/Feature/Plugin/PluginSharedMemoryAndWorkQueueTest.php
git add backend/routes/api.php backend/app/Http/Controllers/Plugin/SharedMemoryPackController.php backend/app/Http/Controllers/Plugin/AgentWorkItemController.php backend/tests/Feature/Plugin/PluginSharedMemoryAndWorkQueueTest.php
git commit -m "feat: add plugin shared memory work queue api"
```

Expected: commit contains only plugin memory/work queue API changes.

## Task 6: Python Plugin Client, CLI, And MCP Tools

**Files:**
- Modify: `plugin/src/devboard_plugin/client.py`
- Modify: `plugin/src/devboard_plugin/mcp_tools.py`
- Modify: `plugin/src/devboard_plugin/cli.py`
- Create: `plugin/tests/test_work_queue_tools.py`
- Modify: `ai-sandbox/logbooks/LOGBOOK_PROJECT.md`

- [ ] **Step 1: Write failing Python tests**

Create `plugin/tests/test_work_queue_tools.py`:

```python
from __future__ import annotations

import httpx

from devboard_plugin.client import DevBoardClient
from devboard_plugin.mcp_tools import (
    devboard_claim_work_item,
    devboard_complete_work_item,
    devboard_list_work_items,
    devboard_shared_memory_pack,
)


def test_client_fetches_shared_memory_pack() -> None:
    def handler(request: httpx.Request) -> httpx.Response:
        assert request.method == "GET"
        assert request.url.path == "/api/plugin/v1/projects/project-1/shared-memory-pack"
        assert request.url.params["repository_id"] == "repo-1"
        return httpx.Response(200, json={"project_id": "project-1", "entries": []})

    client = DevBoardClient(
        base_url="https://devboard.test",
        token="token",
        device_id="device-1",
        transport=httpx.MockTransport(handler),
    )

    assert client.shared_memory_pack("project-1", "repo-1") == {"project_id": "project-1", "entries": []}


def test_mcp_work_item_helpers_call_client_methods(monkeypatch) -> None:
    calls: list[tuple[str, object]] = []

    class FakeClient:
        def shared_memory_pack(self, project_id: str, repository_id: str | None = None):
            calls.append(("shared_memory_pack", (project_id, repository_id)))
            return {"entries": []}

        def list_work_items(self):
            calls.append(("list_work_items", None))
            return {"items": []}

        def claim_work_item(self, work_item_id: str, local_workspace_id: str):
            calls.append(("claim_work_item", (work_item_id, local_workspace_id)))
            return {"lease_token": "lease"}

        def complete_work_item(self, work_item_id: str, lease_token: str, memory_entry: dict):
            calls.append(("complete_work_item", (work_item_id, lease_token, memory_entry)))
            return {"item": {"status": "completed"}}

    monkeypatch.setattr("devboard_plugin.mcp_tools.client_from_options", lambda server_url=None: FakeClient())

    assert devboard_shared_memory_pack("project-1", "repo-1") == {"entries": []}
    assert devboard_list_work_items() == {"items": []}
    assert devboard_claim_work_item("work-1", "workspace-1") == {"lease_token": "lease"}
    assert devboard_complete_work_item("work-1", "lease", {"kind": "implementation", "summary": "done", "payload": {}}) == {"item": {"status": "completed"}}
    assert calls == [
        ("shared_memory_pack", ("project-1", "repo-1")),
        ("list_work_items", None),
        ("claim_work_item", ("work-1", "workspace-1")),
        ("complete_work_item", ("work-1", "lease", {"kind": "implementation", "summary": "done", "payload": {}})),
    ]
```

- [ ] **Step 2: Run failing Python tests**

Run:

```bash
cd plugin && python -m pytest -q plugin/tests/test_work_queue_tools.py
```

Expected: FAIL because the client and MCP helper functions do not exist.

- [ ] **Step 3: Add client methods**

In `plugin/src/devboard_plugin/client.py`, add methods to `DevBoardClient`:

```python
    def shared_memory_pack(self, project_id: str, repository_id: str | None = None) -> dict[str, Any]:
        path = f"/api/plugin/v1/projects/{project_id}/shared-memory-pack"
        if repository_id:
            path += f"?repository_id={repository_id}"
        return self.get(path)

    def list_work_items(self) -> dict[str, Any]:
        return self.get("/api/plugin/v1/agent-work-items")

    def claim_work_item(self, work_item_id: str, local_workspace_id: str) -> dict[str, Any]:
        return self.post(f"/api/plugin/v1/agent-work-items/{work_item_id}/claim", {"local_workspace_id": local_workspace_id})

    def heartbeat_work_item(self, work_item_id: str, message: str | None = None) -> dict[str, Any]:
        payload: dict[str, Any] = {}
        if message is not None:
            payload["message"] = message
        return self.post(f"/api/plugin/v1/agent-work-items/{work_item_id}/heartbeat", payload)

    def complete_work_item(self, work_item_id: str, lease_token: str, memory_entry: dict[str, Any] | None = None) -> dict[str, Any]:
        payload: dict[str, Any] = {"lease_token": lease_token}
        if memory_entry is not None:
            payload["memory_entry"] = memory_entry
        return self.post(f"/api/plugin/v1/agent-work-items/{work_item_id}/complete", payload)

    def fail_work_item(self, work_item_id: str, lease_token: str, failure_reason: str) -> dict[str, Any]:
        return self.post(
            f"/api/plugin/v1/agent-work-items/{work_item_id}/fail",
            {"lease_token": lease_token, "failure_reason": failure_reason},
        )
```

- [ ] **Step 4: Add MCP helper functions and registry entries**

In `plugin/src/devboard_plugin/mcp_tools.py`, add:

```python
def devboard_shared_memory_pack(
    project_id: str,
    repository_id: str | None = None,
    server_url: str | None = None,
) -> dict[str, Any]:
    """Fetch Shared Memory entries before local work starts."""
    return client_from_options(server_url).shared_memory_pack(project_id, repository_id)


def devboard_list_work_items(server_url: str | None = None) -> dict[str, Any]:
    """List queued or claimed work items for this local agent device."""
    return client_from_options(server_url).list_work_items()


def devboard_claim_work_item(
    work_item_id: str,
    local_workspace_id: str,
    server_url: str | None = None,
) -> dict[str, Any]:
    """Claim a server-requested local agent work item."""
    return client_from_options(server_url).claim_work_item(work_item_id, local_workspace_id)


def devboard_complete_work_item(
    work_item_id: str,
    lease_token: str,
    memory_entry: dict[str, Any],
    server_url: str | None = None,
) -> dict[str, Any]:
    """Complete a work item and write the associated Shared Memory entry."""
    return client_from_options(server_url).complete_work_item(work_item_id, lease_token, memory_entry)


def devboard_fail_work_item(
    work_item_id: str,
    lease_token: str,
    failure_reason: str,
    server_url: str | None = None,
) -> dict[str, Any]:
    """Mark a server-requested work item as failed."""
    return client_from_options(server_url).fail_work_item(work_item_id, lease_token, failure_reason)
```

Add the functions to the existing `TOOL_REGISTRY` mapping using the same tuple or dict structure already present in the file. Preserve existing tool names and append:

```python
("devboard_shared_memory_pack", devboard_shared_memory_pack),
("devboard_list_work_items", devboard_list_work_items),
("devboard_claim_work_item", devboard_claim_work_item),
("devboard_complete_work_item", devboard_complete_work_item),
("devboard_fail_work_item", devboard_fail_work_item),
```

- [ ] **Step 5: Add CLI commands**

In `plugin/src/devboard_plugin/cli.py`, create a Typer app:

```python
work_app = typer.Typer(help="Read and complete server-requested local agent work")
app.add_typer(work_app, name="work")
```

Add commands:

```python
@work_app.command("list")
def work_list(
    server_url: str | None = typer.Option(None, "--server-url"),
    token: str | None = typer.Option(None, "--token"),
) -> None:
    echo_json(client_from_options(server_url, token).list_work_items())


@work_app.command("memory-pack")
def work_memory_pack(
    project_id: str,
    repository_id: str | None = typer.Option(None, "--repository-id"),
    server_url: str | None = typer.Option(None, "--server-url"),
    token: str | None = typer.Option(None, "--token"),
) -> None:
    echo_json(client_from_options(server_url, token).shared_memory_pack(project_id, repository_id))


@work_app.command("claim")
def work_claim(
    work_item_id: str,
    local_workspace_id: str,
    server_url: str | None = typer.Option(None, "--server-url"),
    token: str | None = typer.Option(None, "--token"),
) -> None:
    echo_json(client_from_options(server_url, token).claim_work_item(work_item_id, local_workspace_id))


@work_app.command("complete")
def work_complete(
    work_item_id: str,
    lease_token: str,
    summary: str = typer.Option(..., "--summary"),
    kind: str = typer.Option("implementation", "--kind"),
    server_url: str | None = typer.Option(None, "--server-url"),
    token: str | None = typer.Option(None, "--token"),
) -> None:
    memory_entry = {
        "kind": kind,
        "summary": summary,
        "payload": {
            "why": summary,
            "changed": [],
            "tests": [],
            "skipped_checks": [],
            "risks": [],
        },
    }
    echo_json(client_from_options(server_url, token).complete_work_item(work_item_id, lease_token, memory_entry))
```

- [ ] **Step 6: Run plugin tests**

Run:

```bash
cd plugin && python -m pytest -q plugin/tests/test_work_queue_tools.py
cd plugin && python -m pytest -q
```

Expected: both commands PASS.

- [ ] **Step 7: Commit plugin client slice**

Run:

```bash
git diff -- plugin/src/devboard_plugin/client.py plugin/src/devboard_plugin/mcp_tools.py plugin/src/devboard_plugin/cli.py plugin/tests/test_work_queue_tools.py
git add plugin/src/devboard_plugin/client.py plugin/src/devboard_plugin/mcp_tools.py plugin/src/devboard_plugin/cli.py plugin/tests/test_work_queue_tools.py
git commit -m "feat: expose local agent work queue tools"
```

Expected: commit contains only plugin client, CLI, MCP, and Python tests.

## Task 7: React API Contracts And Mock Data

**Files:**
- Modify: `/home/ubuntu/emergent_devboard_frontend/frontend/src/types/devboard.ts`
- Modify: `/home/ubuntu/emergent_devboard_frontend/frontend/src/api/devboardApi.ts`
- Modify: `/home/ubuntu/emergent_devboard_frontend/frontend/src/api/httpApi.ts`
- Modify: `/home/ubuntu/emergent_devboard_frontend/frontend/src/api/mockApi.ts`
- Modify: `/home/ubuntu/emergent_devboard_frontend/frontend/src/api/mockData.ts`

- [ ] **Step 1: Add TypeScript contract types**

In `src/types/devboard.ts`, add:

```ts
export type AgentKey = "socrates" | "platon" | "aristoteles" | "local_agent";
export type AgentWorkStatus = "draft" | "queued" | "claimed" | "running" | "completed" | "completed_with_incomplete_memory" | "failed" | "canceled";
export type AgentWorkPriority = "low" | "normal" | "high" | "urgent";
export type MemoryEntryKind = "decision" | "implementation" | "clarification" | "risk" | "verification" | "handoff" | "incident" | "agent_note";
export type MemoryCompleteness = "complete" | "incomplete";

export interface TaskRepositoryRef {
  id: string;
  name: string;
}

export interface TaskMutationInput {
  title?: string;
  description?: string | null;
  column?: TaskColumn;
  priority?: "low" | "normal" | "high" | "urgent";
  risk?: RiskLevel;
  owner_user_id?: number | null;
  repository_ids?: string[];
  acceptance_criteria?: string[];
}

export interface TaskCreateInput extends TaskMutationInput {
  title: string;
}

export interface ProjectMemoryEntry {
  id: string;
  project_id: string;
  repository_id: string | null;
  task_id: string | null;
  run_id: string | null;
  author_user_id: number | null;
  agent_key: AgentKey | null;
  source: "dashboard_user" | "server_agent" | "local_agent" | "system_event";
  kind: MemoryEntryKind;
  completeness: MemoryCompleteness;
  summary: string;
  payload: Record<string, unknown>;
  occurred_at: string;
  created_at: string;
}

export interface ProjectMemoryResponse {
  entries: ProjectMemoryEntry[];
}

export interface MemoryCreateInput {
  repository_id?: string | null;
  task_id?: string | null;
  run_id?: string | null;
  agent_key?: AgentKey | null;
  kind: MemoryEntryKind;
  completeness?: MemoryCompleteness;
  summary: string;
  payload: Record<string, unknown>;
}

export interface AgentWorkItem {
  id: string;
  project_id: string;
  repository_id: string | null;
  task_id: string | null;
  requested_by_user_id: number | null;
  assigned_agent_key: AgentKey;
  status: AgentWorkStatus;
  priority: AgentWorkPriority;
  title: string;
  prompt: string;
  payload: Record<string, unknown>;
  requires_memory_entry: boolean;
  result_memory_entry_id: string | null;
  claimed_by_device_id: string | null;
  heartbeat_at: string | null;
  failure_reason: string | null;
  created_at: string;
  updated_at: string;
}

export interface AgentWorkResponse {
  items: AgentWorkItem[];
}

export interface AgentWorkCreateInput {
  repository_id?: string | null;
  task_id?: string | null;
  assigned_agent_key: AgentKey;
  priority?: AgentWorkPriority;
  title: string;
  prompt: string;
  payload?: Record<string, unknown>;
  requires_memory_entry?: boolean;
}
```

Change `TaskCard.repositories` and `TaskDetail.repositories` to use `TaskRepositoryRef[]`. If existing UI expects strings, update those call sites in Task 9.

- [ ] **Step 2: Extend adapter interface**

In `src/api/devboardApi.ts`, add methods:

```ts
createTask(projectId: string, input: TaskCreateInput): Promise<TaskDetail>;
updateTask(taskId: string, input: TaskMutationInput): Promise<TaskDetail>;
getProjectMemory(projectId: string): Promise<ProjectMemoryResponse>;
createProjectMemory(projectId: string, input: MemoryCreateInput): Promise<ProjectMemoryEntry>;
getAgentWork(projectId: string): Promise<AgentWorkResponse>;
createAgentWork(projectId: string, input: AgentWorkCreateInput): Promise<AgentWorkItem>;
cancelAgentWork(workItemId: string, message?: string): Promise<AgentWorkItem>;
```

Import the new types from `../types/devboard`.

- [ ] **Step 3: Implement HTTP adapter methods**

In `src/api/httpApi.ts`, add:

```ts
createTask: (projectId, input) => request(`/api/dashboard/projects/${projectId}/tasks`, {
  method: "POST",
  body: JSON.stringify(input),
}),
updateTask: (taskId, input) => request(`/api/dashboard/tasks/${taskId}`, {
  method: "PATCH",
  body: JSON.stringify(input),
}),
getProjectMemory: (projectId) => request(`/api/dashboard/projects/${projectId}/memory`),
createProjectMemory: (projectId, input) => request(`/api/dashboard/projects/${projectId}/memory`, {
  method: "POST",
  body: JSON.stringify(input),
}),
getAgentWork: (projectId) => request(`/api/dashboard/projects/${projectId}/agent-work`),
createAgentWork: (projectId, input) => request(`/api/dashboard/projects/${projectId}/agent-work`, {
  method: "POST",
  body: JSON.stringify(input),
}),
cancelAgentWork: (workItemId, message) => request(`/api/dashboard/agent-work/${workItemId}/cancel`, {
  method: "POST",
  body: JSON.stringify({ message }),
}),
```

- [ ] **Step 4: Implement mock adapter methods**

In `src/api/mockData.ts`, export:

```ts
export const memoryEntries: ProjectMemoryEntry[] = [
  {
    id: "mem-1",
    project_id: "proj-1",
    repository_id: "repo-1",
    task_id: "task-1",
    run_id: null,
    author_user_id: null,
    agent_key: "local_agent",
    source: "local_agent",
    kind: "implementation",
    completeness: "complete",
    summary: "Created first project workspace memory slice.",
    payload: {
      why: "Local agent completed a task and recorded files, symbols, tests, and risks.",
      changed: [{ path: "src/pages/KanbanPage.tsx", symbols: ["KanbanPage"], change: "Added create task dialog." }],
      tests: ["npm run build"],
      skipped_checks: [],
      risks: [],
    },
    occurred_at: new Date().toISOString(),
    created_at: new Date().toISOString(),
  },
];

export const agentWorkItems: AgentWorkItem[] = [
  {
    id: "work-1",
    project_id: "proj-1",
    repository_id: "repo-1",
    task_id: "task-1",
    requested_by_user_id: 1,
    assigned_agent_key: "local_agent",
    status: "queued",
    priority: "high",
    title: "Preflight shared memory before coding",
    prompt: "Fetch recent memory and report conflicts before changing files.",
    payload: { request: "Check recent implementation memory for the selected task." },
    requires_memory_entry: true,
    result_memory_entry_id: null,
    claimed_by_device_id: null,
    heartbeat_at: null,
    failure_reason: null,
    created_at: new Date().toISOString(),
    updated_at: new Date().toISOString(),
  },
];
```

In `src/api/mockApi.ts`, use in-memory arrays to implement create/update/list methods. Keep IDs deterministic with `Date.now().toString()` only inside the mock adapter.

- [ ] **Step 5: Run TypeScript build**

Run:

```bash
cd /home/ubuntu/emergent_devboard_frontend/frontend && npm run build
```

Expected: build reaches completion. If it fails due to existing unrelated lint warnings that are configured as errors, capture the exact message in the logbook and fix only type errors introduced by this task.

- [ ] **Step 6: Commit React contract slice from the frontend repository if it has its own git metadata**

Run:

```bash
cd /home/ubuntu/emergent_devboard_frontend/frontend && git status --short
```

Expected: if this directory is inside a Git repo, commit only these files:

```bash
git add src/types/devboard.ts src/api/devboardApi.ts src/api/httpApi.ts src/api/mockApi.ts src/api/mockData.ts
git commit -m "feat: add project memory agent work api contracts"
```

If the frontend directory is not a Git repo, record the changed files in `ai-sandbox/logbooks/LOGBOOK_PROJECT.md` from the sandbox repo.

## Task 8: React Project Workspace Navigation

**Files:**
- Modify: `/home/ubuntu/emergent_devboard_frontend/frontend/src/App.tsx`
- Modify: `/home/ubuntu/emergent_devboard_frontend/frontend/src/lib/nav.ts`
- Modify: `/home/ubuntu/emergent_devboard_frontend/frontend/src/components/devboard/AppShell.tsx`
- Create: `/home/ubuntu/emergent_devboard_frontend/frontend/src/pages/EngineeringPage.tsx`

- [ ] **Step 1: Add workspace nav labels**

In `src/lib/nav.ts`, make these top-level labels visible:

```ts
export const primaryNavItems: NavItem[] = [
  { key: "overview", label: "Projects", path: "/projects", icon: FolderKanban },
  { key: "kanban", label: "Work", path: "/kanban", icon: KanbanSquare },
  { key: "ask", label: "Ask", path: "/ask", icon: MessagesSquare },
  { key: "memory", label: "Memory", path: "/memory", icon: BookOpenText },
  { key: "engineering", label: "Engineering", path: "/engineering", icon: Activity },
  { key: "settings", label: "Settings", path: "/admin", icon: Settings },
];
```

Keep role guards from the existing file. Import `MessagesSquare`, `BookOpenText`, and `Activity` from `lucide-react`.

- [ ] **Step 2: Scope nav links when a project is active**

In `AppShell.tsx`, extend the existing project path mapping:

```ts
const scopedProjectPaths: Record<string, string> = {
  kanban: `/projects/${activeProjectId}/kanban`,
  ask: `/projects/${activeProjectId}/ask`,
  memory: `/projects/${activeProjectId}/memory`,
  engineering: `/projects/${activeProjectId}/engineering`,
};
```

Render a compact project context row in the header:

```tsx
{activeProjectId ? (
  <div className="text-xs text-muted-foreground">
    Project scope <span className="font-mono text-foreground">{activeProjectId}</span>
  </div>
) : (
  <div className="text-xs text-muted-foreground">Global view. Open a project to create scoped work.</div>
)}
```

- [ ] **Step 3: Add routes**

In `src/App.tsx`, import new pages:

```ts
import ProjectMemoryPage from "./pages/ProjectMemoryPage";
import AgentWorkPage from "./pages/AgentWorkPage";
import AskAgentsPage from "./pages/AskAgentsPage";
import EngineeringPage from "./pages/EngineeringPage";
```

Add routes:

```tsx
<Route path="/projects/:projectId/memory" element={<ProjectMemoryPage />} />
<Route path="/projects/:projectId/agent-work" element={<AgentWorkPage />} />
<Route path="/projects/:projectId/ask" element={<AskAgentsPage />} />
<Route path="/projects/:projectId/engineering" element={<EngineeringPage />} />
<Route path="/memory" element={<ProjectMemoryPage />} />
<Route path="/ask" element={<AskAgentsPage />} />
<Route path="/engineering" element={<EngineeringPage />} />
```

- [ ] **Step 4: Add Engineering page**

Create `src/pages/EngineeringPage.tsx`:

```tsx
import { Link, useParams } from "react-router-dom";
import { Activity, Boxes, GitGraph, PackageSearch } from "lucide-react";
import PageHeader from "../components/devboard/PageHeader";

export default function EngineeringPage() {
  const { projectId } = useParams();
  const prefix = projectId ? `/projects/${projectId}` : "";

  return (
    <div className="space-y-6">
      <PageHeader
        icon={Activity}
        title="Engineering"
        description="Technical evidence for debugging, run history, graph inspection, and generated outputs."
      />
      <div className="grid gap-3 md:grid-cols-3">
        <Link className="rounded-lg border bg-card p-4 hover:bg-accent" to={`${prefix}/runs`}>
          <Activity className="mb-3 h-5 w-5" />
          <div className="font-medium">Runs</div>
          <div className="text-sm text-muted-foreground">Local agent executions and pipeline status.</div>
        </Link>
        <Link className="rounded-lg border bg-card p-4 hover:bg-accent" to="/graph">
          <GitGraph className="mb-3 h-5 w-5" />
          <div className="font-medium">Graph</div>
          <div className="text-sm text-muted-foreground">Code relationship diagnostics.</div>
        </Link>
        <Link className="rounded-lg border bg-card p-4 hover:bg-accent" to={`${prefix}/artifacts`}>
          <PackageSearch className="mb-3 h-5 w-5" />
          <div className="font-medium">Evidence</div>
          <div className="text-sm text-muted-foreground">Generated files and analysis outputs.</div>
        </Link>
      </div>
    </div>
  );
}
```

- [ ] **Step 5: Build frontend**

Run:

```bash
cd /home/ubuntu/emergent_devboard_frontend/frontend && npm run build
```

Expected: build completes.

## Task 9: React Kanban Create/Edit And Platon Entry

**Files:**
- Modify: `/home/ubuntu/emergent_devboard_frontend/frontend/src/pages/KanbanPage.tsx`
- Modify: `/home/ubuntu/emergent_devboard_frontend/frontend/src/pages/TaskDetailPage.tsx`
- Modify: `/home/ubuntu/emergent_devboard_frontend/frontend/src/components/devboard/Board.tsx` if repository labels assume strings.

- [ ] **Step 1: Add Kanban create/edit state**

In `KanbanPage.tsx`, add state:

```tsx
const [draftOpen, setDraftOpen] = useState(false);
const [draftTitle, setDraftTitle] = useState("");
const [draftDescription, setDraftDescription] = useState("");
const [draftAcceptanceCriteria, setDraftAcceptanceCriteria] = useState("");
const [draftRisk, setDraftRisk] = useState<RiskLevel>("low");
const [savingTask, setSavingTask] = useState(false);
```

Add submit handler:

```tsx
async function handleCreateTask() {
  if (!projectId || draftTitle.trim().length < 3) return;
  setSavingTask(true);
  try {
    await api.createTask(projectId, {
      title: draftTitle.trim(),
      description: draftDescription.trim() || null,
      risk: draftRisk,
      acceptance_criteria: draftAcceptanceCriteria
        .split("\n")
        .map((line) => line.trim())
        .filter(Boolean),
    });
    setDraftOpen(false);
    setDraftTitle("");
    setDraftDescription("");
    setDraftAcceptanceCriteria("");
    await loadKanban();
  } finally {
    setSavingTask(false);
  }
}
```

If the page currently loads data in an inline effect, extract the loader into `loadKanban()` so create/edit can refresh.

- [ ] **Step 2: Add project-scoped create action**

Add a primary button near the Kanban header:

```tsx
<Button onClick={() => setDraftOpen(true)} disabled={!projectId}>
  <Plus className="mr-2 h-4 w-4" />
  New task
</Button>
```

When `projectId` is absent, render:

```tsx
<Alert>
  <AlertTitle>Open a project to create work</AlertTitle>
  <AlertDescription>Global Kanban is read-only so tasks cannot be created without an explicit project scope.</AlertDescription>
</Alert>
```

- [ ] **Step 3: Add task dialog**

Use the existing shadcn dialog components already present in the frontend. The dialog must include title, description, risk, and acceptance criteria textarea:

```tsx
<Dialog open={draftOpen} onOpenChange={setDraftOpen}>
  <DialogContent>
    <DialogHeader>
      <DialogTitle>New task</DialogTitle>
      <DialogDescription>Write the work item in the selected project.</DialogDescription>
    </DialogHeader>
    <div className="space-y-4">
      <Input value={draftTitle} onChange={(event) => setDraftTitle(event.target.value)} placeholder="Short, specific title" />
      <Textarea value={draftDescription} onChange={(event) => setDraftDescription(event.target.value)} placeholder="What is happening, where, and what should change" rows={5} />
      <Select value={draftRisk} onValueChange={(value) => setDraftRisk(value as RiskLevel)}>
        <SelectTrigger><SelectValue /></SelectTrigger>
        <SelectContent>
          <SelectItem value="low">Low risk</SelectItem>
          <SelectItem value="medium">Medium risk</SelectItem>
          <SelectItem value="high">High risk</SelectItem>
        </SelectContent>
      </Select>
      <Textarea value={draftAcceptanceCriteria} onChange={(event) => setDraftAcceptanceCriteria(event.target.value)} placeholder="One acceptance criterion per line" rows={4} />
    </div>
    <DialogFooter>
      <Button variant="outline" onClick={() => setDraftOpen(false)}>Cancel</Button>
      <Button onClick={handleCreateTask} disabled={savingTask || draftTitle.trim().length < 3}>Create</Button>
    </DialogFooter>
  </DialogContent>
</Dialog>
```

- [ ] **Step 4: Add Platon clarity entry on task detail**

In `TaskDetailPage.tsx`, add a button in the existing Task Clarifier panel:

```tsx
<Button
  variant="outline"
  onClick={() => api.createAgentWork(task.project_id, {
    assigned_agent_key: "platon",
    task_id: task.id,
    priority: task.risk === "high" ? "high" : "normal",
    title: `Clarify task: ${task.title}`,
    prompt: "Review this task and ask the PM yes/no questions until the expected behavior is unambiguous.",
    payload: {
      task_id: task.id,
      question_style: "yes_no_only",
      expected_output: "Questions attached to the task context.",
    },
    requires_memory_entry: true,
  })}
>
  Ask Platon
</Button>
```

If this page does not yet import the API hook or object, reuse the same `api` access pattern already used elsewhere in the app.

- [ ] **Step 5: Build frontend**

Run:

```bash
cd /home/ubuntu/emergent_devboard_frontend/frontend && npm run build
```

Expected: build completes with no TypeScript errors from the changed files.

## Task 10: React Memory, Agent Work, And Ask Pages

**Files:**
- Create: `/home/ubuntu/emergent_devboard_frontend/frontend/src/pages/ProjectMemoryPage.tsx`
- Create: `/home/ubuntu/emergent_devboard_frontend/frontend/src/pages/AgentWorkPage.tsx`
- Create: `/home/ubuntu/emergent_devboard_frontend/frontend/src/pages/AskAgentsPage.tsx`

- [ ] **Step 1: Create Memory page**

Create `ProjectMemoryPage.tsx`:

```tsx
import { useEffect, useState } from "react";
import { useParams } from "react-router-dom";
import { BookOpenText, Plus } from "lucide-react";
import { api } from "../api";
import PageHeader from "../components/devboard/PageHeader";
import { Button } from "../components/ui/button";
import { Textarea } from "../components/ui/textarea";
import { Input } from "../components/ui/input";
import type { ProjectMemoryEntry } from "../types/devboard";

export default function ProjectMemoryPage() {
  const { projectId } = useParams();
  const [entries, setEntries] = useState<ProjectMemoryEntry[]>([]);
  const [summary, setSummary] = useState("");
  const [why, setWhy] = useState("");

  async function loadMemory() {
    if (!projectId) return;
    const response = await api.getProjectMemory(projectId);
    setEntries(response.entries);
  }

  useEffect(() => {
    void loadMemory();
  }, [projectId]);

  async function appendMemory() {
    if (!projectId || summary.trim().length < 8) return;
    await api.createProjectMemory(projectId, {
      kind: "decision",
      summary: summary.trim(),
      payload: { why: why.trim(), changed: [], tests: [], skipped_checks: [], risks: [] },
    });
    setSummary("");
    setWhy("");
    await loadMemory();
  }

  return (
    <div className="space-y-6">
      <PageHeader icon={BookOpenText} title="Memory" description="Shared operational memory for decisions, changes, verification, and handoffs." />
      {!projectId ? (
        <div className="rounded-lg border p-4 text-sm text-muted-foreground">Open a project to view its Shared Memory.</div>
      ) : (
        <>
          <div className="rounded-lg border bg-card p-4 space-y-3">
            <Input value={summary} onChange={(event) => setSummary(event.target.value)} placeholder="Memory summary" />
            <Textarea value={why} onChange={(event) => setWhy(event.target.value)} placeholder="Why this matters" rows={3} />
            <Button onClick={appendMemory} disabled={summary.trim().length < 8}>
              <Plus className="mr-2 h-4 w-4" />
              Add memory
            </Button>
          </div>
          <div className="space-y-3">
            {entries.map((entry) => (
              <article key={entry.id} className="rounded-lg border bg-card p-4">
                <div className="flex items-center justify-between gap-3">
                  <div className="font-medium">{entry.summary}</div>
                  <span className="rounded border px-2 py-1 text-xs">{entry.kind}</span>
                </div>
                <div className="mt-2 text-xs text-muted-foreground">
                  {entry.source} {entry.agent_key ? `by ${entry.agent_key}` : ""} · {new Date(entry.occurred_at).toLocaleString()}
                </div>
              </article>
            ))}
          </div>
        </>
      )}
    </div>
  );
}
```

- [ ] **Step 2: Create Agent Work page**

Create `AgentWorkPage.tsx`:

```tsx
import { useEffect, useState } from "react";
import { useParams } from "react-router-dom";
import { Bot, Plus } from "lucide-react";
import { api } from "../api";
import PageHeader from "../components/devboard/PageHeader";
import { Button } from "../components/ui/button";
import { Input } from "../components/ui/input";
import { Textarea } from "../components/ui/textarea";
import type { AgentKey, AgentWorkItem } from "../types/devboard";

export default function AgentWorkPage() {
  const { projectId } = useParams();
  const [items, setItems] = useState<AgentWorkItem[]>([]);
  const [title, setTitle] = useState("");
  const [prompt, setPrompt] = useState("");
  const [agent, setAgent] = useState<AgentKey>("local_agent");

  async function loadWork() {
    if (!projectId) return;
    const response = await api.getAgentWork(projectId);
    setItems(response.items);
  }

  useEffect(() => {
    void loadWork();
  }, [projectId]);

  async function queueWork() {
    if (!projectId || title.trim().length < 4 || prompt.trim().length < 8) return;
    await api.createAgentWork(projectId, {
      assigned_agent_key: agent,
      priority: "normal",
      title: title.trim(),
      prompt: prompt.trim(),
      payload: { request: prompt.trim() },
      requires_memory_entry: true,
    });
    setTitle("");
    setPrompt("");
    await loadWork();
  }

  return (
    <div className="space-y-6">
      <PageHeader icon={Bot} title="Agent Work" description="Server-side requests that agents can claim, execute, and close with memory." />
      {!projectId ? (
        <div className="rounded-lg border p-4 text-sm text-muted-foreground">Open a project to manage agent work.</div>
      ) : (
        <>
          <div className="rounded-lg border bg-card p-4 space-y-3">
            <select className="h-10 rounded-md border bg-background px-3 text-sm" value={agent} onChange={(event) => setAgent(event.target.value as AgentKey)}>
              <option value="local_agent">Local agent</option>
              <option value="socrates">Socrates</option>
              <option value="platon">Platon</option>
              <option value="aristoteles">Aristoteles</option>
            </select>
            <Input value={title} onChange={(event) => setTitle(event.target.value)} placeholder="Work title" />
            <Textarea value={prompt} onChange={(event) => setPrompt(event.target.value)} placeholder="What should the agent do?" rows={4} />
            <Button onClick={queueWork} disabled={title.trim().length < 4 || prompt.trim().length < 8}>
              <Plus className="mr-2 h-4 w-4" />
              Queue work
            </Button>
          </div>
          <div className="space-y-3">
            {items.map((item) => (
              <article key={item.id} className="rounded-lg border bg-card p-4">
                <div className="flex items-center justify-between gap-3">
                  <div className="font-medium">{item.title}</div>
                  <span className="rounded border px-2 py-1 text-xs">{item.status}</span>
                </div>
                <p className="mt-2 text-sm text-muted-foreground">{item.prompt}</p>
                <div className="mt-3 text-xs text-muted-foreground">
                  {item.assigned_agent_key} · {item.priority} · memory required: {item.requires_memory_entry ? "yes" : "no"}
                </div>
              </article>
            ))}
          </div>
        </>
      )}
    </div>
  );
}
```

- [ ] **Step 3: Create Ask Agents page**

Create `AskAgentsPage.tsx`:

```tsx
import { useState } from "react";
import { useNavigate, useParams } from "react-router-dom";
import { MessagesSquare, Send } from "lucide-react";
import { api } from "../api";
import PageHeader from "../components/devboard/PageHeader";
import { Button } from "../components/ui/button";
import { Textarea } from "../components/ui/textarea";
import type { AgentKey } from "../types/devboard";

const AGENTS: { key: AgentKey; name: string; description: string }[] = [
  { key: "socrates", name: "Socrates", description: "Asks what is known, unknown, and where to look across wiki, memory, tasks, and local-agent work." },
  { key: "platon", name: "Platon", description: "Turns vague Kanban tasks into yes/no clarification questions." },
  { key: "aristoteles", name: "Aristoteles", description: "Analyzes memory, KPI patterns, and process inefficiencies." },
  { key: "local_agent", name: "Local agent", description: "Reads source code locally and executes queued work when available." },
];

export default function AskAgentsPage() {
  const { projectId } = useParams();
  const navigate = useNavigate();
  const [agent, setAgent] = useState<AgentKey>("socrates");
  const [prompt, setPrompt] = useState("");

  async function askAgent() {
    if (!projectId || prompt.trim().length < 8) return;
    await api.createAgentWork(projectId, {
      assigned_agent_key: agent,
      priority: agent === "local_agent" ? "high" : "normal",
      title: `Ask ${agent}`,
      prompt: prompt.trim(),
      payload: { request: prompt.trim(), source: "ask_page" },
      requires_memory_entry: agent === "local_agent",
    });
    navigate(`/projects/${projectId}/agent-work`);
  }

  return (
    <div className="space-y-6">
      <PageHeader icon={MessagesSquare} title="Ask" description="Ask a specific project agent and track the request as work." />
      {!projectId ? (
        <div className="rounded-lg border p-4 text-sm text-muted-foreground">Open a project to ask its agents.</div>
      ) : (
        <div className="grid gap-4 lg:grid-cols-[280px_1fr]">
          <div className="space-y-2">
            {AGENTS.map((item) => (
              <button
                key={item.key}
                className={`w-full rounded-lg border p-3 text-left ${agent === item.key ? "bg-accent" : "bg-card"}`}
                onClick={() => setAgent(item.key)}
              >
                <div className="font-medium">{item.name}</div>
                <div className="mt-1 text-xs text-muted-foreground">{item.description}</div>
              </button>
            ))}
          </div>
          <div className="rounded-lg border bg-card p-4 space-y-3">
            <Textarea value={prompt} onChange={(event) => setPrompt(event.target.value)} rows={8} placeholder="Write the question or request for the selected agent" />
            <Button onClick={askAgent} disabled={prompt.trim().length < 8}>
              <Send className="mr-2 h-4 w-4" />
              Send
            </Button>
          </div>
        </div>
      )}
    </div>
  );
}
```

- [ ] **Step 4: Build frontend**

Run:

```bash
cd /home/ubuntu/emergent_devboard_frontend/frontend && npm run build
```

Expected: build completes.

## Task 11: Final Verification, Runtime Rebuild, And Documentation

**Files:**
- Modify: `docs/ai-devboard/03_DOMAIN_MODEL.md`
- Modify: `docs/ai-devboard/04_PLUGIN_SERVER_CONTRACT.md`
- Modify: `docs/ai-devboard/09_DASHBOARD_WIREFRAMES.md`
- Modify: `docs/ai-devboard/12_SERVER_SIDE_AGENT_REGISTRY.md`
- Modify: `ai-sandbox/logbooks/LOGBOOK_PROJECT.md`

- [ ] **Step 1: Update domain documentation**

In `docs/ai-devboard/03_DOMAIN_MODEL.md`, add a section named `Project Workspace, Memory, And Agent Work`:

```markdown
## Project Workspace, Memory, And Agent Work

Source status: verified_from_code once migrations and routes from the 2026-06-29 workspace slice are merged.

- `project_memory_entries` is the Shared Memory logbook. Local agents use it to understand what changed, why it changed, which files/symbols were touched, which checks ran, and which risks remain.
- `agent_work_items` is the server-side work queue. Dashboard users, Socrates, Platon, Aristoteles, and system events can queue work. The local agent claims work through `/api/plugin/v1/agent-work-items/*`.
- Kanban tasks can carry acceptance criteria and explicit repository scope through `repository_task`.
- Completing a local-agent work item should create a complete `project_memory_entries` row. If a required memory entry is missing, the work item uses `completed_with_incomplete_memory`.
```

- [ ] **Step 2: Update plugin contract documentation**

In `docs/ai-devboard/04_PLUGIN_SERVER_CONTRACT.md`, add:

```markdown
## Shared Memory And Work Queue

Source status: verified_from_code once the routes are present in `backend/routes/api.php`.

- `GET /api/plugin/v1/projects/{project}/shared-memory-pack?repository_id={repository}` returns the recent project memory entries the local agent must inspect before editing source code.
- `GET /api/plugin/v1/agent-work-items` returns queued, claimed, or running work assigned to the local agent device.
- `POST /api/plugin/v1/agent-work-items/{workItem}/claim` claims a work item and returns a lease token.
- `POST /api/plugin/v1/agent-work-items/{workItem}/heartbeat` keeps the claim active.
- `POST /api/plugin/v1/agent-work-items/{workItem}/complete` closes work and may append a Shared Memory entry.
- `POST /api/plugin/v1/agent-work-items/{workItem}/fail` records a failed attempt and releases the lease.
```

- [ ] **Step 3: Update dashboard wireframes documentation**

In `docs/ai-devboard/09_DASHBOARD_WIREFRAMES.md`, add:

```markdown
## Project Workspace Navigation

Source status: verified_from_code once React routes are present in `src/App.tsx`.

Primary navigation is organized as:

- Projects: project list and project selection.
- Work: project-scoped Kanban.
- Ask: direct request surface for Socrates, Platon, Aristoteles, and the local agent.
- Memory: Shared Memory entries for the active project.
- Engineering: runs, graph, and evidence diagnostics.
- Settings: admin and system controls.
```

- [ ] **Step 4: Update agent registry documentation**

In `docs/ai-devboard/12_SERVER_SIDE_AGENT_REGISTRY.md`, add:

```markdown
## Workspace Agents

Source status: developer_provided until individual agent prompts and `soul.md` files are written.

- Socrates is the cross-workspace interface. It routes questions through wiki, memory, tasks, and local-agent work, and labels unknowns instead of inventing facts.
- Platon is the Kanban clarity agent. It reacts to vague task creation or updates and asks yes/no questions in the task context.
- Aristoteles analyzes Shared Memory, KPIs, and work patterns to detect inefficiency and anticipate risk.
- The local agent is controlled by the developer, sees source code locally, fetches Shared Memory before work, claims server work items when available, and writes exhaustive memory when done.
```

- [ ] **Step 5: Run full verification**

Run:

```bash
cd backend && php artisan test
cd plugin && python -m pytest -q
cd analyzer && python -m pytest -q
cd /home/ubuntu/emergent_devboard_frontend/frontend && npm run build
git diff --check
git status --short
```

Expected:

- Laravel tests PASS.
- Plugin tests PASS.
- Analyzer tests PASS.
- React build completes.
- `git diff --check` prints no whitespace errors.
- `git status --short` shows only intended modified files and any pre-existing user changes that were explicitly excluded from commits.

- [ ] **Step 6: Rebuild Docker services used by the published project**

Run:

```bash
docker compose -f docker-compose.devboard.yaml build app node
docker compose -f docker-compose.devboard.yaml up -d app node postgres neo4j
docker compose -f docker-compose.devboard.yaml ps
```

Expected: `app`, `node`, `postgres`, and `neo4j` are running. If the deployment uses an additional compose file such as `docker-compose.devboard.traefik.yaml`, run the matching build/up command and record the compose files in the logbook.

- [ ] **Step 7: Update project logbook**

Append a new entry to `ai-sandbox/logbooks/LOGBOOK_PROJECT.md`:

```markdown
## 2026-06-29 - Project Workspace Memory Queue implementation

- Request: implement the Project Workspace slice with Kanban task creation/editing, Shared Memory, agent work queue, server-to-local work polling, and React workspace surfaces.
- Context read: `ai-sandbox/INIT.md`, `ai-sandbox/instructions/INDEX.md`, sandbox policies, `ai-sandbox/config/project.yaml`, `docs/superpowers/specs/2026-06-29-devboard-project-workspace-agents-design.md`, Laravel routes/controllers/readers, plugin client/MCP/CLI, React routes/nav/API adapters.
- Work performed: added backend schema, dashboard APIs, plugin APIs, Python plugin tools, React API contracts, React workspace pages, and documentation for the new contracts.
- Verification: record exact PASS/FAIL result for every command from Step 5 and Step 6.
- Files changed: record backend, plugin, frontend, docs, and plan files touched by this implementation.
- Residual risks: record any skipped checks, deployment gaps, or known UX limitations.
```

Stage only this new logbook entry if unrelated logbook lines exist:

```bash
git diff -- ai-sandbox/logbooks/LOGBOOK_PROJECT.md
git add -p ai-sandbox/logbooks/LOGBOOK_PROJECT.md
```

- [ ] **Step 8: Final commit**

Run:

```bash
git status --short
git add docs/ai-devboard/03_DOMAIN_MODEL.md docs/ai-devboard/04_PLUGIN_SERVER_CONTRACT.md docs/ai-devboard/09_DASHBOARD_WIREFRAMES.md docs/ai-devboard/12_SERVER_SIDE_AGENT_REGISTRY.md ai-sandbox/logbooks/LOGBOOK_PROJECT.md
git commit -m "docs: document project workspace memory queue"
```

Expected: final documentation commit contains only docs and the intended logbook section.

## Acceptance Criteria

- A PM or developer can create a new task from project Kanban with title, description, risk, acceptance criteria, and repository scope.
- A user can tell which project owns the current Kanban, Memory, Ask, Agent Work, and Engineering views.
- A user can inspect and append Shared Memory for a project.
- A user can queue work for Socrates, Platon, Aristoteles, or the local agent.
- The local plugin can fetch Shared Memory, list work items, claim one, heartbeat, complete with memory, or fail it.
- Completing local-agent work writes an exhaustive Shared Memory entry or marks completion as incomplete.
- React mock mode remains usable without a backend server.
- The published Docker services are rebuilt after implementation.

## Execution Notes

- Use `superpowers:using-git-worktrees` before implementation begins if the current branch is `main` or `master`. The current observed branch during planning was `fase-2`.
- Use `superpowers:subagent-driven-development` for execution because the user selected that workflow.
- Use a fresh implementation subagent per task and two review subagents before moving to the next task.
- If a review finds a blocker, fix it in the same task before dispatching the next task.
- Keep commits small and scoped to the task that produced them.
