<?php

use App\Models\User;
use Database\Seeders\DevBoardSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Inertia\Testing\AssertableInertia as Assert;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->withoutVite();
    $this->seed(DevBoardSeeder::class);
});

it('redirects dashboard guests to login', function () {
    $this->get('/kanban')->assertRedirect('/login');
});

it('renders the dashboard login form', function () {
    $this->get('/login')
        ->assertOk()
        ->assertSee('name="csrf-token"', false)
        ->assertInertia(fn (Assert $page) => $page->component('Auth/Login'));
});

it('authenticates the seeded admin user', function () {
    $this->post('/login', [
        'email' => 'admin@example.com',
        'password' => 'password',
    ])->assertRedirect('/kanban');

    $this->assertAuthenticated();
});

it('rejects web login for a non-active user', function () {
    User::factory()->create([
        'email' => 'inactive@example.com',
        'password' => Hash::make('correct-password'),
        'status' => 'inactive',
    ]);

    $this->post('/login', [
        'email' => 'inactive@example.com',
        'password' => 'correct-password',
    ])->assertSessionHasErrors('email');

    $this->assertGuest();
});

it('denies dashboard pages to Agent users and users without a role', function () {
    $agent = User::factory()->create(['status' => 'active']);
    $roleId = DB::table('roles')->where('name', 'Agent')->value('id');
    DB::table('role_user')->insert([
        'user_id' => $agent->id,
        'role_id' => $roleId,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    $withoutRole = User::factory()->create(['status' => 'active']);

    foreach (['/kanban', '/artifacts', '/graph', '/projects/test', '/runs', '/runs/test', '/tasks/test', '/wiki', '/wiki/pages/test'] as $path) {
        $this->actingAs($agent)->get($path)->assertForbidden();
        $this->actingAs($withoutRole)->get($path)->assertForbidden();
    }
});

it('invalidates an existing dashboard session when the user becomes inactive', function () {
    $user = User::factory()->create(['status' => 'inactive']);

    $this->actingAs($user)
        ->get('/kanban')
        ->assertRedirect('/login');

    $this->assertGuest();
});

it('logs out a dashboard user', function () {
    $this->post('/login', [
        'email' => 'admin@example.com',
        'password' => 'password',
    ]);

    $this->post('/logout')->assertRedirect('/login');

    $this->assertGuest();
});
