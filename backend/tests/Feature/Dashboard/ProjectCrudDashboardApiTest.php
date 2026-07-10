<?php

use App\Models\User;
use Database\Seeders\DevBoardSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

beforeEach(function () {
    Storage::fake('local');
    $this->seed(DevBoardSeeder::class);
});

it('creates a project with a default kanban board through the dashboard API', function () {
    $admin = projectCrudDashboardApiUserWithRole('Admin');

    $created = $this->actingAs($admin)
        ->postJson('/api/dashboard/projects', [
            'name' => 'Client Portal',
            'key' => 'client-portal',
            'description' => 'Client-facing project workspace.',
        ])
        ->assertCreated()
        ->assertJsonPath('key', 'client-portal')
        ->assertJsonPath('name', 'Client Portal')
        ->assertJsonPath('description', 'Client-facing project workspace.')
        ->assertJsonPath('repository_count', 0)
        ->assertJsonPath('open_tasks', 0)
        ->json();

    expect(DB::table('projects')->where('slug', 'client-portal')->exists())->toBeTrue();

    $boardId = DB::table('kanban_boards')
        ->where('project_id', $created['id'])
        ->where('is_default', true)
        ->value('id');

    expect($boardId)->not->toBeNull();

    $statuses = DB::table('kanban_columns')
        ->where('board_id', $boardId)
        ->orderBy('position')
        ->pluck('status_key')
        ->all();

    expect($statuses)->toBe(['backlog', 'ready', 'in_progress', 'blocked', 'review', 'done']);

    $this->actingAs($admin)
        ->getJson("/api/dashboard/projects/{$created['id']}/kanban")
        ->assertOk()
        ->assertJsonPath('columns.0.id', 'backlog')
        ->assertJsonPath('columns.5.id', 'done')
        ->assertJsonCount(0, 'tasks');
});

it('updates project identity fields without archive or delete behavior', function () {
    $admin = projectCrudDashboardApiUserWithRole('Admin');

    $projectId = $this->actingAs($admin)
        ->postJson('/api/dashboard/projects', [
            'name' => 'Original Project',
            'key' => 'original-project',
            'description' => 'Original description.',
        ])
        ->assertCreated()
        ->json('id');

    $this->actingAs($admin)
        ->patchJson("/api/dashboard/projects/{$projectId}", [
            'name' => 'Renamed Project',
            'key' => 'renamed-project',
            'description' => 'Updated description.',
        ])
        ->assertOk()
        ->assertJsonPath('id', $projectId)
        ->assertJsonPath('key', 'renamed-project')
        ->assertJsonPath('name', 'Renamed Project')
        ->assertJsonPath('description', 'Updated description.');

    expect(DB::table('projects')->where('id', $projectId)->value('slug'))->toBe('renamed-project')
        ->and(DB::table('projects')->where('id', $projectId)->value('status'))->toBe('active');
});

it('supports partial project patch without changing omitted fields', function () {
    $admin = projectCrudDashboardApiUserWithRole('Admin');

    $projectId = $this->actingAs($admin)
        ->postJson('/api/dashboard/projects', [
            'name' => 'Partial Project',
            'key' => 'partial-project',
            'description' => 'Original description.',
        ])
        ->assertCreated()
        ->json('id');

    $this->actingAs($admin)
        ->patchJson("/api/dashboard/projects/{$projectId}", [
            'description' => 'Description only.',
        ])
        ->assertOk()
        ->assertJsonPath('id', $projectId)
        ->assertJsonPath('key', 'partial-project')
        ->assertJsonPath('name', 'Partial Project')
        ->assertJsonPath('description', 'Description only.');
});

it('validates unique project keys and unknown projects', function () {
    $admin = projectCrudDashboardApiUserWithRole('Admin');

    $this->actingAs($admin)
        ->postJson('/api/dashboard/projects', [
            'name' => 'Duplicate Demo',
            'key' => 'demo-project',
            'description' => 'Should conflict with seeded project.',
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['key']);

    $this->actingAs($admin)
        ->patchJson('/api/dashboard/projects/01KV0000000000000000000000', [
            'name' => 'Missing Project',
            'key' => 'missing-project',
            'description' => 'Unknown project.',
        ])
        ->assertNotFound();
});

it('restricts project creation and editing to admins', function () {
    $admin = projectCrudDashboardApiUserWithRole('Admin');
    $pm = projectCrudDashboardApiUserWithRole('PM');

    $projectId = $this->actingAs($admin)
        ->postJson('/api/dashboard/projects', [
            'name' => 'Admin Project',
            'key' => 'admin-project',
            'description' => 'Created by admin.',
        ])
        ->assertCreated()
        ->json('id');

    $this->actingAs($pm)
        ->postJson('/api/dashboard/projects', [
            'name' => 'PM Project',
            'key' => 'pm-project',
            'description' => 'Should be forbidden.',
        ])
        ->assertForbidden();

    $this->actingAs($pm)
        ->patchJson("/api/dashboard/projects/{$projectId}", [
            'name' => 'PM Rename',
            'key' => 'pm-rename',
            'description' => 'Should be forbidden.',
        ])
        ->assertForbidden();
});

function projectCrudDashboardApiUserWithRole(string $roleName): User
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
