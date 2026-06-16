<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->withoutVite();
    $this->seed(\Database\Seeders\DevBoardSeeder::class);
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

it('logs out a dashboard user', function () {
    $this->post('/login', [
        'email' => 'admin@example.com',
        'password' => 'password',
    ]);

    $this->post('/logout')->assertRedirect('/login');

    $this->assertGuest();
});
