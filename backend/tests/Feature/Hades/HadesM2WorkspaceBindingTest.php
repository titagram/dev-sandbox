<?php

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

it('binds a local workspace idempotently for an authenticated Hades agent', function () {
    $agent = hadesM2RegisteredAgent();

    $payload = [
        'project_id' => $agent['project_id'],
        'agent_id' => $agent['external_agent_id'],
        'local_project_id' => 'local-project-1',
        'workspace_fingerprint' => 'wf_hades_m2_workspace',
        'display_path' => '~/Code/hephaistos',
        'git_remote_display' => 'github.com/acme/hephaistos.git',
        'git_remote_hash' => hash('sha256', 'git@github.com:acme/hephaistos.git'),
        'head_commit' => str_repeat('a', 40),
        'platform' => 'darwin-arm64',
    ];

    $first = $this->postJson('/api/hades/v1/workspaces/bind', $payload, hadesM2Headers($agent['agent_token']))
        ->assertOk()
        ->assertJsonPath('protocol_version', 'v1')
        ->assertJsonPath('project_id', $agent['project_id'])
        ->assertJsonPath('agent_id', $agent['external_agent_id'])
        ->assertJsonPath('backend_agent_id', $agent['backend_agent_id'])
        ->assertJsonPath('status', 'linked')
        ->assertJsonPath('workspace.workspace_fingerprint', 'wf_hades_m2_workspace')
        ->assertJsonPath('workspace.display_path', '~/Code/hephaistos');

    $bindingId = $first->json('workspace_binding_id');
    expect($bindingId)->toBeString()->not->toBe('wf_hades_m2_workspace');

    $this->postJson('/api/hades/v1/workspaces/bind', array_merge($payload, [
        'display_path' => '~/Code/hephaistos-renamed',
        'head_commit' => str_repeat('b', 40),
    ]), hadesM2Headers($agent['agent_token']))
        ->assertOk()
        ->assertJsonPath('workspace_binding_id', $bindingId)
        ->assertJsonPath('workspace.display_path', '~/Code/hephaistos-renamed')
        ->assertJsonPath('workspace.head_commit', str_repeat('b', 40));

    expect(DB::table('hades_workspace_bindings')->where('workspace_fingerprint', 'wf_hades_m2_workspace')->count())->toBe(1);
    expect(DB::table('hades_workspace_bindings')->where('id', $bindingId)->value('git_remote_hash'))->toBe($payload['git_remote_hash']);
});

it('rejects binding the same workspace fingerprint to a different project', function () {
    $firstAgent = hadesM2RegisteredAgent();
    $secondAgent = hadesM2RegisteredAgent();

    $this->postJson('/api/hades/v1/workspaces/bind', [
        'project_id' => $firstAgent['project_id'],
        'agent_id' => $firstAgent['external_agent_id'],
        'workspace_fingerprint' => 'wf_hades_m2_conflict',
        'display_path' => '~/Code/shared',
        'git_remote_display' => 'github.com/acme/shared.git',
        'git_remote_hash' => hash('sha256', 'git@github.com:acme/shared.git'),
        'head_commit' => str_repeat('c', 40),
    ], hadesM2Headers($firstAgent['agent_token']))->assertOk();

    $this->postJson('/api/hades/v1/workspaces/bind', [
        'project_id' => $secondAgent['project_id'],
        'agent_id' => $secondAgent['external_agent_id'],
        'workspace_fingerprint' => 'wf_hades_m2_conflict',
        'display_path' => '~/Code/shared',
        'git_remote_display' => 'github.com/acme/shared.git',
        'git_remote_hash' => hash('sha256', 'git@github.com:acme/shared.git'),
        'head_commit' => str_repeat('d', 40),
    ], hadesM2Headers($secondAgent['agent_token']))
        ->assertConflict()
        ->assertJsonPath('error.code', 'workspace_project_conflict')
        ->assertJsonPath('error.current_project_id', $firstAgent['project_id'])
        ->assertJsonPath('error.requested_project_id', $secondAgent['project_id']);
});

it('marks a workspace binding unlinked without deleting backend history', function () {
    $agent = hadesM2RegisteredAgent();

    $bound = $this->postJson('/api/hades/v1/workspaces/bind', [
        'project_id' => $agent['project_id'],
        'agent_id' => $agent['external_agent_id'],
        'workspace_fingerprint' => 'wf_hades_m2_unlink',
        'display_path' => '~/Code/unlink-me',
        'git_remote_display' => 'github.com/acme/unlink-me.git',
        'git_remote_hash' => hash('sha256', 'git@github.com:acme/unlink-me.git'),
        'head_commit' => str_repeat('e', 40),
    ], hadesM2Headers($agent['agent_token']))->assertOk();

    $bindingId = $bound->json('workspace_binding_id');
    $proposalId = (string) Str::ulid();
    DB::table('hades_memory_proposals')->insert([
        'id' => $proposalId,
        'project_id' => $agent['project_id'],
        'hades_agent_id' => $agent['backend_agent_id'],
        'workspace_binding_id' => $bindingId,
        'local_proposal_id' => 'preserve-on-unlink',
        'action' => 'create',
        'intent' => 'sync',
        'summary' => 'Accepted corpus fact must survive unlink.',
        'provenance' => json_encode(['source' => 'regression-test'], JSON_THROW_ON_ERROR),
        'base_version' => null,
        'target_memory_entry_id' => null,
        'memory_entry_id' => null,
        'status' => 'accepted',
        'reason_code' => null,
        'reason_message' => null,
        'decided_at' => now(),
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $this->postJson('/api/hades/v1/workspaces/'.$bindingId.'/unlink', [
        'project_id' => $agent['project_id'],
        'agent_id' => $agent['external_agent_id'],
    ], hadesM2Headers($agent['agent_token']))
        ->assertOk()
        ->assertJsonPath('workspace_binding_id', $bindingId)
        ->assertJsonPath('status', 'unlinked');

    expect(DB::table('hades_workspace_bindings')->where('id', $bindingId)->value('status'))->toBe('unlinked');
    expect(DB::table('hades_workspace_bindings')->where('id', $bindingId)->exists())->toBeTrue();
    expect(DB::table('hades_memory_proposals')->where('id', $proposalId)->value('status'))->toBe('accepted');
});

function hadesM2Headers(?string $token = null): array
{
    return $token === null ? [] : ['Authorization' => 'Bearer '.$token];
}

/**
 * @return array{id: string, user_id: int}
 */
function hadesM2Project(): array
{
    $user = User::factory()->create(['status' => 'active']);
    $id = (string) Str::ulid();
    $now = now();

    DB::table('projects')->insert([
        'id' => $id,
        'name' => 'Hades M2 Test Project',
        'slug' => 'hades-m2-test-'.Str::lower(Str::random(8)),
        'description' => null,
        'status' => 'active',
        'default_code_exposure_policy' => 'full_code_artifacts',
        'created_by_user_id' => $user->id,
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    return ['id' => $id, 'user_id' => $user->id];
}

/**
 * @return array{id: string, plain_token: string, project_id: string}
 */
function hadesM2BootstrapToken(?string $projectId = null): array
{
    $projectId = $projectId ?: hadesM2Project()['id'];
    $id = (string) Str::ulid();
    $secret = 'hades-m2-bootstrap-test-secret';
    $prefix = 'hades_bootstrap_'.$id;
    $now = now();

    DB::table('hades_bootstrap_tokens')->insert([
        'id' => $id,
        'project_id' => $projectId,
        'token_prefix' => $prefix,
        'token_hash' => hash('sha256', $secret),
        'name' => 'Hades M2 Bootstrap Token',
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
function hadesM2RegisteredAgent(): array
{
    $bootstrap = hadesM2BootstrapToken();
    $externalAgentId = 'local-agent-'.Str::lower(Str::random(8));
    $test = test();

    $registered = $test->postJson('/api/hades/v1/agents/register', [
        'project_id' => $bootstrap['project_id'],
        'agent_id' => $externalAgentId,
        'label' => 'Hades M2 Agent',
        'platform' => 'linux-x64',
        'version' => '0.2.0',
        'capabilities' => ['read_files', 'sync_git_tree', 'populate_backend_ast'],
    ], hadesM2Headers($bootstrap['plain_token']))->assertOk();

    return [
        'project_id' => $bootstrap['project_id'],
        'external_agent_id' => $externalAgentId,
        'backend_agent_id' => $registered->json('backend_agent_id'),
        'agent_token' => $registered->json('agent_token'),
    ];
}
