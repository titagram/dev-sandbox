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

it('lets a developer create a manual project wiki page', function () {
    $developer = wikiManualDashboardApiUserWithRole('Developer');
    $projectId = (string) DB::table('projects')->where('slug', 'demo-project')->value('id');

    $response = $this->actingAs($developer)
        ->postJson("/api/dashboard/projects/{$projectId}/wiki/pages", [
            'slug' => 'operations/manual-handoff',
            'title' => 'Manual Handoff',
            'page_type' => 'runbook',
            'source_status' => 'developer_provided',
            'content_markdown' => "## Manual Handoff\n\nDashboard user supplied runbook.",
        ])
        ->assertCreated()
        ->assertJsonPath('title', 'Manual Handoff')
        ->assertJsonPath('project_id', $projectId)
        ->assertJsonPath('category', 'runbook')
        ->assertJsonPath('source_status', 'needs_verification')
        ->assertJsonPath('body_markdown', "## Manual Handoff\n\nDashboard user supplied runbook.")
        ->json();

    $pageId = $response['id'];

    expect(DB::table('wiki_pages')->where('id', $pageId)->value('slug'))->toBe('operations/manual-handoff')
        ->and(DB::table('wiki_revisions')->where('wiki_page_id', $pageId)->value('producer'))->toBe('dashboard_user')
        ->and(DB::table('wiki_revisions')->where('wiki_page_id', $pageId)->value('source_type'))->toBe('user_manual')
        ->and(DB::table('wiki_revisions')->where('wiki_page_id', $pageId)->value('author_user_id'))->toBe($developer->id)
        ->and(DB::table('audit_logs')->where('action', 'wiki.updated')->where('target_id', $pageId)->value('actor_type'))->toBe('user');
});

it('updates a manual wiki page by appending a new revision', function () {
    $developer = wikiManualDashboardApiUserWithRole('Developer');
    $projectId = (string) DB::table('projects')->where('slug', 'demo-project')->value('id');

    $created = $this->actingAs($developer)
        ->postJson("/api/dashboard/projects/{$projectId}/wiki/pages", [
            'slug' => 'architecture/manual-overview',
            'title' => 'Manual Overview',
            'page_type' => 'technical',
            'source_status' => 'needs_verification',
            'content_markdown' => 'Initial wiki body.',
        ])
        ->assertCreated()
        ->json();

    $this->actingAs($developer)
        ->patchJson("/api/dashboard/wiki/pages/{$created['id']}", [
            'title' => 'Manual Overview Updated',
            'source_status' => 'developer_provided',
            'content_markdown' => 'Updated wiki body from the dashboard.',
        ])
        ->assertOk()
        ->assertJsonPath('title', 'Manual Overview Updated')
        ->assertJsonPath('source_status', 'needs_verification')
        ->assertJsonPath('body_markdown', 'Updated wiki body from the dashboard.');

    expect(DB::table('wiki_revisions')->where('wiki_page_id', $created['id'])->count())->toBe(2)
        ->and(DB::table('wiki_pages')->where('id', $created['id'])->value('slug'))->toBe('architecture/manual-overview');
});

it('cannot self-assign a manual wiki verification status', function (string $sourceStatus) {
    $developer = wikiManualDashboardApiUserWithRole('Developer');
    $projectId = (string) DB::table('projects')->where('slug', 'demo-project')->value('id');
    $slug = 'verification/'.str_replace('_', '-', $sourceStatus);

    $response = $this->actingAs($developer)
        ->postJson("/api/dashboard/projects/{$projectId}/wiki/pages", [
            'slug' => $slug,
            'title' => 'Manual Verification Boundary',
            'page_type' => 'technical',
            'source_status' => $sourceStatus,
            'content_markdown' => 'A manually supplied page remains unverified.',
        ])
        ->assertCreated()
        ->assertJsonPath('source_status', 'needs_verification')
        ->json();

    expect(DB::table('wiki_revisions')->where('wiki_page_id', $response['id'])->latest('created_at')->value('source_status'))
        ->toBe('needs_verification');
})->with([
    'developer_provided',
    'verified_from_code',
    'ai_generated',
    'stale',
    'conflict_with_code',
]);

it('keeps a manually submitted verified status in the verification queue', function () {
    $developer = wikiManualDashboardApiUserWithRole('Developer');
    $projectId = (string) DB::table('projects')->where('slug', 'demo-project')->value('id');

    $this->actingAs($developer)
        ->postJson("/api/dashboard/projects/{$projectId}/wiki/pages", [
            'slug' => 'verified/missing-evidence',
            'title' => 'Missing Evidence',
            'page_type' => 'technical',
            'source_status' => 'verified_from_code',
            'content_markdown' => 'This claim needs evidence.',
        ])
        ->assertCreated()
        ->assertJsonPath('source_status', 'needs_verification');
});

it('exposes additive wiki facets for readable index filters', function () {
    $developer = wikiManualDashboardApiUserWithRole('Developer');
    $projectId = (string) DB::table('projects')->where('slug', 'demo-project')->value('id');

    $this->actingAs($developer)
        ->postJson("/api/dashboard/projects/{$projectId}/wiki/pages", [
            'slug' => 'operations/filter-facets',
            'title' => 'Filter Facets',
            'page_type' => 'runbook',
            'source_status' => 'developer_provided',
            'content_markdown' => 'Manual runbook.',
        ])
        ->assertCreated();

    $page = collect($this->actingAs($developer)->getJson("/api/dashboard/projects/{$projectId}/wiki")->assertOk()->json())
        ->firstWhere('title', 'Filter Facets');

    expect($page)->not->toBeNull()
        ->and($page['page_type'])->toBe('runbook')
        ->and($page['audience'])->toBe('operations')
        ->and($page['source_type'])->toBe('user_manual');
});

it('blocks sysadmin from manually writing wiki pages', function () {
    $sysadmin = wikiManualDashboardApiUserWithRole('Sysadmin');
    $projectId = (string) DB::table('projects')->where('slug', 'demo-project')->value('id');

    $this->actingAs($sysadmin)
        ->postJson("/api/dashboard/projects/{$projectId}/wiki/pages", [
            'slug' => 'blocked/sysadmin',
            'title' => 'Blocked Sysadmin Write',
            'page_type' => 'technical',
            'source_status' => 'developer_provided',
            'content_markdown' => 'Sysadmin is read-only for wiki mutation.',
        ])
        ->assertForbidden();
});

it('blocks manual wiki writes to inactive projects', function (string $status) {
    $developer = wikiManualDashboardApiUserWithRole('Developer');
    $project = wikiManualDashboardApiProject(Str::headline($status).' Wiki Project', "{$status}-wiki-project", $status);

    $this->actingAs($developer)
        ->postJson("/api/dashboard/projects/{$project['project_id']}/wiki/pages", [
            'slug' => 'inactive/project-wiki',
            'title' => 'Inactive Project Wiki',
            'page_type' => 'technical',
            'source_status' => 'developer_provided',
            'content_markdown' => 'Inactive projects should be read-only.',
        ])
        ->assertConflict()
        ->assertJsonPath('error.code', 'project_not_active');
})->with(['archived', 'deleted']);

function wikiManualDashboardApiUserWithRole(string $roleName): User
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
function wikiManualDashboardApiProject(string $name, string $slug, string $status = 'active'): array
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

    return [
        'project_id' => $projectId,
        'repository_id' => $repositoryId,
    ];
}
