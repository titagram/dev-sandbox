<?php

use App\Models\User;
use Database\Seeders\DevBoardSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(DevBoardSeeder::class);
});

it('exposes the backend capability catalog and decoded token and agent grants', function () {
    $admin = hadesAdminDashboardUser('Admin');
    $projectId = (string) DB::table('projects')->where('status', 'active')->value('id');
    $capabilities = [
        'read_files',
        'read_source_slice',
        'project_inspection',
        'sync_git_tree',
        'populate_backend_ast',
        'populate_project_wiki',
    ];

    $created = $this->actingAs($admin)
        ->postJson('/api/dashboard/admin/hades/bootstrap-tokens', [
            'project_id' => $projectId,
            'name' => 'All capabilities',
            'allowed_capabilities' => $capabilities,
        ])
        ->assertCreated()
        ->json();

    $agentId = (string) Str::ulid();
    $bindingId = (string) Str::ulid();
    $now = now();
    DB::table('hades_agents')->insert([
        'id' => $agentId,
        'project_id' => $projectId,
        'external_agent_id' => 'admin-snapshot-agent',
        'label' => 'Admin snapshot agent',
        'platform' => 'linux',
        'version' => 'test',
        'declared_capabilities' => json_encode($capabilities, JSON_THROW_ON_ERROR),
        'effective_capabilities' => json_encode(['read_files', 'populate_project_wiki'], JSON_THROW_ON_ERROR),
        'status' => 'active',
        'created_at' => $now,
        'updated_at' => $now,
    ]);
    DB::table('hades_workspace_bindings')->insert([
        'id' => $bindingId,
        'project_id' => $projectId,
        'hades_agent_id' => $agentId,
        'external_agent_id' => 'admin-snapshot-agent',
        'workspace_fingerprint' => hash('sha256', $bindingId),
        'display_path' => '/workspace/admin-snapshot',
        'status' => 'linked',
        'linked_at' => $now,
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    $snapshot = $this->actingAs($admin)
        ->getJson('/api/dashboard/admin/hades')
        ->assertOk()
        ->json();

    $projectName = DB::table('projects')->where('id', $projectId)->value('name');

    expect($snapshot['supported_capabilities'])->toBe($capabilities)
        ->and($created['token']['allowed_capabilities'])->toBe($capabilities)
        ->and(array_key_exists('project_name', $created['token']))->toBeTrue()
        ->and(array_key_exists('created_at', $created['token']))->toBeTrue()
        ->and($created['token']['project_name'])->toBeNull()
        ->and($created['token']['created_at'])->not->toBeNull()
        ->and($snapshot['bootstrapTokens'][0]['allowed_capabilities'])->toBe($capabilities)
        ->and($snapshot['bootstrapTokens'][0]['project_name'])->toBe($projectName)
        ->and($snapshot['bootstrapTokens'][0]['created_at'])->not->toBeNull()
        ->and($snapshot['workspaces'][0]['declared_capabilities'])->toBe($capabilities)
        ->and($snapshot['workspaces'][0]['effective_capabilities'])->toBe(['read_files', 'populate_project_wiki']);
});

it('queues an admin job only for the linked active agent with an effective capability', function () {
    $admin = hadesAdminDashboardUser('Admin');
    $fixture = hadesAdminJobFixture(['read_source_slice']);

    $this->actingAs($admin)
        ->postJson('/api/dashboard/admin/hades/jobs', [
            'project_id' => $fixture['project_id'],
            'workspace_binding_id' => $fixture['binding_id'],
            'capability' => 'read_source_slice',
            'payload' => ['path' => 'app/Invoice.php'],
        ])
        ->assertCreated()
        ->assertJsonPath('job.hades_agent_id', $fixture['agent_id'])
        ->assertJsonPath('job.capability', 'read_source_slice');

    expect(DB::table('hades_agent_jobs')->where('workspace_binding_id', $fixture['binding_id'])->count())->toBe(1);
});

it('rejects an admin job when the bound agent lacks the requested capability', function () {
    $admin = hadesAdminDashboardUser('Admin');
    $fixture = hadesAdminJobFixture(['read_files']);

    $this->actingAs($admin)
        ->postJson('/api/dashboard/admin/hades/jobs', [
            'project_id' => $fixture['project_id'],
            'workspace_binding_id' => $fixture['binding_id'],
            'capability' => 'read_source_slice',
            'payload' => ['path' => 'app/Invoice.php'],
        ])
        ->assertStatus(409)
        ->assertJsonPath('error.code', 'agent_capability_not_enabled')
        ->assertJsonPath('error.details.capability', 'read_source_slice');

    expect(DB::table('hades_agent_jobs')->where('workspace_binding_id', $fixture['binding_id'])->count())->toBe(0);
});

it('rejects an admin job that names an agent different from the binding agent', function () {
    $admin = hadesAdminDashboardUser('Admin');
    $fixture = hadesAdminJobFixture(['read_source_slice']);
    $otherAgentId = (string) Str::ulid();
    $now = now();

    DB::table('hades_agents')->insert([
        'id' => $otherAgentId,
        'project_id' => $fixture['project_id'],
        'external_agent_id' => 'other-admin-job-agent',
        'label' => 'Other admin job agent',
        'platform' => 'linux',
        'version' => 'test',
        'declared_capabilities' => json_encode(['read_source_slice'], JSON_THROW_ON_ERROR),
        'effective_capabilities' => json_encode(['read_source_slice'], JSON_THROW_ON_ERROR),
        'status' => 'active',
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    $this->actingAs($admin)
        ->postJson('/api/dashboard/admin/hades/jobs', [
            'project_id' => $fixture['project_id'],
            'workspace_binding_id' => $fixture['binding_id'],
            'hades_agent_id' => $otherAgentId,
            'capability' => 'read_source_slice',
            'payload' => ['path' => 'app/Invoice.php'],
        ])
        ->assertStatus(409)
        ->assertJsonPath('error.code', 'agent_binding_mismatch');

    expect(DB::table('hades_agent_jobs')->where('workspace_binding_id', $fixture['binding_id'])->count())->toBe(0);
});

function hadesAdminDashboardUser(string $role): User
{
    $user = User::factory()->create(['status' => 'active']);
    $roleId = DB::table('roles')->where('name', $role)->value('id');

    DB::table('role_user')->insert([
        'user_id' => $user->id,
        'role_id' => $roleId,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    return $user;
}

/**
 * @param  list<string>  $effectiveCapabilities
 * @return array{project_id: string, agent_id: string, binding_id: string}
 */
function hadesAdminJobFixture(array $effectiveCapabilities): array
{
    $projectId = (string) DB::table('projects')->where('status', 'active')->value('id');
    $agentId = (string) Str::ulid();
    $bindingId = (string) Str::ulid();
    $now = now();

    DB::table('hades_agents')->insert([
        'id' => $agentId,
        'project_id' => $projectId,
        'external_agent_id' => 'admin-job-agent-'.$agentId,
        'label' => 'Admin job agent',
        'platform' => 'linux',
        'version' => 'test',
        'declared_capabilities' => json_encode($effectiveCapabilities, JSON_THROW_ON_ERROR),
        'effective_capabilities' => json_encode($effectiveCapabilities, JSON_THROW_ON_ERROR),
        'status' => 'active',
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    DB::table('hades_workspace_bindings')->insert([
        'id' => $bindingId,
        'project_id' => $projectId,
        'hades_agent_id' => $agentId,
        'external_agent_id' => 'admin-job-agent-'.$agentId,
        'workspace_fingerprint' => hash('sha256', $bindingId),
        'display_path' => '/workspace/admin-job',
        'status' => 'linked',
        'linked_at' => $now,
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    return ['project_id' => $projectId, 'agent_id' => $agentId, 'binding_id' => $bindingId];
}
