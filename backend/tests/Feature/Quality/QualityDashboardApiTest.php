<?php

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Testing\Fluent\AssertableJson;

uses(RefreshDatabase::class);

beforeEach(function () {
    File::deleteDirectory(base_path('var/quality'));
    $this->seed(\Database\Seeders\DevBoardSeeder::class);
});

afterEach(function () {
    File::deleteDirectory(base_path('var/quality'));
});

it('serves frontend quality contract through dashboard api endpoints', function () {
    Artisan::call('quality:route-inventory', ['--format' => 'json']);
    Artisan::call('quality:route-smoke', ['--actor' => 'guest', '--format' => 'json']);
    Artisan::call('quality:check-gates', ['--gate' => 'pull_request', '--format' => 'json']);

    $admin = qualityApiUserWithRole('Admin');

    $this->actingAs($admin)
        ->getJson('/api/dashboard/quality/overview')
        ->assertOk()
        ->assertJsonPath('overall_status', 'warning')
        ->assertJsonPath('latest_gate.gate', 'pull_request')
        ->assertJsonPath('latest_gate.status', 'pass')
        ->assertJsonPath('latest_route_smoke.status', 'warning')
        ->assertJsonStructure([
            'stale_or_missing' => [['label', 'reason']],
            'counters' => ['passed', 'failed', 'warnings', 'skipped'],
        ]);

    $this->actingAs($admin)
        ->getJson('/api/dashboard/quality/current-state')
        ->assertOk()
        ->assertJsonPath('deterministic', true)
        ->assertJsonStructure([
            'description',
            'current_state',
            'desired_state',
            'transition_notes',
        ]);

    $this->actingAs($admin)
        ->getJson('/api/dashboard/quality/reports')
        ->assertOk()
        ->assertJsonCount(3)
        ->assertJson(fn (AssertableJson $json) => $json
            ->has('0.tool')
            ->has('0.status')
            ->has('0.generated_at')
            ->has('0.summary.total')
            ->has('0.findings')
            ->etc());

    $this->actingAs($admin)
        ->getJson('/api/dashboard/quality/route-inventory')
        ->assertOk()
        ->assertJson(fn (AssertableJson $json) => $json
            ->has('0.id')
            ->has('0.name')
            ->has('0.method')
            ->has('0.path')
            ->has('0.controller_action')
            ->has('0.classification')
            ->has('0.configured')
            ->has('0.parameter_provider')
            ->etc());

    $this->actingAs($admin)
        ->getJson('/api/dashboard/quality/route-smoke')
        ->assertOk()
        ->assertJsonStructure([
            'rows' => [[
                'id',
                'route',
                'actor',
                'expected_status',
                'actual_status',
                'result',
                'blocking',
            ]],
            'matrix' => [[
                'resource',
                'decisions',
            ]],
        ]);

    $this->actingAs($admin)
        ->getJson('/api/dashboard/quality/gates/pull_request')
        ->assertOk()
        ->assertJsonPath('gate', 'pull_request')
        ->assertJsonPath('status', 'pass')
        ->assertJsonStructure([
            'blocking_findings',
            'warnings',
            'human_approvals_required',
        ]);

    $this->actingAs($admin)
        ->getJson('/api/dashboard/quality/roadmap')
        ->assertOk()
        ->assertJsonStructure([
            'phases' => [['id', 'phase', 'title', 'status', 'items']],
            'checks' => [['id', 'tool', 'category', 'state', 'requires_human_approval', 'destructive', 'description', 'last_run_at']],
            'truth' => [['id', 'feature', 'domain_rules', 'required_tests', 'risk', 'evidence', 'marking', 'source']],
        ]);
});

it('requires dashboard authentication for quality api endpoints', function () {
    $this->getJson('/api/dashboard/quality/overview')
        ->assertUnauthorized();
});

it('runs whitelisted quality checks only for quality operators', function () {
    $admin = qualityApiUserWithRole('Admin');
    $developer = qualityApiUserWithRole('Developer');

    $this->actingAs($developer)
        ->postJson('/api/dashboard/quality/runs', ['tool' => 'route-smoke', 'confirm' => false])
        ->assertForbidden();

    $this->actingAs($admin)
        ->postJson('/api/dashboard/quality/runs', ['tool' => 'route-smoke', 'confirm' => false])
        ->assertOk()
        ->assertJsonPath('tool', 'route-smoke')
        ->assertJsonPath('actor', 'guest')
        ->assertJsonStructure(['summary', 'findings']);

    $this->actingAs($admin)
        ->postJson('/api/dashboard/quality/runs', ['tool' => 'zap-active', 'confirm' => true])
        ->assertUnprocessable()
        ->assertJsonPath('error.code', 'quality_tool_not_supported');
});

function qualityApiUserWithRole(string $roleName): User
{
    $user = User::factory()->create();
    $roleId = DB::table('roles')->where('name', $roleName)->value('id');

    DB::table('role_user')->insert([
        'user_id' => $user->id,
        'role_id' => $roleId,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    return $user;
}
