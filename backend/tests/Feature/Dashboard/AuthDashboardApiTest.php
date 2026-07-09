<?php

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(\Database\Seeders\DevBoardSeeder::class);
});

it('returns the authenticated dashboard user for the frontend adapter', function () {
    $user = dashboardApiUserWithRole('Developer');

    $this->actingAs($user)
        ->getJson('/api/dashboard/me')
        ->assertOk()
        ->assertJsonPath('id', (string) $user->id)
        ->assertJsonPath('name', $user->name)
        ->assertJsonPath('email', $user->email)
        ->assertJsonPath('role', 'developer')
        ->assertJsonStructure(['avatar_color']);
});

it('authenticates and logs out through dashboard json endpoints', function () {
    $user = User::factory()->create([
        'email' => 'pm@example.com',
        'password' => Hash::make('correct-password'),
    ]);
    dashboardApiAttachRole($user, 'PM');

    $this->postJson('/api/dashboard/login', [
        'email' => 'pm@example.com',
        'password' => 'correct-password',
        'role' => 'admin',
    ])
        ->assertOk()
        ->assertJsonPath('email', 'pm@example.com')
        ->assertJsonPath('role', 'pm');

    $this->assertAuthenticatedAs($user);

    $this->postJson('/api/dashboard/logout')
        ->assertNoContent();

    $this->assertGuest();
});

it('authenticates the generated frontend seeded login profiles', function (string $email, string $role) {
    $this->postJson('/api/dashboard/login', [
        'email' => $email,
        'password' => 'devboard',
    ])
        ->assertOk()
        ->assertJsonPath('email', $email)
        ->assertJsonPath('role', $role);
})->with([
    'admin' => ['admin@devboard.local', 'admin'],
    'pm' => ['pm@devboard.local', 'pm'],
    'developer' => ['dev@devboard.local', 'developer'],
    'sysadmin' => ['sysadmin@devboard.local', 'sysadmin'],
]);

it('returns unauthorized for guest dashboard me requests', function () {
    $this->getJson('/api/dashboard/me')
        ->assertUnauthorized();
});

it('denies non-admin dashboard users from creating plugin tokens', function () {
    $developer = dashboardApiUserWithRole('Developer');

    $this->actingAs($developer)
        ->postJson('/api/dashboard/admin/plugin-tokens', [
            'name' => 'unauthorized token',
            'scopes' => ['projects.read'],
        ])
        ->assertForbidden();

    expect(DB::table('audit_logs')
        ->where('action', 'permission.denied')
        ->where('target_id', 'manage-plugin-tokens')
        ->exists())->toBeTrue();
});

it('denies non-admin dashboard users from revoking plugin tokens', function () {
    $admin = dashboardApiUserWithRole('Admin');
    $developer = dashboardApiUserWithRole('Developer');

    $token = $this->actingAs($admin)
        ->postJson('/api/dashboard/admin/plugin-tokens', [
            'name' => 'token to be revoked',
            'scopes' => ['projects.read'],
        ])
        ->assertOk()
        ->json();

    $this->actingAs($developer)
        ->deleteJson("/api/dashboard/admin/plugin-tokens/{$token['id']}")
        ->assertForbidden();

    expect(DB::table('audit_logs')
        ->where('action', 'permission.denied')
        ->where('target_id', 'manage-plugin-tokens')
        ->exists())->toBeTrue();
});

it('denies non-admin dashboard users from rotating plugin tokens', function () {
    $admin = dashboardApiUserWithRole('Admin');
    $pm = dashboardApiUserWithRole('PM');

    $token = $this->actingAs($admin)
        ->postJson('/api/dashboard/admin/plugin-tokens', [
            'name' => 'token to rotate',
            'scopes' => ['projects.read'],
        ])
        ->assertOk()
        ->json();

    $this->actingAs($pm)
        ->postJson("/api/dashboard/admin/plugin-tokens/{$token['id']}/rotate")
        ->assertForbidden();

    expect(DB::table('audit_logs')
        ->where('action', 'permission.denied')
        ->where('target_id', 'manage-plugin-tokens')
        ->exists())->toBeTrue();
});

it('permits admin users to manage plugin tokens through gates', function () {
    $admin = dashboardApiUserWithRole('Admin');

    $this->actingAs($admin)
        ->postJson('/api/dashboard/admin/plugin-tokens', [
            'name' => 'gate-managed token',
            'scopes' => ['projects.read', 'runs.read'],
        ])
        ->assertOk()
        ->assertJsonPath('name', 'gate-managed token');

    expect(DB::table('audit_logs')
        ->where('action', 'permission.denied')
        ->exists())->toBeFalse();
});

it('configures credentialed cors for the generated frontend adapter', function () {
    expect(config('cors.supports_credentials'))->toBeTrue()
        ->and(config('cors.paths'))->toContain('api/*')
        ->and(config('cors.paths'))->toContain('sanctum/csrf-cookie')
        ->and(config('cors.allowed_origins'))->toContain('http://127.0.0.1:3000');
});

function dashboardApiUserWithRole(string $roleName): User
{
    $user = User::factory()->create();
    dashboardApiAttachRole($user, $roleName);

    return $user;
}

function dashboardApiAttachRole(User $user, string $roleName): void
{
    $roleId = DB::table('roles')->where('name', $roleName)->value('id');

    DB::table('role_user')->insert([
        'user_id' => $user->id,
        'role_id' => $roleId,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}
