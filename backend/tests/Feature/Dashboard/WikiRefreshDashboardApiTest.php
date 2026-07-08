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

it('queues a project wiki refresh request as a Hades job', function () {
    $developer = wikiRefreshDashboardUserWithRole('Developer');
    $projectId = (string) DB::table('projects')->where('slug', 'demo-project')->value('id');
    $repositoryId = (string) DB::table('repositories')->where('project_id', $projectId)->value('id');
    $agent = wikiRefreshHadesAgent($projectId);
    $bindingId = wikiRefreshWorkspaceBinding($agent);

    $response = $this->actingAs($developer)
        ->postJson("/api/dashboard/projects/{$projectId}/wiki/refresh-requests", [
            'workspace_binding_id' => $bindingId,
            'repository_id' => $repositoryId,
            'reason' => 'Refresh wiki after local code inspection.',
        ])
        ->assertCreated()
        ->assertJsonPath('refresh_request.project_id', $projectId)
        ->assertJsonPath('refresh_request.workspace_binding_id', $bindingId)
        ->assertJsonPath('refresh_request.repository_id', $repositoryId)
        ->assertJsonPath('refresh_request.capability', 'populate_project_wiki')
        ->assertJsonPath('refresh_request.status', 'queued')
        ->assertJsonPath('refresh_request.payload.schema', 'devboard.wiki_refresh_request.v1')
        ->json('refresh_request');

    expect(DB::table('hades_agent_jobs')->where('id', $response['id'])->value('capability'))->toBe('populate_project_wiki')
        ->and(DB::table('hades_agent_jobs')->where('id', $response['id'])->value('requested_by_user_id'))->toBe($developer->id);

    $this->actingAs($developer)
        ->getJson("/api/dashboard/projects/{$projectId}/wiki/refresh-requests")
        ->assertOk()
        ->assertJsonPath('refresh_requests.0.id', $response['id'])
        ->assertJsonPath('refresh_requests.0.capability', 'populate_project_wiki');
});

it('queues a project scoped wiki refresh request without a repository id', function () {
    $developer = wikiRefreshDashboardUserWithRole('Developer');
    $projectId = (string) DB::table('projects')->where('slug', 'demo-project')->value('id');
    $agent = wikiRefreshHadesAgent($projectId);
    $bindingId = wikiRefreshWorkspaceBinding($agent);

    $this->actingAs($developer)
        ->postJson("/api/dashboard/projects/{$projectId}/wiki/refresh-requests", [
            'workspace_binding_id' => $bindingId,
            'reason' => 'Refresh the whole project wiki.',
        ])
        ->assertCreated()
        ->assertJsonPath('refresh_request.project_id', $projectId)
        ->assertJsonPath('refresh_request.workspace_binding_id', $bindingId)
        ->assertJsonPath('refresh_request.repository_id', null)
        ->assertJsonPath('refresh_request.payload.repository_id', null)
        ->assertJsonPath('refresh_request.status', 'queued');
});

it('rejects wiki refresh requests for bindings or repositories outside the project', function () {
    $developer = wikiRefreshDashboardUserWithRole('Developer');
    $projectId = (string) DB::table('projects')->where('slug', 'demo-project')->value('id');
    $other = wikiRefreshProject('Other Wiki Project', 'other-wiki-project');
    $otherAgent = wikiRefreshHadesAgent($other['project_id']);
    $otherBindingId = wikiRefreshWorkspaceBinding($otherAgent);

    $this->actingAs($developer)
        ->postJson("/api/dashboard/projects/{$projectId}/wiki/refresh-requests", [
            'workspace_binding_id' => $otherBindingId,
            'repository_id' => $other['repository_id'],
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['workspace_binding_id', 'repository_id']);
});

it('applies a Hades wiki refresh result through the wiki revision service', function () {
    $projectId = (string) DB::table('projects')->where('slug', 'demo-project')->value('id');
    $repositoryId = (string) DB::table('repositories')->where('project_id', $projectId)->value('id');
    $agent = wikiRefreshHadesAgent($projectId);
    $bindingId = wikiRefreshWorkspaceBinding($agent);
    $jobId = wikiRefreshJob($projectId, $repositoryId, $agent['backend_agent_id'], $bindingId);

    $this->postJson("/api/hades/v1/agent/jobs/{$jobId}/result", [
        'project_id' => $projectId,
        'agent_id' => $agent['external_agent_id'],
        'workspace_binding_id' => $bindingId,
        'status' => 'completed',
        'result' => [
            'schema' => 'devboard.wiki_refresh_result.v1',
            'pages' => [
                [
                    'slug' => 'hades-wiki-refresh',
                    'title' => 'Hades Wiki Refresh',
                    'page_type' => 'technical',
                    'source_status' => 'verified_from_code',
                    'content_markdown' => "# Hades Wiki Refresh\n\nGenerated from bounded local summaries.",
                    'evidence_refs' => [
                        ['kind' => 'file_ref', 'path' => 'README.md', 'hash' => hash('sha256', 'readme')],
                    ],
                ],
            ],
        ],
    ], wikiRefreshHeaders($agent['agent_token']))
        ->assertOk()
        ->assertJsonPath('job.status', 'completed')
        ->assertJsonPath('job.result.applied.pages_written', 1);

    $page = DB::table('wiki_pages')->where('project_id', $projectId)->where('slug', 'hades-wiki-refresh')->first();

    expect($page)->not->toBeNull()
        ->and(DB::table('wiki_revisions')->where('wiki_page_id', $page->id)->value('source_type'))->toBe('hades_wiki_refresh')
        ->and(DB::table('hades_agent_jobs')->where('id', $jobId)->value('result_applied_at'))->not->toBeNull();

    $memory = DB::table('project_memory_entries')
        ->where('project_id', $projectId)
        ->where('source', 'hades_wiki_refresh')
        ->where('kind', 'project_awareness')
        ->first();
    $payload = json_decode((string) $memory->payload, true, flags: JSON_THROW_ON_ERROR);

    expect($memory)->not->toBeNull()
        ->and($payload['schema'])->toBe('hades.project_awareness_memory.v1')
        ->and($payload['wiki_pages'][0]['slug'])->toBe('hades-wiki-refresh')
        ->and(DB::table('project_memory_links')
            ->where('memory_entry_id', $memory->id)
            ->where('target_type', 'wiki_page')
            ->where('target_id', $page->id)
            ->exists())->toBeTrue();
});

it('applies legacy local-agent wiki_revisions results and records written page titles', function () {
    $projectId = (string) DB::table('projects')->where('slug', 'demo-project')->value('id');
    $repositoryId = (string) DB::table('repositories')->where('project_id', $projectId)->value('id');
    $agent = wikiRefreshHadesAgent($projectId);
    $bindingId = wikiRefreshWorkspaceBinding($agent);
    $jobId = wikiRefreshJob($projectId, $repositoryId, $agent['backend_agent_id'], $bindingId);

    $this->postJson("/api/hades/v1/agent/jobs/{$jobId}/result", [
        'project_id' => $projectId,
        'agent_id' => $agent['external_agent_id'],
        'workspace_binding_id' => $bindingId,
        'status' => 'completed',
        'result' => [
            'schema' => 'devboard.wiki_refresh_result.v1',
            'wiki_revisions' => [
                [
                    'slug' => 'local-agent-overview',
                    'title' => 'Local Agent Overview',
                    'source' => 'verified_from_code',
                    'content_markdown' => "# Local Agent Overview\n\nGenerated by the local Hades CLI.",
                    'evidence' => [
                        ['kind' => 'file_ref', 'path' => 'AGENTS.md', 'hash' => hash('sha256', 'agents')],
                    ],
                ],
            ],
        ],
    ], wikiRefreshHeaders($agent['agent_token']))
        ->assertOk()
        ->assertJsonPath('job.status', 'completed')
        ->assertJsonPath('job.result.applied.pages_written', 1)
        ->assertJsonPath('job.result.applied.pages.0.slug', 'local-agent-overview')
        ->assertJsonPath('job.result.applied.pages.0.title', 'Local Agent Overview');

    $page = DB::table('wiki_pages')->where('project_id', $projectId)->where('slug', 'local-agent-overview')->first();
    expect($page)->not->toBeNull();
    $revision = DB::table('wiki_revisions')->where('wiki_page_id', $page->id)->first();
    expect($revision)->not->toBeNull();
    $evidence = json_decode((string) $revision->evidence_refs, true, flags: JSON_THROW_ON_ERROR);

    expect($page->source_status)->toBe('verified_from_code')
        ->and($revision->producer)->toBe('hades')
        ->and($revision->source_type)->toBe('hades_wiki_refresh')
        ->and($evidence[0]['path'])->toBe('AGENTS.md');
});

function wikiRefreshDashboardUserWithRole(string $roleName): User
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

/**
 * @return array{project_id: string, repository_id: string}
 */
function wikiRefreshProject(string $name, string $slug): array
{
    $user = User::factory()->create(['status' => 'active']);
    $projectId = (string) Str::ulid();
    $repositoryId = (string) Str::ulid();
    $now = now();

    DB::table('projects')->insert([
        'id' => $projectId,
        'name' => $name,
        'slug' => $slug,
        'description' => null,
        'status' => 'active',
        'default_code_exposure_policy' => 'full_code_artifacts',
        'created_by_user_id' => $user->id,
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    DB::table('repositories')->insert([
        'id' => $repositoryId,
        'project_id' => $projectId,
        'name' => $slug,
        'slug' => $slug,
        'default_branch' => 'main',
        'local_only' => true,
        'code_exposure_policy' => 'full_code_artifacts',
        'protected_paths' => json_encode([], JSON_THROW_ON_ERROR),
        'excluded_paths' => json_encode([], JSON_THROW_ON_ERROR),
        'stack_hints' => json_encode([], JSON_THROW_ON_ERROR),
        'graph_enabled' => true,
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    return ['project_id' => $projectId, 'repository_id' => $repositoryId];
}

/**
 * @return array{project_id: string, external_agent_id: string, backend_agent_id: string, agent_token: string}
 */
function wikiRefreshHadesAgent(string $projectId): array
{
    $bootstrapId = (string) Str::ulid();
    $secret = 'wiki-refresh-bootstrap-secret-'.$bootstrapId;
    $prefix = 'hades_bootstrap_'.$bootstrapId;
    $now = now();

    DB::table('hades_bootstrap_tokens')->insert([
        'id' => $bootstrapId,
        'project_id' => $projectId,
        'token_prefix' => $prefix,
        'token_hash' => hash('sha256', $secret),
        'name' => 'Wiki Refresh Bootstrap Token',
        'scopes' => json_encode(['hades.bootstrap'], JSON_THROW_ON_ERROR),
        'allowed_capabilities' => json_encode(['read_files', 'populate_project_wiki'], JSON_THROW_ON_ERROR),
        'expires_at' => now()->addMonth(),
        'revoked_at' => null,
        'last_used_at' => null,
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    $externalAgentId = 'wiki-refresh-agent-'.Str::lower(Str::random(8));
    $registered = test()->postJson('/api/hades/v1/agents/register', [
        'project_id' => $projectId,
        'agent_id' => $externalAgentId,
        'label' => 'Wiki Refresh Agent',
        'platform' => 'linux-x64',
        'version' => '0.6.0',
        'capabilities' => ['read_files', 'populate_project_wiki'],
    ], wikiRefreshHeaders($prefix.'|'.$secret))->assertOk();

    return [
        'project_id' => $projectId,
        'external_agent_id' => $externalAgentId,
        'backend_agent_id' => $registered->json('backend_agent_id'),
        'agent_token' => $registered->json('agent_token'),
    ];
}

/**
 * @param  array{project_id: string, external_agent_id: string, backend_agent_id: string, agent_token: string}  $agent
 */
function wikiRefreshWorkspaceBinding(array $agent): string
{
    $bound = test()->postJson('/api/hades/v1/workspaces/bind', [
        'project_id' => $agent['project_id'],
        'agent_id' => $agent['external_agent_id'],
        'workspace_fingerprint' => 'wf_wiki_refresh_'.Str::lower(Str::random(8)),
        'display_path' => '~/Code/wiki-refresh',
        'git_remote_display' => 'github.com/acme/wiki-refresh.git',
        'git_remote_hash' => hash('sha256', 'git@github.com:acme/wiki-refresh.git'),
        'head_commit' => str_repeat('a', 40),
    ], wikiRefreshHeaders($agent['agent_token']))->assertOk();

    return $bound->json('workspace_binding_id');
}

function wikiRefreshJob(string $projectId, string $repositoryId, string $backendAgentId, string $bindingId): string
{
    $jobId = (string) Str::ulid();
    $now = now();

    DB::table('hades_agent_jobs')->insert([
        'id' => $jobId,
        'project_id' => $projectId,
        'repository_id' => $repositoryId,
        'hades_agent_id' => $backendAgentId,
        'workspace_binding_id' => $bindingId,
        'requested_by_user_id' => null,
        'idempotency_key' => 'wiki-refresh-'.$jobId,
        'capability' => 'populate_project_wiki',
        'job_type' => 'wiki_refresh',
        'status' => 'queued',
        'policy' => 'manual_review',
        'priority' => 'normal',
        'payload' => json_encode(['schema' => 'devboard.wiki_refresh_request.v1'], JSON_THROW_ON_ERROR),
        'result' => null,
        'requires_confirmation' => false,
        'deadline_at' => null,
        'available_at' => null,
        'claimed_at' => null,
        'started_at' => null,
        'completed_at' => null,
        'failed_at' => null,
        'cancelled_at' => null,
        'result_applied_at' => null,
        'error_code' => null,
        'error_message' => null,
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    return $jobId;
}

function wikiRefreshHeaders(?string $token = null): array
{
    return $token === null ? [] : ['Authorization' => 'Bearer '.$token];
}
