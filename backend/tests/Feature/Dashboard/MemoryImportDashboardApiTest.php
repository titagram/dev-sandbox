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

it('lists project workspace bindings for memory import selection', function () {
    $admin = memoryImportDashboardUserWithRole('Admin');
    $projectId = (string) DB::table('projects')->where('slug', 'demo-project')->value('id');
    $agent = memoryImportHadesAgent($projectId);
    $bindingId = memoryImportWorkspaceBinding($agent, 'wf_import_visible');
    $other = memoryImportProject('Other Memory Import Project', 'other-memory-import');
    $otherAgent = memoryImportHadesAgent($other['project_id']);
    memoryImportWorkspaceBinding($otherAgent, 'wf_import_hidden');

    $this->actingAs($admin)
        ->getJson("/api/dashboard/projects/{$projectId}/workspace-bindings")
        ->assertOk()
        ->assertJsonPath('workspace_bindings.0.id', $bindingId)
        ->assertJsonCount(1, 'workspace_bindings');
});

it('creates a dashboard memory import batch as pending Hades proposals and dedupes by source hash', function () {
    $admin = memoryImportDashboardUserWithRole('Admin');
    $projectId = (string) DB::table('projects')->where('slug', 'demo-project')->value('id');
    $agent = memoryImportHadesAgent($projectId);
    $sourceBindingId = memoryImportWorkspaceBinding($agent, 'wf_import_source');
    $targetBindingId = memoryImportWorkspaceBinding($agent, 'wf_import_target');
    $sourceHash = hash('sha256', 'memory-from-other-folder');

    $payload = [
        'source_workspace_binding_id' => $sourceBindingId,
        'target_workspace_binding_id' => $targetBindingId,
        'reason' => 'Import useful notes from another local folder.',
        'entries' => [
            [
                'source_hash' => $sourceHash,
                'kind' => 'decision',
                'summary' => 'Reuse the existing bounded workspace memory.',
                'payload' => ['decision' => 'reuse-memory'],
                'provenance' => ['source' => 'workspace_transfer'],
            ],
        ],
    ];

    $created = $this->actingAs($admin)
        ->postJson("/api/dashboard/projects/{$projectId}/memory/imports", $payload)
        ->assertCreated()
        ->assertJsonPath('import_batch.project_id', $projectId)
        ->assertJsonPath('import_batch.status', 'completed')
        ->assertJsonPath('import_batch.items.0.status', 'proposal_created')
        ->json('import_batch');

    expect(DB::table('hades_memory_proposals')->where('workspace_binding_id', $targetBindingId)->where('status', 'pending')->count())->toBe(1);

    $this->actingAs($admin)
        ->postJson("/api/dashboard/projects/{$projectId}/memory/imports", $payload)
        ->assertCreated()
        ->assertJsonPath('import_batch.items.0.status', 'duplicate_skipped');

    expect(DB::table('hades_memory_proposals')->where('workspace_binding_id', $targetBindingId)->where('local_proposal_id', 'memory-import:'.$sourceHash)->count())->toBe(1);

    $this->actingAs($admin)
        ->getJson("/api/dashboard/projects/{$projectId}/memory/imports/{$created['id']}")
        ->assertOk()
        ->assertJsonPath('import_batch.id', $created['id']);
});

it('derives dashboard memory import entries from existing project memory when entries are omitted', function () {
    $admin = memoryImportDashboardUserWithRole('Admin');
    $projectId = (string) DB::table('projects')->where('slug', 'demo-project')->value('id');
    $agent = memoryImportHadesAgent($projectId);
    $sourceBindingId = memoryImportWorkspaceBinding($agent, 'wf_import_existing_source');
    $targetBindingId = memoryImportWorkspaceBinding($agent, 'wf_import_existing_target');
    $memoryId = (string) Str::ulid();
    $now = now();

    DB::table('project_memory_entries')->insert([
        'id' => $memoryId,
        'project_id' => $projectId,
        'repository_id' => null,
        'task_id' => null,
        'run_id' => null,
        'author_user_id' => $admin->id,
        'agent_key' => null,
        'source' => 'user_inserted',
        'kind' => 'decision',
        'completeness' => 'complete',
        'summary' => 'Carry over the customer access level decision.',
        'payload' => json_encode(['decision' => 'access-levels'], JSON_THROW_ON_ERROR),
        'occurred_at' => $now,
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    $this->actingAs($admin)
        ->postJson("/api/dashboard/projects/{$projectId}/memory/imports", [
            'source_workspace_binding_id' => $sourceBindingId,
            'target_workspace_binding_id' => $targetBindingId,
            'mode' => 'copy_as_proposals',
            'filters' => ['kinds' => ['decision'], 'limit' => 10],
            'dedupe_strategy' => 'summary_payload_hash',
            'conflict_policy' => 'proposal',
            'reason' => 'Seed memory from another linked workspace.',
        ])
        ->assertCreated()
        ->assertJsonPath('import_batch.status', 'completed')
        ->assertJsonPath('import_batch.filters.kinds.0', 'decision')
        ->assertJsonPath('import_batch.counts.entries_found', 1)
        ->assertJsonPath('import_batch.counts.proposals_created', 1)
        ->assertJsonPath('import_batch.items.0.status', 'proposal_created');
});

it('accepts Hades memory import bundles without crossing projects', function () {
    $projectId = (string) DB::table('projects')->where('slug', 'demo-project')->value('id');
    $agent = memoryImportHadesAgent($projectId);
    $bindingId = memoryImportWorkspaceBinding($agent, 'wf_import_hades_bundle');
    $sourceHash = hash('sha256', 'hades-bundle-entry');

    $this->postJson('/api/hades/v1/memory/import-bundles', [
        'project_id' => $projectId,
        'workspace_binding_id' => $bindingId,
        'source' => [
            'display_path' => '~/Code/old-folder',
            'workspace_fingerprint' => 'old-folder-fingerprint',
        ],
        'entries' => [
            [
                'source_hash' => $sourceHash,
                'kind' => 'handoff',
                'summary' => 'Carry over the local handoff memory.',
                'payload' => ['handoff' => 'bounded'],
                'provenance' => ['source' => 'hades_import_bundle'],
            ],
        ],
    ], memoryImportHeaders($agent['agent_token']))
        ->assertCreated()
        ->assertJsonPath('import_batch.project_id', $projectId)
        ->assertJsonPath('import_batch.target_workspace_binding_id', $bindingId)
        ->assertJsonPath('import_batch.items.0.status', 'proposal_created');

    $other = memoryImportProject('Forbidden Import Project', 'forbidden-import-project');

    $this->postJson('/api/hades/v1/memory/import-bundles', [
        'project_id' => $other['project_id'],
        'workspace_binding_id' => $bindingId,
        'entries' => [
            [
                'source_hash' => hash('sha256', 'cross-project'),
                'summary' => 'This must not cross projects.',
                'provenance' => [],
            ],
        ],
    ], memoryImportHeaders($agent['agent_token']))
        ->assertForbidden()
        ->assertJsonPath('error.code', 'project_mismatch');
});

function memoryImportDashboardUserWithRole(string $roleName): User
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
function memoryImportProject(string $name, string $slug): array
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
function memoryImportHadesAgent(string $projectId): array
{
    $bootstrapId = (string) Str::ulid();
    $secret = 'memory-import-bootstrap-secret-'.$bootstrapId;
    $prefix = 'hades_bootstrap_'.$bootstrapId;
    $now = now();

    DB::table('hades_bootstrap_tokens')->insert([
        'id' => $bootstrapId,
        'project_id' => $projectId,
        'token_prefix' => $prefix,
        'token_hash' => hash('sha256', $secret),
        'name' => 'Memory Import Bootstrap Token',
        'scopes' => json_encode(['hades.bootstrap'], JSON_THROW_ON_ERROR),
        'allowed_capabilities' => json_encode(['read_files'], JSON_THROW_ON_ERROR),
        'expires_at' => now()->addMonth(),
        'revoked_at' => null,
        'last_used_at' => null,
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    $externalAgentId = 'memory-import-agent-'.Str::lower(Str::random(8));
    $registered = test()->postJson('/api/hades/v1/agents/register', [
        'project_id' => $projectId,
        'agent_id' => $externalAgentId,
        'label' => 'Memory Import Agent',
        'platform' => 'linux-x64',
        'version' => '0.6.0',
        'capabilities' => ['read_files'],
    ], memoryImportHeaders($prefix.'|'.$secret))->assertOk();

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
function memoryImportWorkspaceBinding(array $agent, string $fingerprint): string
{
    $bound = test()->postJson('/api/hades/v1/workspaces/bind', [
        'project_id' => $agent['project_id'],
        'agent_id' => $agent['external_agent_id'],
        'workspace_fingerprint' => $fingerprint.'_'.Str::lower(Str::random(6)),
        'display_path' => '~/Code/'.$fingerprint,
        'git_remote_display' => 'github.com/acme/'.$fingerprint.'.git',
        'git_remote_hash' => hash('sha256', 'git@github.com:acme/'.$fingerprint.'.git'),
        'head_commit' => str_repeat('b', 40),
    ], memoryImportHeaders($agent['agent_token']))->assertOk();

    return $bound->json('workspace_binding_id');
}

function memoryImportHeaders(?string $token = null): array
{
    return $token === null ? [] : ['Authorization' => 'Bearer '.$token];
}
