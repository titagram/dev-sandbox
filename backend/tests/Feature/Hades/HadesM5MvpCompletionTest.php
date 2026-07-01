<?php

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(\Database\Seeders\DevBoardSeeder::class);
});

it('lets admins provision and revoke project scoped Hades bootstrap tokens with installer commands', function () {
    $admin = hadesM5DashboardUserWithRole('Admin');
    $project = hadesM5Project($admin);

    $response = $this->actingAs($admin)
        ->postJson('/api/dashboard/admin/hades/bootstrap-tokens', [
            'project_id' => $project['id'],
            'name' => 'Gabriele laptop bootstrap',
            'expires_in_days' => 30,
            'base_url' => 'https://backend.example.test',
            'project_name' => 'Hermes Dev',
        ])
        ->assertCreated()
        ->assertJsonPath('token.name', 'Gabriele laptop bootstrap')
        ->assertJsonPath('token.project_id', $project['id']);

    $plainToken = $response->json('plain_token');
    expect($plainToken)->toStartWith('hades_bootstrap_')
        ->and($response->json('install.posix'))->toContain('install.sh')
        ->and($response->json('install.posix'))->toContain('--backend-project-id '.$project['id'])
        ->and($response->json('install.posix'))->toContain('--backend-project-token')
        ->and($response->json('install.windows'))->toContain('install.ps1')
        ->and($response->json('install.windows'))->toContain('-BackendProjectId '.$project['id']);

    $storedHash = DB::table('hades_bootstrap_tokens')->where('id', $response->json('token.id'))->value('token_hash');
    expect($storedHash)->not->toContain($plainToken);

    $this->actingAs($admin)
        ->deleteJson('/api/dashboard/admin/hades/bootstrap-tokens/'.$response->json('token.id'))
        ->assertOk()
        ->assertJsonPath('revoked', true);

    expect(DB::table('hades_bootstrap_tokens')->where('id', $response->json('token.id'))->value('revoked_at'))->not->toBeNull();
});

it('lets admins queue Hades jobs and review memory proposals for linked workspaces', function () {
    $admin = hadesM5DashboardUserWithRole('Admin');
    $agent = hadesM5RegisteredAgent($admin);
    $binding = hadesM5WorkspaceBinding($agent);

    $job = $this->actingAs($admin)
        ->postJson('/api/dashboard/admin/hades/jobs', [
            'project_id' => $agent['project_id'],
            'workspace_binding_id' => $binding['workspace_binding_id'],
            'capability' => 'read_files',
            'policy' => 'manual_review',
            'requires_confirmation' => true,
            'payload' => ['paths' => ['README.md']],
        ])
        ->assertCreated()
        ->assertJsonPath('job.project_id', $agent['project_id'])
        ->assertJsonPath('job.workspace_binding_id', $binding['workspace_binding_id'])
        ->assertJsonPath('job.status', 'queued')
        ->json('job');

    expect((int) DB::table('hades_agent_jobs')->where('id', $job['id'])->value('requires_confirmation'))->toBe(1);

    $proposalId = hadesM5MemoryProposal($agent, $binding, ['status' => 'pending']);

    $this->actingAs($admin)
        ->postJson('/api/dashboard/admin/hades/memory-proposals/'.$proposalId.'/review', [
            'status' => 'refused',
            'reason_code' => 'duplicate',
            'reason_message' => 'Already present in shared memory.',
        ])
        ->assertOk()
        ->assertJsonPath('proposal.id', $proposalId)
        ->assertJsonPath('proposal.status', 'refused');

    expect(DB::table('hades_memory_proposals')->where('id', $proposalId)->value('reason_code'))->toBe('duplicate');
});

it('stores Hades git tree and symbols artifacts from authenticated agents', function () {
    $agent = hadesM5RegisteredAgent();
    $binding = hadesM5WorkspaceBinding($agent);

    $this->postJson('/api/hades/v1/artifacts', [
        'project_id' => $agent['project_id'],
        'agent_id' => $agent['external_agent_id'],
        'workspace_binding_id' => $binding['workspace_binding_id'],
        'schema' => 'hades.git_tree.v1',
        'artifact' => [
            'schema' => 'hades.git_tree.v1',
            'head_commit' => str_repeat('a', 40),
            'files' => [
                ['path' => 'README.md', 'sha256' => str_repeat('1', 64), 'bytes' => 120],
            ],
        ],
        'sha256' => str_repeat('2', 64),
        'truncated' => false,
    ], hadesM5Headers($agent['agent_token']))
        ->assertCreated()
        ->assertJsonPath('artifact.schema', 'hades.git_tree.v1')
        ->assertJsonPath('artifact.workspace_binding_id', $binding['workspace_binding_id']);

    $this->postJson('/api/hades/v1/artifacts', [
        'project_id' => $agent['project_id'],
        'workspace_binding_id' => $binding['workspace_binding_id'],
        'schema' => 'hades.symbols.v1',
        'artifact' => [
            'schema' => 'hades.symbols.v1',
            'language' => 'php',
            'symbols' => [
                ['name' => 'HadesTokenService', 'kind' => 'class', 'path' => 'app/Services/Hades/HadesTokenService.php'],
            ],
        ],
    ], hadesM5Headers($agent['agent_token']))
        ->assertCreated()
        ->assertJsonPath('artifact.schema', 'hades.symbols.v1');

    expect(DB::table('hades_agent_artifacts')->where('schema', 'hades.git_tree.v1')->count())->toBe(1)
        ->and(DB::table('hades_agent_artifacts')->where('schema', 'hades.symbols.v1')->count())->toBe(1);
});

it('stores explicit doctor reports and exposes a persistent Persephone inbox with polling and SSE fallback', function () {
    $agent = hadesM5RegisteredAgent();
    $binding = hadesM5WorkspaceBinding($agent);

    $this->postJson('/api/hades/v1/doctor/reports', [
        'project_id' => $agent['project_id'],
        'workspace_binding_id' => $binding['workspace_binding_id'],
        'status' => 'warning',
        'payload' => [
            'checks' => [['name' => 'backend', 'status' => 'warning']],
            'reported_by' => 'hades doctor --report',
        ],
    ], hadesM5Headers($agent['agent_token']))
        ->assertCreated()
        ->assertJsonPath('report.status', 'warning')
        ->assertJsonPath('report.workspace_binding_id', $binding['workspace_binding_id']);

    $this->postJson('/api/hades/v1/persephone/messages', [
        'project_id' => $agent['project_id'],
        'event_type' => 'proposal.reviewed',
        'payload' => ['message' => 'Memory proposal refused.'],
    ], hadesM5Headers($agent['agent_token']))
        ->assertCreated()
        ->assertJsonPath('event.event_type', 'proposal.reviewed');

    $this->getJson('/api/hades/v1/persephone/inbox?'.http_build_query([
        'project_id' => $agent['project_id'],
    ]), hadesM5Headers($agent['agent_token']))
        ->assertOk()
        ->assertJsonPath('counts.total', 1)
        ->assertJsonPath('counts.unread', 1)
        ->assertJsonPath('events.0.event_type', 'proposal.reviewed');

    $this->get('/api/hades/v1/persephone/events?'.http_build_query([
        'project_id' => $agent['project_id'],
    ]), hadesM5Headers($agent['agent_token']))
        ->assertOk()
        ->assertHeader('content-type', 'text/event-stream; charset=UTF-8')
        ->assertSee('event: proposal.reviewed');

    expect(DB::table('hades_doctor_reports')->where('project_id', $agent['project_id'])->count())->toBe(1)
        ->and(DB::table('hades_persephone_events')->where('project_id', $agent['project_id'])->count())->toBe(1);
});

function hadesM5Headers(?string $token = null): array
{
    return $token === null ? [] : ['Authorization' => 'Bearer '.$token];
}

function hadesM5DashboardUserWithRole(string $roleName): User
{
    $user = User::factory()->create([
        'status' => 'active',
        'password' => Hash::make('devboard'),
    ]);
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
 * @return array{id: string, user_id: int}
 */
function hadesM5Project(?User $owner = null): array
{
    $owner ??= User::factory()->create(['status' => 'active']);
    $id = (string) Str::ulid();
    $now = now();

    DB::table('projects')->insert([
        'id' => $id,
        'name' => 'Hades M5 Test Project',
        'slug' => 'hades-m5-test-'.Str::lower(Str::random(8)),
        'description' => null,
        'status' => 'active',
        'default_code_exposure_policy' => 'full_code_artifacts',
        'created_by_user_id' => $owner->id,
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    return ['id' => $id, 'user_id' => $owner->id];
}

/**
 * @return array{id: string, plain_token: string, project_id: string}
 */
function hadesM5BootstrapToken(?string $projectId = null): array
{
    $projectId = $projectId ?: hadesM5Project()['id'];
    $id = (string) Str::ulid();
    $secret = 'hades-m5-bootstrap-test-secret';
    $prefix = 'hades_bootstrap_'.$id;
    $now = now();

    DB::table('hades_bootstrap_tokens')->insert([
        'id' => $id,
        'project_id' => $projectId,
        'token_prefix' => $prefix,
        'token_hash' => hash('sha256', $secret),
        'name' => 'Hades M5 Bootstrap Token',
        'scopes' => json_encode(['hades.bootstrap'], JSON_THROW_ON_ERROR),
        'allowed_capabilities' => json_encode(['read_files', 'sync_git_tree', 'populate_backend_ast'], JSON_THROW_ON_ERROR),
        'expires_at' => now()->addMonth(),
        'revoked_at' => null,
        'last_used_at' => null,
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    return ['id' => $id, 'plain_token' => $prefix.'|'.$secret, 'project_id' => $projectId];
}

/**
 * @return array{project_id: string, external_agent_id: string, backend_agent_id: string, agent_token: string}
 */
function hadesM5RegisteredAgent(?User $owner = null): array
{
    $project = hadesM5Project($owner);
    $bootstrap = hadesM5BootstrapToken($project['id']);
    $externalAgentId = 'local-agent-'.Str::lower(Str::random(8));
    $test = test();

    $registered = $test->postJson('/api/hades/v1/agents/register', [
        'project_id' => $bootstrap['project_id'],
        'agent_id' => $externalAgentId,
        'label' => 'Hades M5 Agent',
        'platform' => 'linux-x64',
        'version' => '0.5.0',
        'capabilities' => ['read_files', 'sync_git_tree', 'populate_backend_ast'],
    ], hadesM5Headers($bootstrap['plain_token']))->assertOk();

    return [
        'project_id' => $bootstrap['project_id'],
        'external_agent_id' => $externalAgentId,
        'backend_agent_id' => $registered->json('backend_agent_id'),
        'agent_token' => $registered->json('agent_token'),
    ];
}

/**
 * @param array{project_id: string, external_agent_id: string, backend_agent_id: string, agent_token: string} $agent
 * @return array{workspace_binding_id: string}
 */
function hadesM5WorkspaceBinding(array $agent): array
{
    $test = test();
    $bound = $test->postJson('/api/hades/v1/workspaces/bind', [
        'project_id' => $agent['project_id'],
        'agent_id' => $agent['external_agent_id'],
        'workspace_fingerprint' => 'wf_hades_m5_'.Str::lower(Str::random(8)),
        'display_path' => '~/Code/hades-m5',
        'git_remote_display' => 'github.com/acme/hades-m5.git',
        'git_remote_hash' => hash('sha256', 'git@github.com:acme/hades-m5.git'),
        'head_commit' => str_repeat('5', 40),
    ], hadesM5Headers($agent['agent_token']))->assertOk();

    return ['workspace_binding_id' => $bound->json('workspace_binding_id')];
}

/**
 * @param array{project_id: string, external_agent_id: string, backend_agent_id: string, agent_token: string} $agent
 * @param array{workspace_binding_id: string} $binding
 * @param array<string, mixed> $overrides
 */
function hadesM5MemoryProposal(array $agent, array $binding, array $overrides = []): string
{
    $id = (string) Str::ulid();
    $now = now();

    DB::table('hades_memory_proposals')->insert(array_merge([
        'id' => $id,
        'project_id' => $agent['project_id'],
        'hades_agent_id' => $agent['backend_agent_id'],
        'workspace_binding_id' => $binding['workspace_binding_id'],
        'local_proposal_id' => 'm5-proposal-'.$id,
        'action' => 'update',
        'intent' => 'shared_memory_update',
        'summary' => 'Update shared memory from Hades.',
        'provenance' => json_encode(['source' => 'test'], JSON_THROW_ON_ERROR),
        'base_version' => null,
        'target_memory_entry_id' => null,
        'memory_entry_id' => null,
        'status' => 'pending',
        'reason_code' => null,
        'reason_message' => null,
        'decided_at' => null,
        'created_at' => $now,
        'updated_at' => $now,
    ], array_filter([
        'status' => $overrides['status'] ?? null,
    ], fn ($value) => $value !== null)));

    return $id;
}
