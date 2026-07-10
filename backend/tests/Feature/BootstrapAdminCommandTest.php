<?php

use Database\Seeders\DevBoardSeeder;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

uses(RefreshDatabase::class);

it('creates the first administrator without accepting a password argument', function () {
    $this->seed(DevBoardSeeder::class);
    DB::table('role_user')->delete();

    $this->artisan('devboard:bootstrap-admin', [
        '--name' => 'Production Admin',
        '--email' => 'operator@example.com',
    ])
        ->expectsQuestion('Admin password', 'Strong-Password-123!')
        ->expectsQuestion('Confirm admin password', 'Strong-Password-123!')
        ->expectsOutputToContain('Administrator operator@example.com created.')
        ->assertSuccessful();

    $user = DB::table('users')->where('email', 'operator@example.com')->first();

    expect($user)->not->toBeNull()
        ->and($user->status)->toBe('active')
        ->and(Hash::check('Strong-Password-123!', $user->password))->toBeTrue()
        ->and(DB::table('role_user')
            ->join('roles', 'roles.id', '=', 'role_user.role_id')
            ->where('role_user.user_id', $user->id)
            ->where('roles.name', 'Admin')
            ->exists())->toBeTrue();
});

it('refuses to replace an existing administrator', function () {
    $this->seed(DevBoardSeeder::class);
    $roleId = DB::table('roles')->where('name', 'Admin')->value('id');
    $userId = DB::table('users')->insertGetId([
        'name' => 'Existing Admin',
        'email' => 'existing@example.com',
        'password' => Hash::make('Existing-Password-123!'),
        'status' => 'active',
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    DB::table('role_user')->insert([
        'user_id' => $userId,
        'role_id' => $roleId,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $this->artisan('devboard:bootstrap-admin', [
        '--name' => 'Replacement Admin',
        '--email' => 'replacement@example.com',
    ])
        ->expectsOutputToContain('An administrator already exists.')
        ->assertFailed();

    expect(DB::table('users')->where('email', 'replacement@example.com')->exists())->toBeFalse();
});

it('rechecks for an administrator after input without creating an orphaned user', function () {
    $this->seed(DevBoardSeeder::class);
    DB::table('role_user')->delete();

    $roleId = DB::table('roles')->where('name', 'Admin')->value('id');
    $injected = false;

    DB::listen(function (QueryExecuted $query) use (&$injected, $roleId): void {
        $sql = strtolower($query->sql);

        if ($injected || ! str_contains($sql, 'role_user') || ! str_contains($sql, 'exists')) {
            return;
        }

        $injected = true;
        $now = now();
        $userId = DB::table('users')->insertGetId([
            'name' => 'Concurrent Admin',
            'email' => 'concurrent@example.com',
            'password' => Hash::make('Concurrent-Password-123!'),
            'status' => 'active',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        DB::table('role_user')->insert([
            'user_id' => $userId,
            'role_id' => $roleId,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    });

    $this->artisan('devboard:bootstrap-admin', [
        '--name' => 'Racing Admin',
        '--email' => 'racing@example.com',
    ])
        ->expectsQuestion('Admin password', 'Strong-Password-123!')
        ->expectsQuestion('Confirm admin password', 'Strong-Password-123!')
        ->expectsOutputToContain('An administrator already exists.')
        ->assertFailed();

    expect($injected)->toBeTrue()
        ->and(DB::table('users')->where('email', 'racing@example.com')->exists())->toBeFalse()
        ->and(DB::table('users')->where('email', 'concurrent@example.com')->exists())->toBeTrue()
        ->and(DB::table('role_user')->where('role_id', $roleId)->count())->toBe(1);
});
