# DevBoard Project Lifecycle Policy Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Implement reversible project archive and reversible soft-delete for DevBoard projects across dashboard API, plugin guardrails, and the operational React/emergent projects UI.

**Architecture:** Keep lifecycle state explicit in `projects.status` plus nullable metadata columns because the backend currently uses query builder rather than a `Project` model. Add a focused lifecycle service/controller for transitions and audit, update dashboard readers to filter active/archived/deleted consistently, and keep all browser calls under `/api/dashboard/...`. Add plugin guardrails so local agent/CLI clients cannot start or upload work for archived/deleted projects.

**Tech Stack:** Laravel 13, Pest, PostgreSQL/SQLite test DB, route registry YAML, React 19, Create React App/CRACO, TypeScript, Tailwind/shadcn UI, Jest, Playwright/Chromium fallback for browser smoke.

---

## Required Context

Read these first:

- `AGENTS.md`
- `ai-sandbox/INIT.md`
- `ai-sandbox/instructions/INDEX.md`
- `ai-sandbox/config/project.yaml`
- `ai-sandbox/instructions/policies/`
- `ai-sandbox/logbooks/LOGBOOK_PROJECT.md`
- `docs/superpowers/plans/2026-06-24-devboard-masterplan.md`
- `docs/superpowers/plans/2026-06-24-devboard-multiproject-dashboard.md`
- `docs/superpowers/specs/2026-06-24-devboard-project-lifecycle-policy-design.md`

Before implementation edits, add a new `LOGBOOK_PROJECT.md` entry listing every planned backend and external frontend path. The external frontend checkout used by the previous slices is `/home/ubuntu/emergent_devboard_frontend`.

## File Structure

Backend create:

- `backend/database/migrations/2026_06_24_000001_add_project_lifecycle_metadata_to_projects.php`: lifecycle metadata columns.
- `backend/app/Projects/ProjectLifecycleService.php`: transition rules, active-work checks, audit writes, dashboard/plugin guard helpers.
- `backend/app/Http/Controllers/Dashboard/Api/DashboardProjectLifecycleController.php`: `/api/dashboard/projects/{project}/archive|restore|delete`.
- `backend/tests/Feature/Dashboard/ProjectLifecycleDashboardApiTest.php`: dashboard lifecycle behavior.
- `backend/tests/Feature/Plugin/ProjectLifecyclePluginGuardTest.php`: plugin namespace guard behavior.

Backend modify:

- `backend/app/Dashboard/DashboardApiReader.php`: project filtering, lifecycle fields, deleted read blocking, active-only overview metrics.
- `backend/app/Http/Controllers/Dashboard/Api/DashboardResourceController.php`: block project identity/task/run mutations when project is archived/deleted; pass status filter to reader.
- `backend/routes/web.php`: lifecycle dashboard API routes.
- `backend/routes/api.php`: no new routes; existing plugin routes use service guard updates in controllers.
- `backend/config/quality/route_registry.yaml`: lifecycle route classifications.
- Plugin controllers that must call the lifecycle guard:
  - `backend/app/Http/Controllers/Plugin/ListProjectsController.php`
  - `backend/app/Http/Controllers/Plugin/ListRepositoriesController.php`
  - `backend/app/Http/Controllers/Plugin/RegisterLocalWorkspaceController.php`
  - `backend/app/Http/Controllers/Plugin/RepositoryInstructionsController.php`
  - `backend/app/Http/Controllers/Plugin/RunStartController.php`
  - `backend/app/Http/Controllers/Plugin/RunHeartbeatController.php`
  - `backend/app/Http/Controllers/Plugin/RunEventController.php`
  - `backend/app/Http/Controllers/Plugin/RunFinishController.php`
  - `backend/app/Http/Controllers/Plugin/DeltaLocalSnapshotController.php`
  - `backend/app/Http/Controllers/Plugin/DeltaStartController.php`
  - `backend/app/Http/Controllers/Plugin/DeltaChunkController.php`
  - `backend/app/Http/Controllers/Plugin/DeltaFinalizeController.php`
  - `backend/app/Http/Controllers/Plugin/GenesisStartController.php`
  - `backend/app/Http/Controllers/Plugin/GenesisChunkController.php`
  - `backend/app/Http/Controllers/Plugin/GenesisFinalizeController.php`
  - `backend/app/Http/Controllers/Plugin/WikiRevisionController.php`

External frontend modify:

- `/home/ubuntu/emergent_devboard_frontend/frontend/src/types/devboard.ts`: `ProjectStatus`, lifecycle fields, lifecycle input type.
- `/home/ubuntu/emergent_devboard_frontend/frontend/src/api/devboardApi.ts`: status-filtered `getProjects` and lifecycle methods.
- `/home/ubuntu/emergent_devboard_frontend/frontend/src/api/httpApi.ts`: dashboard lifecycle endpoints and `status` query.
- `/home/ubuntu/emergent_devboard_frontend/frontend/src/api/httpApi.test.ts`: adapter endpoint coverage and `/api/plugin/v1` guard assertion.
- `/home/ubuntu/emergent_devboard_frontend/frontend/src/api/mockApi.ts`: mutable lifecycle state and filtering.
- `/home/ubuntu/emergent_devboard_frontend/frontend/src/pages/ProjectsPage.tsx`: status segmented control, Admin/PM lifecycle actions, delete confirmation.

## Task 1: Backend Dashboard Lifecycle RED Tests

**Files:**

- Create: `backend/tests/Feature/Dashboard/ProjectLifecycleDashboardApiTest.php`

- [ ] **Step 1: Add failing dashboard lifecycle tests**

Create `backend/tests/Feature/Dashboard/ProjectLifecycleDashboardApiTest.php`:

```php
<?php

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

beforeEach(function () {
    Storage::fake('local');
    $this->seed(\Database\Seeders\DevBoardSeeder::class);
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

    $activeIds = $this->actingAs($developer)
        ->getJson('/api/dashboard/projects')
        ->assertOk()
        ->json();

    expect(collect($activeIds)->pluck('id')->all())->toContain($active)
        ->and(collect($activeIds)->pluck('id')->all())->not->toContain($archived, $deleted);

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

    $this->actingAs($pm)
        ->getJson("/api/dashboard/projects/{$archived}/kanban")
        ->assertOk();

    $this->actingAs($pm)
        ->getJson("/api/dashboard/projects/{$deleted}")
        ->assertNotFound();

    $this->actingAs($pm)
        ->getJson("/api/dashboard/projects/{$deleted}/kanban")
        ->assertNotFound();
});

it('blocks archive and delete while active work is in progress', function () {
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

it('blocks operational dashboard mutations for archived projects', function () {
    $admin = projectLifecycleUserWithRole('Admin');
    $pm = projectLifecycleUserWithRole('PM');
    $projectId = projectLifecycleCreateProject('Read Only Project', 'read-only-project', 'archived');
    $taskId = projectLifecycleTask($projectId);
    $runId = projectLifecycleRun($projectId, 'failed');

    $this->actingAs($admin)
        ->patchJson("/api/dashboard/projects/{$projectId}", ['description' => 'Blocked edit.'])
        ->assertConflict()
        ->assertJsonPath('error.code', 'project_not_active');

    $this->actingAs($pm)
        ->patchJson("/api/dashboard/tasks/{$taskId}", ['column' => 'done'])
        ->assertConflict()
        ->assertJsonPath('error.code', 'project_not_active');

    $this->actingAs($pm)
        ->postJson("/api/dashboard/runs/{$runId}/review")
        ->assertConflict()
        ->assertJsonPath('error.code', 'project_not_active');
});

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

    foreach (['archived_at', 'archived_by_user_id', 'deleted_at', 'deleted_by_user_id'] as $column) {
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
    $repositoryId = DB::table('repositories')->where('project_id', $projectId)->value('id');
    if (! $repositoryId) {
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
    }
    $adminId = DB::table('users')->where('email', 'admin@example.com')->value('id');

    DB::table('runs')->insert([
        'id' => $runId,
        'project_id' => $projectId,
        'repository_id' => $repositoryId,
        'local_workspace_id' => null,
        'task_id' => null,
        'device_id' => null,
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
```

- [ ] **Step 2: Run the RED test**

Run:

```bash
docker compose -f docker-compose.devboard.yaml exec -T app sh -lc 'APP_ENV=testing DB_CONNECTION=sqlite DB_DATABASE=:memory: DB_URL= DEVBOARD_DASHBOARD_ORIGINS=http://127.0.0.1:3000,http://localhost:3000 php artisan test tests/Feature/Dashboard/ProjectLifecycleDashboardApiTest.php --display-warnings'
```

Expected: FAIL because lifecycle metadata columns and `/api/dashboard/projects/{project}/archive|restore|delete` routes do not exist yet.

- [ ] **Step 3: Commit RED test only if this workspace is being committed task-by-task**

```bash
git add backend/tests/Feature/Dashboard/ProjectLifecycleDashboardApiTest.php
git commit -m "test: cover project lifecycle dashboard policy"
```

Skip commit if the operator wants one combined commit after all dirty multiproject slices are reviewed.

## Task 2: Project Lifecycle Migration

**Files:**

- Create: `backend/database/migrations/2026_06_24_000001_add_project_lifecycle_metadata_to_projects.php`

- [ ] **Step 1: Add the migration**

Create `backend/database/migrations/2026_06_24_000001_add_project_lifecycle_metadata_to_projects.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('projects', function (Blueprint $table): void {
            $table->timestamp('archived_at')->nullable()->after('default_code_exposure_policy');
            $table->foreignId('archived_by_user_id')->nullable()->after('archived_at')->constrained('users')->nullOnDelete();
            $table->timestamp('deleted_at')->nullable()->after('archived_by_user_id');
            $table->foreignId('deleted_by_user_id')->nullable()->after('deleted_at')->constrained('users')->nullOnDelete();
            $table->timestamp('restored_at')->nullable()->after('deleted_by_user_id');
            $table->foreignId('restored_by_user_id')->nullable()->after('restored_at')->constrained('users')->nullOnDelete();
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::table('projects', function (Blueprint $table): void {
            $table->dropIndex(['status']);
            $table->dropConstrainedForeignId('restored_by_user_id');
            $table->dropColumn('restored_at');
            $table->dropConstrainedForeignId('deleted_by_user_id');
            $table->dropColumn('deleted_at');
            $table->dropConstrainedForeignId('archived_by_user_id');
            $table->dropColumn('archived_at');
        });
    }
};
```

- [ ] **Step 2: Run migration-aware RED test again**

Run the Task 1 command again.

Expected: still FAIL, but failures should move from missing lifecycle columns toward missing routes/controller behavior.

- [ ] **Step 3: Run the existing focused dashboard CRUD tests**

Run:

```bash
docker compose -f docker-compose.devboard.yaml exec -T app sh -lc 'APP_ENV=testing DB_CONNECTION=sqlite DB_DATABASE=:memory: DB_URL= DEVBOARD_DASHBOARD_ORIGINS=http://127.0.0.1:3000,http://localhost:3000 php artisan test tests/Feature/Dashboard/ProjectCrudDashboardApiTest.php --display-warnings'
```

Expected: PASS. The nullable metadata columns must not break project create/edit.

- [ ] **Step 4: Commit migration if committing task-by-task**

```bash
git add backend/database/migrations/2026_06_24_000001_add_project_lifecycle_metadata_to_projects.php
git commit -m "feat: add project lifecycle metadata"
```

## Task 3: Lifecycle Service, Dashboard Routes, And Audit

**Files:**

- Create: `backend/app/Projects/ProjectLifecycleService.php`
- Create: `backend/app/Http/Controllers/Dashboard/Api/DashboardProjectLifecycleController.php`
- Modify: `backend/routes/web.php`
- Modify: `backend/config/quality/route_registry.yaml`
- Modify: `backend/app/Dashboard/DashboardApiReader.php`

- [ ] **Step 1: Add the lifecycle service**

Create `backend/app/Projects/ProjectLifecycleService.php`:

```php
<?php

namespace App\Projects;

use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

final class ProjectLifecycleService
{
    public const ACTIVE = 'active';
    public const ARCHIVED = 'archived';
    public const DELETED = 'deleted';

    private const TERMINAL_RUN_STATUSES = ['finished', 'failed', 'aborted'];
    private const ACTIVE_UPLOAD_STATUSES = ['uploading', 'active', 'started', 'queued', 'running'];

    public function transition(string $projectId, string $action, User $actor, ?string $reason, Request $request): array|JsonResponse
    {
        $project = DB::table('projects')->where('id', $projectId)->first();
        abort_unless($project, Response::HTTP_NOT_FOUND);

        $targetStatus = match ($action) {
            'archive' => self::ARCHIVED,
            'delete' => self::DELETED,
            'restore' => self::ACTIVE,
            default => throw new \InvalidArgumentException("Unsupported lifecycle action [{$action}]."),
        };

        if (! $this->transitionAllowed((string) $project->status, $targetStatus)) {
            return $this->conflict('invalid_project_lifecycle_transition', 'Invalid project lifecycle transition.');
        }

        if (in_array($targetStatus, [self::ARCHIVED, self::DELETED], true)) {
            $activeWork = $this->activeWorkSummary($projectId);
            if ($activeWork['runs'] > 0 || $activeWork['uploads'] > 0) {
                return $this->conflict('project_lifecycle_blocked', 'Project has active work.', $activeWork);
            }
        }

        $now = now();
        $updates = [
            'status' => $targetStatus,
            'updated_at' => $now,
        ];

        if ($targetStatus === self::ARCHIVED) {
            $updates['archived_at'] = $now;
            $updates['archived_by_user_id'] = $actor->id;
        }

        if ($targetStatus === self::DELETED) {
            $updates['deleted_at'] = $now;
            $updates['deleted_by_user_id'] = $actor->id;
        }

        if ($targetStatus === self::ACTIVE) {
            $updates['restored_at'] = $now;
            $updates['restored_by_user_id'] = $actor->id;
        }

        DB::transaction(function () use ($project, $projectId, $updates, $action, $targetStatus, $actor, $reason, $request): void {
            DB::table('projects')->where('id', $projectId)->update($updates);

            DB::table('audit_logs')->insert([
                'id' => (string) Str::ulid(),
                'actor_user_id' => $actor->id,
                'actor_device_id' => null,
                'actor_type' => 'user',
                'action' => match ($action) {
                    'archive' => 'project.archived',
                    'delete' => 'project.deleted',
                    'restore' => 'project.restored',
                },
                'target_type' => 'project',
                'target_id' => $projectId,
                'ip_address' => $request->ip(),
                'user_agent' => $request->userAgent(),
                'payload' => json_encode([
                    'project' => [
                        'id' => $projectId,
                        'slug' => (string) $project->slug,
                        'name' => (string) $project->name,
                    ],
                    'previous_status' => (string) $project->status,
                    'new_status' => $targetStatus,
                    'reason' => $reason,
                    'actor' => [
                        'id' => $actor->id,
                        'email' => $actor->email,
                    ],
                ], JSON_THROW_ON_ERROR),
                'created_at' => now(),
            ]);
        });

        return ['status' => $targetStatus];
    }

    public function activeWorkSummary(string $projectId): array
    {
        $runs = DB::table('runs')
            ->where('project_id', $projectId)
            ->whereNotIn('status', self::TERMINAL_RUN_STATUSES)
            ->count();

        $genesis = DB::table('genesis_imports')
            ->where('project_id', $projectId)
            ->whereIn('status', self::ACTIVE_UPLOAD_STATUSES)
            ->count();

        $delta = DB::table('delta_syncs')
            ->where('project_id', $projectId)
            ->whereIn('status', self::ACTIVE_UPLOAD_STATUSES)
            ->count();

        return [
            'runs' => (int) $runs,
            'uploads' => (int) $genesis + (int) $delta,
        ];
    }

    public function assertProjectActiveForDashboard(string $projectId): ?JsonResponse
    {
        $project = DB::table('projects')->where('id', $projectId)->first();
        abort_unless($project, Response::HTTP_NOT_FOUND);

        if ((string) $project->status !== self::ACTIVE) {
            return $this->conflict('project_not_active', 'Project is not active.');
        }

        return null;
    }

    public function assertTaskProjectActive(string $taskId): ?JsonResponse
    {
        $task = DB::table('tasks')->where('id', $taskId)->first();
        abort_unless($task, Response::HTTP_NOT_FOUND);

        return $this->assertProjectActiveForDashboard((string) $task->project_id);
    }

    public function assertRunProjectActive(string $runId): ?JsonResponse
    {
        $run = DB::table('runs')->where('id', $runId)->first();
        abort_unless($run, Response::HTTP_NOT_FOUND);

        return $this->assertProjectActiveForDashboard((string) $run->project_id);
    }

    public function pluginProjectWriteGuard(string $projectId): ?JsonResponse
    {
        $project = DB::table('projects')->where('id', $projectId)->first();

        if (! $project || (string) $project->status === self::DELETED) {
            abort(Response::HTTP_NOT_FOUND);
        }

        if ((string) $project->status === self::ARCHIVED) {
            return $this->conflict('project_archived', 'Project is archived and read-only.');
        }

        return null;
    }

    public function pluginRepositoryWriteGuard(string $repositoryId): ?JsonResponse
    {
        $repository = DB::table('repositories')->where('id', $repositoryId)->first();
        abort_unless($repository, Response::HTTP_NOT_FOUND);

        return $this->pluginProjectWriteGuard((string) $repository->project_id);
    }

    public function pluginRunWriteGuard(string $runId): ?JsonResponse
    {
        $run = DB::table('runs')->where('id', $runId)->first();
        abort_unless($run, Response::HTTP_NOT_FOUND);

        return $this->pluginProjectWriteGuard((string) $run->project_id);
    }

    public function pluginGenesisWriteGuard(string $genesisImportId): ?JsonResponse
    {
        $import = DB::table('genesis_imports')->where('id', $genesisImportId)->first();
        abort_unless($import, Response::HTTP_NOT_FOUND);

        return $this->pluginProjectWriteGuard((string) $import->project_id);
    }

    public function pluginDeltaWriteGuard(string $deltaSyncId): ?JsonResponse
    {
        $delta = DB::table('delta_syncs')->where('id', $deltaSyncId)->first();
        abort_unless($delta, Response::HTTP_NOT_FOUND);

        return $this->pluginProjectWriteGuard((string) $delta->project_id);
    }

    private function transitionAllowed(string $current, string $target): bool
    {
        return match ($current) {
            self::ACTIVE => in_array($target, [self::ARCHIVED, self::DELETED], true),
            self::ARCHIVED => in_array($target, [self::ACTIVE, self::DELETED], true),
            self::DELETED => $target === self::ACTIVE,
            default => false,
        };
    }

    private function conflict(string $code, string $message, array $details = []): JsonResponse
    {
        $error = [
            'code' => $code,
            'message' => $message,
        ];

        if ($details !== []) {
            $error['details'] = $details;
        }

        return response()->json(['error' => $error], Response::HTTP_CONFLICT);
    }
}
```

- [ ] **Step 2: Add the dashboard lifecycle controller**

Create `backend/app/Http/Controllers/Dashboard/Api/DashboardProjectLifecycleController.php`:

```php
<?php

namespace App\Http\Controllers\Dashboard\Api;

use App\Dashboard\DashboardApiReader;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Dashboard\Concerns\ChecksDashboardRoles;
use App\Projects\ProjectLifecycleService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class DashboardProjectLifecycleController extends Controller
{
    use ChecksDashboardRoles;

    public function __construct(
        private readonly ProjectLifecycleService $lifecycle,
        private readonly DashboardApiReader $reader,
    ) {
    }

    public function archive(Request $request, string $project): JsonResponse
    {
        return $this->transition($request, $project, 'archive');
    }

    public function restore(Request $request, string $project): JsonResponse
    {
        return $this->transition($request, $project, 'restore');
    }

    public function delete(Request $request, string $project): JsonResponse
    {
        return $this->transition($request, $project, 'delete');
    }

    private function transition(Request $request, string $project, string $action): JsonResponse
    {
        abort_unless(
            $this->userHasRole($request->user(), 'Admin') || $this->userHasRole($request->user(), 'PM'),
            403,
        );

        $validated = $request->validate([
            'reason' => ['nullable', 'string', 'max:500'],
        ]);

        $result = $this->lifecycle->transition(
            projectId: $project,
            action: $action,
            actor: $request->user(),
            reason: isset($validated['reason']) ? (string) $validated['reason'] : null,
            request: $request,
        );

        if ($result instanceof JsonResponse) {
            return $result;
        }

        return response()->json($this->reader->projectLifecycle($project));
    }
}
```

- [ ] **Step 3: Add lifecycle route imports and routes**

Modify `backend/routes/web.php`:

```php
use App\Http\Controllers\Dashboard\Api\DashboardProjectLifecycleController;
```

Add these routes inside the authenticated `/api/dashboard` group immediately after the existing project PATCH route:

```php
        Route::post('/projects/{project}/archive', [DashboardProjectLifecycleController::class, 'archive']);
        Route::post('/projects/{project}/restore', [DashboardProjectLifecycleController::class, 'restore']);
        Route::post('/projects/{project}/delete', [DashboardProjectLifecycleController::class, 'delete']);
```

- [ ] **Step 4: Add route registry entries**

Modify `backend/config/quality/route_registry.yaml` after `dashboard.api.projects.update`:

```yaml
  - id: dashboard.api.projects.archive
    method: POST
    uri: /api/dashboard/projects/{project}/archive
    source_status: verified_from_code
    classification: MUTATING
    classification_status: inferred
    actor: project_lifecycle_mutator
    parameter_provider:
      project: seeded_project
    smoke:
      enabled: false
      reason: "Archives a project and must not run in default route smoke."

  - id: dashboard.api.projects.restore
    method: POST
    uri: /api/dashboard/projects/{project}/restore
    source_status: verified_from_code
    classification: MUTATING
    classification_status: inferred
    actor: project_lifecycle_mutator
    parameter_provider:
      project: seeded_project
    smoke:
      enabled: false
      reason: "Restores project lifecycle state and must not run in default route smoke."

  - id: dashboard.api.projects.delete
    method: POST
    uri: /api/dashboard/projects/{project}/delete
    source_status: verified_from_code
    classification: DESTRUCTIVE
    classification_status: inferred
    actor: project_lifecycle_mutator
    parameter_provider:
      project: seeded_project
    smoke:
      enabled: false
      reason: "Soft-deletes a project into trash and must not run in default route smoke."
```

- [ ] **Step 5: Add lifecycle payload support to reader**

Modify `backend/app/Dashboard/DashboardApiReader.php`:

Change `projects()` to accept status:

```php
    public function projects(string $status = 'active'): array
    {
        return DB::table('projects')
            ->where('status', $status)
            ->orderBy('name')
            ->get()
            ->map(fn (object $project): array => $this->projectSummary($project))
            ->all();
    }
```

In `project()`, block deleted projects:

```php
        $project = DB::table('projects')->where('id', $projectId)->first();
        abort_unless($project && $project->status !== 'deleted', 404);
```

Add a public lifecycle payload method:

```php
    public function projectLifecycle(string $projectId): array
    {
        $project = DB::table('projects')->where('id', $projectId)->first();
        abort_unless($project, 404);

        return $this->projectSummary($project);
    }
```

Add lifecycle fields to `projectSummary()`:

```php
            'status' => (string) $project->status,
            'archived_at' => $project->archived_at ? (string) $project->archived_at : null,
            'deleted_at' => $project->deleted_at ? (string) $project->deleted_at : null,
            'restored_at' => $project->restored_at ? (string) $project->restored_at : null,
```

Keep those fields near `updated_at` so frontend typing is straightforward.

- [ ] **Step 6: Run focused lifecycle test**

Run:

```bash
docker compose -f docker-compose.devboard.yaml exec -T app sh -lc 'APP_ENV=testing DB_CONNECTION=sqlite DB_DATABASE=:memory: DB_URL= DEVBOARD_DASHBOARD_ORIGINS=http://127.0.0.1:3000,http://localhost:3000 php artisan test tests/Feature/Dashboard/ProjectLifecycleDashboardApiTest.php --display-warnings'
```

Expected: some tests PASS, remaining failures should point to reader filtering or operational mutation guards.

- [ ] **Step 7: Commit service/controller/routes if committing task-by-task**

```bash
git add backend/app/Projects/ProjectLifecycleService.php backend/app/Http/Controllers/Dashboard/Api/DashboardProjectLifecycleController.php backend/routes/web.php backend/config/quality/route_registry.yaml backend/app/Dashboard/DashboardApiReader.php
git commit -m "feat: add dashboard project lifecycle actions"
```

## Task 4: Dashboard Read Filtering And Mutation Blocks

**Files:**

- Modify: `backend/app/Dashboard/DashboardApiReader.php`
- Modify: `backend/app/Http/Controllers/Dashboard/Api/DashboardResourceController.php`

- [ ] **Step 1: Filter overview metrics to active projects**

Modify `DashboardApiReader::overview()` so task, repository, run, wiki, and project metrics join active projects. Replace the existing task state query shape with:

```php
        $taskStateCounts = DB::table('tasks')
            ->join('projects', 'projects.id', '=', 'tasks.project_id')
            ->join('kanban_columns', 'kanban_columns.id', '=', 'tasks.status_column_id')
            ->where('projects.status', 'active')
            ->select('kanban_columns.status_key', DB::raw('count(*) as aggregate'))
            ->groupBy('kanban_columns.status_key')
            ->pluck('aggregate', 'kanban_columns.status_key')
            ->map(fn (mixed $count): int => (int) $count)
            ->all();
```

Apply the same `projects.status = active` join to:

```php
        $taskRiskCounts = DB::table('tasks')
            ->join('projects', 'projects.id', '=', 'tasks.project_id')
            ->where('projects.status', 'active')
            ->select('risk_level', DB::raw('count(*) as aggregate'))
            ->groupBy('risk_level')
            ->pluck('aggregate', 'risk_level')
            ->map(fn (mixed $count): int => (int) $count)
            ->all();
```

Change overview counts:

```php
                'repositories_awaiting_genesis' => DB::table('repositories')
                    ->join('projects', 'projects.id', '=', 'repositories.project_id')
                    ->where('projects.status', 'active')
                    ->when($repositoryIdsWithGenesis !== [], fn ($query) => $query->whereNotIn('repositories.id', $repositoryIdsWithGenesis))
                    ->count(),
```

```php
                'total' => DB::table('tasks')
                    ->join('projects', 'projects.id', '=', 'tasks.project_id')
                    ->where('projects.status', 'active')
                    ->count(),
```

```php
                'failed' => DB::table('runs')
                    ->join('projects', 'projects.id', '=', 'runs.project_id')
                    ->where('projects.status', 'active')
                    ->where('runs.status', 'failed')
                    ->count(),
```

```php
                'running' => DB::table('runs')
                    ->join('projects', 'projects.id', '=', 'runs.project_id')
                    ->where('projects.status', 'active')
                    ->whereIn('runs.status', [
                        'created',
                        'queued',
                        'started',
                        'context_pulled',
                        'local_snapshot_received',
                        'working',
                        'heartbeat',
                        'artifact_uploaded',
                        'active',
                        'running',
                    ])
                    ->count(),
```

```php
                'stale_pages' => DB::table('wiki_pages')
                    ->join('projects', 'projects.id', '=', 'wiki_pages.project_id')
                    ->where('projects.status', 'active')
                    ->whereIn('wiki_pages.source_status', ['stale', 'conflict_with_code'])
                    ->count(),
```

And keep:

```php
            'projects' => $this->projects('active'),
```

- [ ] **Step 2: Make default project resolution active-only and deleted-blocking**

Modify `resolveProject()`:

```php
    private function resolveProject(?string $projectId): ?object
    {
        if ($projectId === null) {
            return DB::table('projects')->where('status', 'active')->orderBy('created_at')->first();
        }

        $project = DB::table('projects')->where('id', $projectId)->first();
        abort_unless($project && $project->status !== 'deleted', 404);

        return $project;
    }
```

Modify `abortUnlessProjectExists()`:

```php
    private function abortUnlessProjectExists(?string $projectId): void
    {
        if ($projectId === null) {
            return;
        }

        abort_unless(
            DB::table('projects')->where('id', $projectId)->where('status', '!=', 'deleted')->exists(),
            404,
        );
    }
```

- [ ] **Step 3: Wire project status filtering in `DashboardResourceController::projects()`**

Modify imports in `DashboardResourceController.php`:

```php
use App\Projects\ProjectLifecycleService;
```

Change the `projects()` method:

```php
    public function projects(Request $request, DashboardApiReader $reader): JsonResponse
    {
        $this->abortUnlessDashboardReader($request);

        $status = (string) $request->query('status', ProjectLifecycleService::ACTIVE);
        abort_unless(in_array($status, [
            ProjectLifecycleService::ACTIVE,
            ProjectLifecycleService::ARCHIVED,
            ProjectLifecycleService::DELETED,
        ], true), 422, 'Unknown project status filter.');

        if ($status === ProjectLifecycleService::DELETED) {
            abort_unless(
                $this->userHasRole($request->user(), 'Admin') || $this->userHasRole($request->user(), 'PM'),
                403,
            );
        }

        return response()->json($reader->projects($status));
    }
```

- [ ] **Step 4: Block operational dashboard mutations on non-active projects**

Inject lifecycle service where needed by method injection to avoid changing the controller constructor.

Change `updateTask()` signature:

```php
    public function updateTask(Request $request, DashboardApiReader $reader, ProjectLifecycleService $lifecycle, string $task): JsonResponse
```

Add at the top after the role guard:

```php
        if ($error = $lifecycle->assertTaskProjectActive($task)) {
            return $error;
        }
```

Change `updateProject()` signature:

```php
    public function updateProject(Request $request, DashboardApiReader $reader, ProjectLifecycleService $lifecycle, string $project): JsonResponse
```

Add after finding the project:

```php
        if ($error = $lifecycle->assertProjectActiveForDashboard($project)) {
            return $error;
        }
```

Change `retryImport()` and `review()` signatures to include `ProjectLifecycleService $lifecycle`, then add after the run exists:

```php
        if ($error = $lifecycle->assertRunProjectActive($run)) {
            return $error;
        }
```

- [ ] **Step 5: Run focused dashboard tests**

Run:

```bash
docker compose -f docker-compose.devboard.yaml exec -T app sh -lc 'APP_ENV=testing DB_CONNECTION=sqlite DB_DATABASE=:memory: DB_URL= DEVBOARD_DASHBOARD_ORIGINS=http://127.0.0.1:3000,http://localhost:3000 php artisan test tests/Feature/Dashboard/ProjectLifecycleDashboardApiTest.php tests/Feature/Dashboard/ProjectCrudDashboardApiTest.php tests/Feature/Dashboard/MultiprojectDashboardApiTest.php tests/Feature/Dashboard/DashboardApiContractTest.php --display-warnings'
```

Expected: PASS. If `MultiprojectDashboardApiTest` expects both active fixture projects, keep fixtures active and ensure overview still counts them.

- [ ] **Step 6: Commit reader/mutation guard changes if committing task-by-task**

```bash
git add backend/app/Dashboard/DashboardApiReader.php backend/app/Http/Controllers/Dashboard/Api/DashboardResourceController.php
git commit -m "feat: apply project lifecycle dashboard filters"
```

## Task 5: Plugin Namespace Lifecycle Guards

**Files:**

- Create: `backend/tests/Feature/Plugin/ProjectLifecyclePluginGuardTest.php`
- Modify: plugin controllers listed in File Structure.

- [ ] **Step 1: Add failing plugin guard tests**

Create `backend/tests/Feature/Plugin/ProjectLifecyclePluginGuardTest.php`:

```php
<?php

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

beforeEach(function () {
    Storage::fake('local');
    $this->seed(\Database\Seeders\DevBoardSeeder::class);
});

it('lists only active projects through plugin namespace', function () {
    $token = projectLifecyclePluginToken();
    projectLifecyclePluginProject('Plugin Archived', 'plugin-archived', 'archived');
    projectLifecyclePluginProject('Plugin Deleted', 'plugin-deleted', 'deleted');

    $projects = $this->withHeader('Authorization', "Bearer {$token}")
        ->getJson('/api/plugin/v1/projects')
        ->assertOk()
        ->json('projects');

    $statuses = collect($projects)->pluck('status')->unique()->all();

    expect($statuses)->toBe(['active']);
});

it('returns conflict for archived project plugin write operations', function () {
    $token = projectLifecyclePluginToken();
    $projectId = projectLifecyclePluginProject('Archived Plugin Project', 'archived-plugin-project', 'archived');
    $repositoryId = projectLifecyclePluginRepository($projectId);

    $this->withHeader('Authorization', "Bearer {$token}")
        ->getJson("/api/plugin/v1/projects/{$projectId}/repositories")
        ->assertConflict()
        ->assertJsonPath('error.code', 'project_archived');

    $this->withHeader('Authorization', "Bearer {$token}")
        ->postJson('/api/plugin/v1/runs', [
            'project_id' => $projectId,
            'repository_id' => $repositoryId,
            'run_type' => 'delta',
            'runtime_profile' => 'agent_plugin',
            'branch' => 'main',
            'base_branch' => 'main',
            'base_sha' => 'abc123',
            'head_sha' => 'def456',
            'dirty_status' => 'clean',
        ])
        ->assertConflict()
        ->assertJsonPath('error.code', 'project_archived');
});

it('hides deleted projects from plugin write operations', function () {
    $token = projectLifecyclePluginToken();
    $projectId = projectLifecyclePluginProject('Deleted Plugin Project', 'deleted-plugin-project', 'deleted');
    $repositoryId = projectLifecyclePluginRepository($projectId);

    $this->withHeader('Authorization', "Bearer {$token}")
        ->getJson("/api/plugin/v1/projects/{$projectId}/repositories")
        ->assertNotFound();

    $this->withHeader('Authorization', "Bearer {$token}")
        ->getJson("/api/plugin/v1/repositories/{$repositoryId}/instructions")
        ->assertNotFound();
});

function projectLifecyclePluginToken(): string
{
    $plain = 'dvb_test_lifecycle_token';
    $user = User::factory()->create(['status' => 'active']);

    DB::table('api_tokens')->insert([
        'id' => (string) Str::ulid(),
        'user_id' => $user->id,
        'device_id' => null,
        'name' => 'Lifecycle plugin token',
        'token_prefix' => 'dvb_test',
        'token_hash' => Hash::make($plain),
        'scopes' => json_encode([
            'projects.read',
            'repositories.read',
            'policies.read',
            'runs.write',
            'artifacts.write',
            'wiki.write',
        ], JSON_THROW_ON_ERROR),
        'expires_at' => now()->addDay(),
        'revoked_at' => null,
        'last_used_at' => null,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    return $plain;
}

function projectLifecyclePluginProject(string $name, string $slug, string $status): string
{
    $id = (string) Str::ulid();
    $adminId = DB::table('users')->where('email', 'admin@example.com')->value('id');

    DB::table('projects')->insert([
        'id' => $id,
        'name' => $name,
        'slug' => $slug,
        'description' => "{$name} description.",
        'status' => $status,
        'default_code_exposure_policy' => 'full_code_artifacts',
        'created_by_user_id' => $adminId,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    return $id;
}

function projectLifecyclePluginRepository(string $projectId): string
{
    $id = (string) Str::ulid();

    DB::table('repositories')->insert([
        'id' => $id,
        'project_id' => $projectId,
        'name' => 'plugin-repository',
        'slug' => 'plugin-repository',
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

    return $id;
}
```

- [ ] **Step 2: Run the RED plugin test**

Run:

```bash
docker compose -f docker-compose.devboard.yaml exec -T app sh -lc 'APP_ENV=testing DB_CONNECTION=sqlite DB_DATABASE=:memory: DB_URL= php artisan test tests/Feature/Plugin/ProjectLifecyclePluginGuardTest.php --display-warnings'
```

Expected: FAIL because plugin endpoints still expose archived/deleted project operations.

- [ ] **Step 3: Filter plugin project list**

Modify `backend/app/Http/Controllers/Plugin/ListProjectsController.php`:

```php
        $projects = DB::table('projects')
            ->where('status', 'active')
            ->orderBy('name')
```

- [ ] **Step 4: Add guards to project/repository plugin reads and writes**

For repository/project based controllers, inject `ProjectLifecycleService $lifecycle` via constructor or method parameter and return the guard result before the existing response.

Example for `ListRepositoriesController`:

```php
use App\Projects\ProjectLifecycleService;

class ListRepositoriesController extends Controller
{
    public function __construct(private readonly ProjectLifecycleService $lifecycle)
    {
    }

    public function __invoke(string $project): JsonResponse
    {
        if ($error = $this->lifecycle->pluginProjectWriteGuard($project)) {
            return $error;
        }

        $repositories = DB::table('repositories')
```

Example for `RepositoryInstructionsController` after fetching the joined row:

```php
        if ($error = $this->lifecycle->pluginProjectWriteGuard((string) $repositoryRow->project_id)) {
            return $error;
        }
```

Example for `RunStartController` after validating payload:

```php
        if ($error = $this->lifecycle->pluginProjectWriteGuard($validated['project_id'])) {
            return $error;
        }
```

Example for repository upload/workspace controllers:

```php
        if ($error = $this->lifecycle->pluginRepositoryWriteGuard($repository)) {
            return $error;
        }
```

Example for run-scoped controllers:

```php
        if ($error = $this->lifecycle->pluginRunWriteGuard($run)) {
            return $error;
        }
```

Example for Genesis/Delta chunk/finalize controllers:

```php
        if ($error = $this->lifecycle->pluginGenesisWriteGuard($genesisImport)) {
            return $error;
        }
```

```php
        if ($error = $this->lifecycle->pluginDeltaWriteGuard($deltaSync)) {
            return $error;
        }
```

Apply this exact guard mapping:

- `ListRepositoriesController`: `pluginProjectWriteGuard($project)`
- `RepositoryInstructionsController`: `pluginProjectWriteGuard($repositoryRow->project_id)`
- `RegisterLocalWorkspaceController`: `pluginRepositoryWriteGuard($repository)`
- `GenesisStartController`: `pluginRepositoryWriteGuard($repository)`
- `RunStartController`: `pluginProjectWriteGuard($validated['project_id'])`
- `RunHeartbeatController`: `pluginRunWriteGuard($run)`
- `RunEventController`: `pluginRunWriteGuard($run)`
- `RunFinishController`: `pluginRunWriteGuard($run)`
- `DeltaLocalSnapshotController`: `pluginRunWriteGuard($run)`
- `DeltaStartController`: `pluginRunWriteGuard($run)`
- `DeltaChunkController`: `pluginDeltaWriteGuard($deltaSync)`
- `DeltaFinalizeController`: `pluginDeltaWriteGuard($deltaSync)`
- `GenesisChunkController`: `pluginGenesisWriteGuard($genesisImport)`
- `GenesisFinalizeController`: `pluginGenesisWriteGuard($genesisImport)`
- `WikiRevisionController`: `pluginRunWriteGuard($run)` and `pluginProjectWriteGuard($validated['project_id'])`

Keep `/api/plugin/v1` as plugin-only; do not add browser UI calls here.

- [ ] **Step 5: Run plugin and focused backend tests**

Run:

```bash
docker compose -f docker-compose.devboard.yaml exec -T app sh -lc 'APP_ENV=testing DB_CONNECTION=sqlite DB_DATABASE=:memory: DB_URL= php artisan test tests/Feature/Plugin/ProjectLifecyclePluginGuardTest.php --display-warnings'
```

Expected: PASS.

Then run:

```bash
docker compose -f docker-compose.devboard.yaml exec -T app sh -lc 'APP_ENV=testing DB_CONNECTION=sqlite DB_DATABASE=:memory: DB_URL= DEVBOARD_DASHBOARD_ORIGINS=http://127.0.0.1:3000,http://localhost:3000 php artisan test tests/Feature/Dashboard/ProjectLifecycleDashboardApiTest.php tests/Feature/Dashboard/ProjectCrudDashboardApiTest.php tests/Feature/Dashboard/MultiprojectDashboardApiTest.php tests/Feature/Dashboard/DashboardApiContractTest.php tests/Feature/Plugin/ProjectLifecyclePluginGuardTest.php --display-warnings'
```

Expected: PASS.

- [ ] **Step 6: Commit plugin guard changes if committing task-by-task**

```bash
git add backend/app/Http/Controllers/Plugin backend/tests/Feature/Plugin/ProjectLifecyclePluginGuardTest.php
git commit -m "feat: block plugin operations for inactive projects"
```

## Task 6: Frontend Adapter Lifecycle Contract

**Files:**

- Modify: `/home/ubuntu/emergent_devboard_frontend/frontend/src/types/devboard.ts`
- Modify: `/home/ubuntu/emergent_devboard_frontend/frontend/src/api/devboardApi.ts`
- Modify: `/home/ubuntu/emergent_devboard_frontend/frontend/src/api/httpApi.ts`
- Modify: `/home/ubuntu/emergent_devboard_frontend/frontend/src/api/httpApi.test.ts`
- Modify: `/home/ubuntu/emergent_devboard_frontend/frontend/src/api/mockApi.ts`

- [ ] **Step 1: Add failing HTTP adapter tests**

Append to `/home/ubuntu/emergent_devboard_frontend/frontend/src/api/httpApi.test.ts`:

```ts
  it("filters projects by lifecycle status through dashboard API query params", async () => {
    fetchMock
      .mockResolvedValueOnce(jsonResponse([]))
      .mockResolvedValueOnce(jsonResponse([]))
      .mockResolvedValueOnce(jsonResponse([]));

    await httpApi.getProjects();
    await httpApi.getProjects("archived");
    await httpApi.getProjects("deleted");

    expect(fetchMock.mock.calls.map(([url]) => url)).toEqual([
      "http://127.0.0.1:8000/api/dashboard/projects",
      "http://127.0.0.1:8000/api/dashboard/projects?status=archived",
      "http://127.0.0.1:8000/api/dashboard/projects?status=deleted",
    ]);
  });

  it("uses dashboard API endpoints for lifecycle actions", async () => {
    Object.defineProperty(document, "cookie", {
      configurable: true,
      value: "XSRF-TOKEN=test-token",
    });
    fetchMock
      .mockResolvedValueOnce(jsonResponse({ id: "proj-client", status: "archived" }))
      .mockResolvedValueOnce(jsonResponse({ id: "proj-client", status: "active" }))
      .mockResolvedValueOnce(jsonResponse({ id: "proj-client", status: "deleted" }));

    await httpApi.archiveProject("proj-client", { reason: "Done." });
    await httpApi.restoreProject("proj-client", { reason: "Resume." });
    await httpApi.deleteProject("proj-client", { reason: "Trash." });

    expect(fetchMock).toHaveBeenNthCalledWith(
      1,
      "http://127.0.0.1:8000/api/dashboard/projects/proj-client/archive",
      expect.objectContaining({ method: "POST", body: JSON.stringify({ reason: "Done." }) }),
    );
    expect(fetchMock).toHaveBeenNthCalledWith(
      2,
      "http://127.0.0.1:8000/api/dashboard/projects/proj-client/restore",
      expect.objectContaining({ method: "POST", body: JSON.stringify({ reason: "Resume." }) }),
    );
    expect(fetchMock).toHaveBeenNthCalledWith(
      3,
      "http://127.0.0.1:8000/api/dashboard/projects/proj-client/delete",
      expect.objectContaining({ method: "POST", body: JSON.stringify({ reason: "Trash." }) }),
    );

    expect(fetchMock.mock.calls.map(([url]) => String(url)).some((url) => url.includes("/api/plugin/v1"))).toBe(false);
  });
```

- [ ] **Step 2: Run the RED frontend adapter test**

Run from `/home/ubuntu/emergent_devboard_frontend/frontend`:

```bash
CI=true npx -y yarn@1.22.22 test --watchAll=false src/api/httpApi.test.ts
```

Expected: FAIL because `getProjects` does not accept status and lifecycle methods do not exist.

- [ ] **Step 3: Add lifecycle types**

Modify `/home/ubuntu/emergent_devboard_frontend/frontend/src/types/devboard.ts` near project types:

```ts
export type ProjectStatus = "active" | "archived" | "deleted";

export type ProjectStatusFilter = ProjectStatus;
```

Add fields to `Project`:

```ts
  status: ProjectStatus;
  archived_at: string | null;
  deleted_at: string | null;
  restored_at: string | null;
```

Add:

```ts
export interface ProjectLifecycleInput {
  reason?: string;
}
```

- [ ] **Step 4: Update the adapter interface**

Modify `/home/ubuntu/emergent_devboard_frontend/frontend/src/api/devboardApi.ts` imports:

```ts
  PluginToken, DashboardOverview, Project, ProjectDetail, ProjectInput, ProjectLifecycleInput, ProjectStatusFilter, QualityCurrentState, QualityGate, QualityOverview,
```

Change project methods:

```ts
  getProjects(status?: ProjectStatusFilter): Promise<Project[]>;
  getProject(projectId: string): Promise<ProjectDetail>;
  createProject(payload: ProjectInput): Promise<ProjectDetail>;
  updateProject(projectId: string, payload: ProjectInput): Promise<ProjectDetail>;
  archiveProject(projectId: string, payload?: ProjectLifecycleInput): Promise<Project>;
  restoreProject(projectId: string, payload?: ProjectLifecycleInput): Promise<Project>;
  deleteProject(projectId: string, payload?: ProjectLifecycleInput): Promise<Project>;
```

- [ ] **Step 5: Update `httpApi`**

Modify imports in `/home/ubuntu/emergent_devboard_frontend/frontend/src/api/httpApi.ts`:

```ts
import { ProjectLifecycleInput, ProjectStatusFilter, User } from "@/types/devboard";
```

Add helper:

```ts
const projectListPath = (status?: ProjectStatusFilter) =>
  status ? `${D}/projects?status=${encodeURIComponent(status)}` : `${D}/projects`;
```

Change and add methods:

```ts
  getProjects: (status) => req("GET", projectListPath(status)),
  getProject: (id) => req("GET", `${D}/projects/${id}`),
  createProject: (payload) => req("POST", `${D}/projects`, payload),
  updateProject: (id, payload) => req("PATCH", `${D}/projects/${encodeURIComponent(id)}`, payload),
  archiveProject: (id, payload?: ProjectLifecycleInput) =>
    req("POST", `${D}/projects/${encodeURIComponent(id)}/archive`, payload ?? {}),
  restoreProject: (id, payload?: ProjectLifecycleInput) =>
    req("POST", `${D}/projects/${encodeURIComponent(id)}/restore`, payload ?? {}),
  deleteProject: (id, payload?: ProjectLifecycleInput) =>
    req("POST", `${D}/projects/${encodeURIComponent(id)}/delete`, payload ?? {}),
```

- [ ] **Step 6: Update mock API lifecycle state**

Modify `/home/ubuntu/emergent_devboard_frontend/frontend/src/api/mockApi.ts` imports:

```ts
import { DashboardOverview, LoginPayload, ProjectLifecycleInput, ProjectStatusFilter, Role, TaskColumn, User } from "@/types/devboard";
```

Change `mockOverview()` active counts and projects:

```ts
  const activeProjects = projects.filter((project) => project.status === "active");
```

Use `activeProjects` for `active_projects`, `repositories_awaiting_genesis`, and `projects`.

Change `getProjects`:

```ts
  async getProjects(status: ProjectStatusFilter = "active") {
    await delay();
    return clone(projects.filter((project) => project.status === status));
  },
```

Ensure createProject includes lifecycle fields:

```ts
      status: "active" as const,
      archived_at: null,
      deleted_at: null,
      restored_at: null,
```

Add lifecycle helper before `export const mockApi`:

```ts
function transitionProject(projectId: string, status: "active" | "archived" | "deleted", _payload?: ProjectLifecycleInput) {
  requireProject(projectId);
  const now = new Date().toISOString();
  projects = projects.map((project) => project.id === projectId
    ? {
        ...project,
        status,
        archived_at: status === "archived" ? now : project.archived_at,
        deleted_at: status === "deleted" ? now : project.deleted_at,
        restored_at: status === "active" ? now : project.restored_at,
        updated_at: now,
      }
    : project);
  projectDetails = {
    ...projectDetails,
    [projectId]: {
      ...projectDetails[projectId],
      status,
      archived_at: status === "archived" ? now : projectDetails[projectId].archived_at,
      deleted_at: status === "deleted" ? now : projectDetails[projectId].deleted_at,
      restored_at: status === "active" ? now : projectDetails[projectId].restored_at,
      updated_at: now,
    },
  };

  return clone(projectDetails[projectId]);
}
```

Add methods:

```ts
  async archiveProject(projectId, payload) {
    await delay(260);
    return transitionProject(projectId, "archived", payload);
  },
  async restoreProject(projectId, payload) {
    await delay(260);
    return transitionProject(projectId, "active", payload);
  },
  async deleteProject(projectId, payload) {
    await delay(260);
    return transitionProject(projectId, "deleted", payload);
  },
```

- [ ] **Step 7: Run frontend adapter test**

Run:

```bash
CI=true npx -y yarn@1.22.22 test --watchAll=false src/api/httpApi.test.ts
```

Expected: PASS.

- [ ] **Step 8: Commit frontend adapter changes if committing task-by-task**

```bash
git add frontend/src/types/devboard.ts frontend/src/api/devboardApi.ts frontend/src/api/httpApi.ts frontend/src/api/httpApi.test.ts frontend/src/api/mockApi.ts
git commit -m "feat: add project lifecycle frontend adapter"
```

Run the commit from `/home/ubuntu/emergent_devboard_frontend` only if the external frontend repo is being committed separately.

## Task 7: Frontend Projects UI Lifecycle Controls

**Files:**

- Modify: `/home/ubuntu/emergent_devboard_frontend/frontend/src/pages/ProjectsPage.tsx`

- [ ] **Step 1: Add UI state and lifecycle helpers**

Modify imports:

```ts
import { Archive, Boxes, Search, ArrowUpRight, FolderGit2, Pencil, Plus, RotateCcw, Trash2 } from "lucide-react";
import { Project, ProjectInput, ProjectLifecycleInput, ProjectStatusFilter } from "@/types/devboard";
```

Move the `useApi` call below the lifecycle status state. The top of `ProjectsPage()` should start like this:

```ts
  const nav = useNavigate();
  const { user } = useAuth();
  const [statusFilter, setStatusFilter] = useState<ProjectStatusFilter>("active");
  const state = useApi(() => api.getProjects(statusFilter), [statusFilter]);
  const [q, setQ] = useState("");
  const [form, setForm] = useState<ProjectInput>(emptyForm);
  const [editing, setEditing] = useState<Project | null>(null);
  const [open, setOpen] = useState(false);
  const [busy, setBusy] = useState(false);
  const [lifecycleProject, setLifecycleProject] = useState<Project | null>(null);
  const [lifecycleAction, setLifecycleAction] = useState<"archive" | "restore" | "delete" | null>(null);
  const [reason, setReason] = useState("");
  const [deleteConfirm, setDeleteConfirm] = useState("");
```

Change role guard:

```ts
  const canEditProjects = user?.role === "admin";
  const canManageLifecycle = user?.role === "admin" || user?.role === "pm";
```

Add helpers before return:

```ts
  const beginLifecycle = (project: Project, action: "archive" | "restore" | "delete") => {
    setLifecycleProject(project);
    setLifecycleAction(action);
    setReason("");
    setDeleteConfirm("");
  };

  const closeLifecycle = () => {
    if (busy) return;
    setLifecycleProject(null);
    setLifecycleAction(null);
    setReason("");
    setDeleteConfirm("");
  };

  const submitLifecycle = async (event: React.FormEvent) => {
    event.preventDefault();
    if (!lifecycleProject || !lifecycleAction) return;
    if (lifecycleAction === "delete" && deleteConfirm !== lifecycleProject.key && deleteConfirm !== lifecycleProject.name) {
      toast.error("Confirmation does not match.");
      return;
    }

    const payload: ProjectLifecycleInput = reason.trim() ? { reason: reason.trim() } : {};
    setBusy(true);
    try {
      if (lifecycleAction === "archive") await api.archiveProject(lifecycleProject.id, payload);
      if (lifecycleAction === "restore") await api.restoreProject(lifecycleProject.id, payload);
      if (lifecycleAction === "delete") await api.deleteProject(lifecycleProject.id, payload);
      toast.success(lifecycleAction === "delete" ? "Project moved to trash" : lifecycleAction === "restore" ? "Project restored" : "Project archived");
      closeLifecycle();
      state.reload();
    } catch (err: any) {
      toast.error(err?.message || "Project lifecycle update failed.");
    } finally {
      setBusy(false);
    }
  };
```

- [ ] **Step 2: Add status segmented control**

In `PageHeader actions`, before the search input, add:

```tsx
            <div className="flex h-9 items-center rounded-md border border-border bg-card p-0.5" data-testid="project-status-filter">
              {(["active", "archived", "deleted"] as ProjectStatusFilter[])
                .filter((status) => status !== "deleted" || canManageLifecycle)
                .map((status) => (
                  <button
                    key={status}
                    type="button"
                    onClick={() => setStatusFilter(status)}
                    className={`h-7 rounded px-2 text-xs capitalize transition-colors ${statusFilter === status ? "bg-primary text-primary-foreground" : "text-muted-foreground hover:bg-accent hover:text-foreground"}`}
                    data-testid={`project-status-${status}`}
                  >
                    {status === "deleted" ? "Trash" : status}
                  </button>
                ))}
            </div>
```

- [ ] **Step 3: Add lifecycle dialog**

After the existing create/edit project dialog, add:

```tsx
      <Dialog open={!!lifecycleAction} onOpenChange={(next) => { if (!next) closeLifecycle(); }}>
        <DialogContent data-testid="project-lifecycle-dialog">
          <form onSubmit={submitLifecycle} className="space-y-4">
            <DialogHeader>
              <DialogTitle>
                {lifecycleAction === "archive" && "Archive project"}
                {lifecycleAction === "restore" && "Restore project"}
                {lifecycleAction === "delete" && "Move project to trash"}
              </DialogTitle>
            </DialogHeader>

            {lifecycleAction === "delete" && lifecycleProject && (
              <div className="space-y-1.5">
                <Label htmlFor="project-delete-confirm" className="text-xs">Confirm</Label>
                <Input
                  id="project-delete-confirm"
                  value={deleteConfirm}
                  onChange={(e) => setDeleteConfirm(e.target.value)}
                  placeholder={lifecycleProject.key}
                  data-testid="project-delete-confirm-input"
                />
              </div>
            )}

            <div className="space-y-1.5">
              <Label htmlFor="project-lifecycle-reason" className="text-xs">Reason</Label>
              <Textarea
                id="project-lifecycle-reason"
                value={reason}
                onChange={(e) => setReason(e.target.value)}
                className="min-h-20"
                data-testid="project-lifecycle-reason-input"
              />
            </div>

            <DialogFooter>
              <Button type="button" variant="outline" onClick={closeLifecycle} disabled={busy}>Cancel</Button>
              <Button type="submit" disabled={busy} data-testid="project-lifecycle-submit">
                {busy ? "Saving..." : "Apply"}
              </Button>
            </DialogFooter>
          </form>
        </DialogContent>
      </Dialog>
```

- [ ] **Step 4: Add lifecycle action buttons on cards**

Inside each project card, replace the single edit button block with this action group:

```tsx
                    {(canEditProjects || canManageLifecycle) && (
                      <div className="absolute right-3 top-3 z-10 flex items-center gap-1">
                        {canEditProjects && p.status === "active" && (
                          <button
                            type="button"
                            onClick={() => beginEdit(p)}
                            data-testid={`edit-project-${p.id}`}
                            className="grid h-7 w-7 place-items-center rounded-md text-muted-foreground hover:bg-accent hover:text-foreground"
                            title="Edit project"
                          >
                            <Pencil className="h-3.5 w-3.5" />
                          </button>
                        )}
                        {canManageLifecycle && p.status === "active" && (
                          <button
                            type="button"
                            onClick={() => beginLifecycle(p, "archive")}
                            data-testid={`archive-project-${p.id}`}
                            className="grid h-7 w-7 place-items-center rounded-md text-muted-foreground hover:bg-accent hover:text-foreground"
                            title="Archive project"
                          >
                            <Archive className="h-3.5 w-3.5" />
                          </button>
                        )}
                        {canManageLifecycle && p.status !== "deleted" && (
                          <button
                            type="button"
                            onClick={() => beginLifecycle(p, "delete")}
                            data-testid={`delete-project-${p.id}`}
                            className="grid h-7 w-7 place-items-center rounded-md text-muted-foreground hover:bg-accent hover:text-foreground"
                            title="Move project to trash"
                          >
                            <Trash2 className="h-3.5 w-3.5" />
                          </button>
                        )}
                        {canManageLifecycle && p.status !== "active" && (
                          <button
                            type="button"
                            onClick={() => beginLifecycle(p, "restore")}
                            data-testid={`restore-project-${p.id}`}
                            className="grid h-7 w-7 place-items-center rounded-md text-muted-foreground hover:bg-accent hover:text-foreground"
                            title="Restore project"
                          >
                            <RotateCcw className="h-3.5 w-3.5" />
                          </button>
                        )}
                      </div>
                    )}
```

Keep the card body as a button that navigates to detail. Do not nest buttons inside that navigation button.

- [ ] **Step 5: Adjust empty and metric labels**

Keep compact operational copy:

```tsx
empty={<div className="py-12 text-center text-sm text-muted-foreground">No projects.</div>}
```

Metric labels can remain the same; they reflect the currently selected lifecycle filter because `projects` is the filtered array.

- [ ] **Step 6: Run frontend tests and build**

Run from `/home/ubuntu/emergent_devboard_frontend/frontend`:

```bash
CI=true npx -y yarn@1.22.22 test --watchAll=false
```

Expected: PASS.

Run:

```bash
REACT_APP_USE_MOCK=false REACT_APP_API_BASE_URL=http://127.0.0.1:18000 TSC_COMPILE_ON_ERROR=true npx -y yarn@1.22.22 build
```

Expected: build exits 0. Existing generated shadcn `.jsx` TypeScript warnings and the existing `GraphPage` hook warning may still appear.

- [ ] **Step 7: Commit UI changes if committing task-by-task**

```bash
git add frontend/src/pages/ProjectsPage.tsx
git commit -m "feat: add project lifecycle controls"
```

Run from `/home/ubuntu/emergent_devboard_frontend` only if the external frontend repo is being committed separately.

## Task 8: Route Inventory, Full Verification, And Browser Smoke

**Files:**

- Modify only if verification finds a defect in files touched by earlier tasks.

- [ ] **Step 1: Parse route registry YAML**

Run from `/home/ubuntu/dev-sandbox`:

```bash
ruby -e 'require "yaml"; YAML.load_file("backend/config/quality/route_registry.yaml"); puts "route registry yaml ok"'
```

Expected: `route registry yaml ok`.

- [ ] **Step 2: Check project dashboard routes**

Run:

```bash
docker compose -f docker-compose.devboard.yaml exec -T app php artisan route:list --path=api/dashboard/projects --except-vendor
```

Expected: routes include:

- `GET /api/dashboard/projects`
- `POST /api/dashboard/projects`
- `GET /api/dashboard/projects/{project}`
- `PATCH /api/dashboard/projects/{project}`
- `POST /api/dashboard/projects/{project}/archive`
- `POST /api/dashboard/projects/{project}/restore`
- `POST /api/dashboard/projects/{project}/delete`
- project-scoped read routes for Kanban, runs, wiki, artifacts

No hard-delete route should exist.

- [ ] **Step 3: Run backend focused tests**

Run:

```bash
docker compose -f docker-compose.devboard.yaml exec -T app sh -lc 'APP_ENV=testing DB_CONNECTION=sqlite DB_DATABASE=:memory: DB_URL= DEVBOARD_DASHBOARD_ORIGINS=http://127.0.0.1:3000,http://localhost:3000 php artisan test tests/Feature/Dashboard/ProjectLifecycleDashboardApiTest.php tests/Feature/Dashboard/ProjectCrudDashboardApiTest.php tests/Feature/Dashboard/MultiprojectDashboardApiTest.php tests/Feature/Dashboard/DashboardApiContractTest.php tests/Feature/Dashboard/AuthDashboardApiTest.php tests/Feature/Plugin/ProjectLifecyclePluginGuardTest.php tests/Feature/Quality/RouteInventoryCommandTest.php tests/Feature/Quality/RouteSmokeCommandTest.php --display-warnings'
```

Expected: PASS.

- [ ] **Step 4: Run frontend focused tests and build**

Run from `/home/ubuntu/emergent_devboard_frontend/frontend`:

```bash
CI=true npx -y yarn@1.22.22 test --watchAll=false
```

Expected: PASS.

Run:

```bash
REACT_APP_USE_MOCK=false REACT_APP_API_BASE_URL=http://127.0.0.1:18000 TSC_COMPILE_ON_ERROR=true npx -y yarn@1.22.22 build
```

Expected: exit 0 with only known generated warnings.

- [ ] **Step 5: Scan for forbidden browser namespace**

Run:

```bash
rg -n "/api/plugin/v1" /home/ubuntu/emergent_devboard_frontend/frontend/src || true
```

Expected: only guardrail comments or test assertions, no runtime browser endpoint implementation.

- [ ] **Step 6: Run diff checks**

Run from `/home/ubuntu/dev-sandbox`:

```bash
git diff --check
```

Run from `/home/ubuntu/emergent_devboard_frontend`:

```bash
git diff --check
```

Expected: both commands exit 0.

- [ ] **Step 7: Browser smoke with Playwright fallback**

Use the already-running frontend dev server if `http://127.0.0.1:3000` responds. If not, start it from `/home/ubuntu/emergent_devboard_frontend/frontend`:

```bash
REACT_APP_USE_MOCK=false REACT_APP_API_BASE_URL=http://127.0.0.1:18000 BROWSER=none HOST=127.0.0.1 PORT=3000 npx -y yarn@1.22.22 start
```

Smoke flow:

1. Open `http://127.0.0.1:3000`.
2. Login as `admin@devboard.local` / `devboard`.
3. Open `/projects`.
4. Create a temporary project if no active disposable project exists, or use the QA project left by the previous smoke.
5. Archive the project; verify it disappears from Active and appears under Archived.
6. Open Archived filter; verify Restore and Trash actions exist.
7. Restore the project; verify it returns to Active.
8. Move it to Trash using text confirmation; verify it appears only under Trash.
9. Restore from Trash.
10. Capture network calls and assert all API calls are `/api/dashboard/...`, with zero `/api/plugin/v1`.
11. Capture desktop and mobile screenshots under `/tmp/devboard-project-lifecycle-*.png`.

Because hard-delete is out of scope, do not attempt cleanup by deleting the smoke project. Record any left-over smoke project in `LOGBOOK_PROJECT.md`.

- [ ] **Step 8: Update logbook**

Update `ai-sandbox/logbooks/LOGBOOK_PROJECT.md` with:

- files changed;
- tests and exact results;
- browser smoke result and screenshot paths;
- any local DB smoke project left behind;
- known generated frontend warnings;
- residual risks.

- [ ] **Step 9: Final commit or handoff**

If the operator wants commits, commit backend and frontend repos separately and do not include unrelated dirty files.

Backend example:

```bash
git add ai-sandbox/logbooks/LOGBOOK_PROJECT.md backend/database/migrations/2026_06_24_000001_add_project_lifecycle_metadata_to_projects.php backend/app/Projects/ProjectLifecycleService.php backend/app/Http/Controllers/Dashboard/Api/DashboardProjectLifecycleController.php backend/app/Dashboard/DashboardApiReader.php backend/app/Http/Controllers/Dashboard/Api/DashboardResourceController.php backend/routes/web.php backend/config/quality/route_registry.yaml backend/tests/Feature/Dashboard/ProjectLifecycleDashboardApiTest.php backend/tests/Feature/Plugin/ProjectLifecyclePluginGuardTest.php
git commit -m "feat: add project lifecycle policy"
```

Frontend example from `/home/ubuntu/emergent_devboard_frontend`:

```bash
git add frontend/src/types/devboard.ts frontend/src/api/devboardApi.ts frontend/src/api/httpApi.ts frontend/src/api/httpApi.test.ts frontend/src/api/mockApi.ts frontend/src/pages/ProjectsPage.tsx
git commit -m "feat: add project lifecycle UI"
```

If commits are not wanted, leave files unstaged and provide a concise final handoff.
