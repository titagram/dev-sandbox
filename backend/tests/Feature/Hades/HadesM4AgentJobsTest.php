<?php

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

it('pulls queued jobs for a linked workspace and requested capability', function () {
    $agent = hadesM4RegisteredAgent();
    $binding = hadesM4WorkspaceBinding($agent);
    $readJob = hadesM4CreateJob($agent, $binding, [
        'capability' => 'read_files',
        'payload' => ['paths' => ['README.md'], 'max_bytes' => 1024],
    ]);
    hadesM4CreateJob($agent, $binding, [
        'capability' => 'populate_backend_ast',
        'payload' => ['max_files' => 10],
    ]);

    $response = $this->getJson('/api/hades/v1/agent/jobs?'.http_build_query([
        'project_id' => $agent['project_id'],
        'agent_id' => $agent['external_agent_id'],
        'workspace_binding_id' => $binding['workspace_binding_id'],
        'capabilities' => ['read_files'],
    ]), hadesM4Headers($agent['agent_token']))
        ->assertOk()
        ->assertJsonPath('protocol_version', 'v1')
        ->assertJsonPath('project_id', $agent['project_id'])
        ->assertJsonPath('workspace_binding_id', $binding['workspace_binding_id'])
        ->assertJsonPath('jobs.0.job_id', $readJob)
        ->assertJsonPath('jobs.0.id', $readJob)
        ->assertJsonPath('jobs.0.capability', 'read_files')
        ->assertJsonPath('jobs.0.status', 'queued')
        ->assertJsonPath('jobs.0.policy', 'auto')
        ->assertJsonPath('jobs.0.payload.paths.0', 'README.md');

    expect($response->json('jobs'))->toHaveCount(1);
});

it('records job status transitions and bounded job results', function () {
    $agent = hadesM4RegisteredAgent();
    $binding = hadesM4WorkspaceBinding($agent);
    $jobId = hadesM4CreateJob($agent, $binding, [
        'capability' => 'read_files',
        'payload' => ['paths' => ['docs/README.md']],
    ]);

    $this->postJson('/api/hades/v1/agent/jobs/'.$jobId.'/status', [
        'project_id' => $agent['project_id'],
        'agent_id' => $agent['external_agent_id'],
        'workspace_binding_id' => $binding['workspace_binding_id'],
        'status' => 'received',
    ], hadesM4Headers($agent['agent_token']))
        ->assertOk()
        ->assertJsonPath('job.job_id', $jobId)
        ->assertJsonPath('job.status', 'received');

    $this->postJson('/api/hades/v1/agent/jobs/'.$jobId.'/status', [
        'project_id' => $agent['project_id'],
        'agent_id' => $agent['external_agent_id'],
        'workspace_binding_id' => $binding['workspace_binding_id'],
        'status' => 'started',
    ], hadesM4Headers($agent['agent_token']))
        ->assertOk()
        ->assertJsonPath('job.status', 'started');

    $this->postJson('/api/hades/v1/agent/jobs/'.$jobId.'/result', [
        'project_id' => $agent['project_id'],
        'agent_id' => $agent['external_agent_id'],
        'workspace_binding_id' => $binding['workspace_binding_id'],
        'status' => 'completed',
        'result' => [
            'summary' => 'Read 1 file; omitted 0.',
            'attachments' => [
                ['path' => 'docs/README.md', 'sha256' => str_repeat('1', 64), 'truncated' => false],
            ],
            'omitted' => [],
        ],
    ], hadesM4Headers($agent['agent_token']))
        ->assertOk()
        ->assertJsonPath('job.job_id', $jobId)
        ->assertJsonPath('job.status', 'completed')
        ->assertJsonPath('job.result.summary', 'Read 1 file; omitted 0.');

    expect(DB::table('hades_agent_jobs')->where('id', $jobId)->value('status'))->toBe('completed');
    expect(DB::table('hades_agent_jobs')->where('id', $jobId)->value('completed_at'))->not->toBeNull();
    expect(DB::table('hades_agent_job_events')->where('job_id', $jobId)->count())->toBe(3);
});

it('marks non terminal jobs unlinked when the workspace binding is unlinked', function () {
    $agent = hadesM4RegisteredAgent();
    $binding = hadesM4WorkspaceBinding($agent);
    $queuedJob = hadesM4CreateJob($agent, $binding, ['status' => 'queued']);
    $startedJob = hadesM4CreateJob($agent, $binding, ['status' => 'started']);
    $completedJob = hadesM4CreateJob($agent, $binding, ['status' => 'completed']);

    $this->postJson('/api/hades/v1/workspaces/'.$binding['workspace_binding_id'].'/unlink', [
        'project_id' => $agent['project_id'],
        'agent_id' => $agent['external_agent_id'],
    ], hadesM4Headers($agent['agent_token']))->assertOk();

    expect(DB::table('hades_agent_jobs')->where('id', $queuedJob)->value('status'))->toBe('unlinked');
    expect(DB::table('hades_agent_jobs')->where('id', $startedJob)->value('status'))->toBe('unlinked');
    expect(DB::table('hades_agent_jobs')->where('id', $completedJob)->value('status'))->toBe('completed');

    $this->getJson('/api/hades/v1/agent/jobs?'.http_build_query([
        'project_id' => $agent['project_id'],
        'agent_id' => $agent['external_agent_id'],
        'workspace_binding_id' => $binding['workspace_binding_id'],
        'capabilities' => ['read_files'],
    ]), hadesM4Headers($agent['agent_token']))
        ->assertStatus(409)
        ->assertJsonPath('error.code', 'workspace_binding_unlinked');
});

it('lets linked agents enqueue an idempotent project awareness wiki bootstrap job', function () {
    $agent = hadesM4RegisteredAgent();
    $binding = hadesM4WorkspaceBinding($agent);

    $response = $this->postJson('/api/hades/v1/project-awareness/bootstrap', [
        'project_id' => $agent['project_id'],
        'agent_id' => $agent['external_agent_id'],
        'workspace_binding_id' => $binding['workspace_binding_id'],
        'reason' => 'CLI bootstrap-awareness smoke',
    ], hadesM4Headers($agent['agent_token']))
        ->assertCreated()
        ->assertJsonPath('protocol_version', 'v1')
        ->assertJsonPath('project_id', $agent['project_id'])
        ->assertJsonPath('workspace_binding_id', $binding['workspace_binding_id'])
        ->assertJsonPath('job.capability', 'populate_project_wiki')
        ->assertJsonPath('job.status', 'queued')
        ->assertJsonPath('job.policy', 'auto')
        ->assertJsonPath('job.payload.schema', 'devboard.wiki_refresh_request.v1')
        ->assertJsonPath('created', true);

    $jobId = $response->json('job.id');

    $this->postJson('/api/hades/v1/project-awareness/bootstrap', [
        'project_id' => $agent['project_id'],
        'agent_id' => $agent['external_agent_id'],
        'workspace_binding_id' => $binding['workspace_binding_id'],
    ], hadesM4Headers($agent['agent_token']))
        ->assertOk()
        ->assertJsonPath('job.id', $jobId)
        ->assertJsonPath('created', false);

    expect(DB::table('hades_agent_jobs')
        ->where('project_id', $agent['project_id'])
        ->where('workspace_binding_id', $binding['workspace_binding_id'])
        ->where('capability', 'populate_project_wiki')
        ->count())->toBe(1);
});

function hadesM4Headers(?string $token = null): array
{
    return $token === null ? [] : ['Authorization' => 'Bearer '.$token];
}

/**
 * @return array{id: string, user_id: int}
 */
function hadesM4Project(): array
{
    $user = User::factory()->create(['status' => 'active']);
    $id = (string) Str::ulid();
    $now = now();

    DB::table('projects')->insert([
        'id' => $id,
        'name' => 'Hades M4 Test Project',
        'slug' => 'hades-m4-test-'.Str::lower(Str::random(8)),
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
function hadesM4BootstrapToken(?string $projectId = null): array
{
    $projectId = $projectId ?: hadesM4Project()['id'];
    $id = (string) Str::ulid();
    $secret = 'hades-m4-bootstrap-test-secret';
    $prefix = 'hades_bootstrap_'.$id;
    $now = now();

    DB::table('hades_bootstrap_tokens')->insert([
        'id' => $id,
        'project_id' => $projectId,
        'token_prefix' => $prefix,
        'token_hash' => hash('sha256', $secret),
        'name' => 'Hades M4 Bootstrap Token',
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
function hadesM4RegisteredAgent(): array
{
    $bootstrap = hadesM4BootstrapToken();
    $externalAgentId = 'local-agent-'.Str::lower(Str::random(8));
    $test = test();

    $registered = $test->postJson('/api/hades/v1/agents/register', [
        'project_id' => $bootstrap['project_id'],
        'agent_id' => $externalAgentId,
        'label' => 'Hades M4 Agent',
        'platform' => 'linux-x64',
        'version' => '0.4.0',
        'capabilities' => ['read_files', 'sync_git_tree', 'populate_backend_ast'],
    ], hadesM4Headers($bootstrap['plain_token']))->assertOk();

    return [
        'project_id' => $bootstrap['project_id'],
        'external_agent_id' => $externalAgentId,
        'backend_agent_id' => $registered->json('backend_agent_id'),
        'agent_token' => $registered->json('agent_token'),
    ];
}

/**
 * @param  array{project_id: string, external_agent_id: string, backend_agent_id: string, agent_token: string}  $agent
 * @return array{workspace_binding_id: string}
 */
function hadesM4WorkspaceBinding(array $agent): array
{
    $test = test();
    $bound = $test->postJson('/api/hades/v1/workspaces/bind', [
        'project_id' => $agent['project_id'],
        'agent_id' => $agent['external_agent_id'],
        'workspace_fingerprint' => 'wf_hades_m4_'.Str::lower(Str::random(8)),
        'display_path' => '~/Code/hades-m4',
        'git_remote_display' => 'github.com/acme/hades-m4.git',
        'git_remote_hash' => hash('sha256', 'git@github.com:acme/hades-m4.git'),
        'head_commit' => str_repeat('4', 40),
    ], hadesM4Headers($agent['agent_token']))->assertOk();

    return ['workspace_binding_id' => $bound->json('workspace_binding_id')];
}

/**
 * @param  array{project_id: string, external_agent_id: string, backend_agent_id: string, agent_token: string}  $agent
 * @param  array{workspace_binding_id: string}  $binding
 * @param  array<string, mixed>  $overrides
 */
function hadesM4CreateJob(array $agent, array $binding, array $overrides = []): string
{
    $id = (string) Str::ulid();
    $now = now();

    DB::table('hades_agent_jobs')->insert(array_merge([
        'id' => $id,
        'project_id' => $agent['project_id'],
        'hades_agent_id' => $agent['backend_agent_id'],
        'workspace_binding_id' => $binding['workspace_binding_id'],
        'idempotency_key' => 'job-key-'.$id,
        'capability' => 'read_files',
        'status' => 'queued',
        'policy' => 'auto',
        'priority' => 'normal',
        'payload' => json_encode(['paths' => ['README.md']], JSON_THROW_ON_ERROR),
        'result' => null,
        'requires_confirmation' => false,
        'deadline_at' => null,
        'available_at' => null,
        'claimed_at' => null,
        'started_at' => null,
        'completed_at' => ($overrides['status'] ?? null) === 'completed' ? $now : null,
        'failed_at' => null,
        'cancelled_at' => null,
        'error_code' => null,
        'error_message' => null,
        'created_at' => $now,
        'updated_at' => $now,
    ], array_filter([
        'capability' => $overrides['capability'] ?? null,
        'status' => $overrides['status'] ?? null,
        'policy' => $overrides['policy'] ?? null,
        'payload' => array_key_exists('payload', $overrides) ? json_encode($overrides['payload'], JSON_THROW_ON_ERROR) : null,
    ], fn ($value) => $value !== null)));

    return $id;
}
