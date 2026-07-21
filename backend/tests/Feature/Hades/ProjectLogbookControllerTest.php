<?php

use App\Models\User;
use App\Services\Hades\HadesTokenService;
use Database\Seeders\DevBoardSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(DevBoardSeeder::class);
});

it('lets an explicitly capable linked Hades agent append a project logbook entry as itself', function () {
    $agent = projectLogbookRegisteredAgent(['write_project_logbook']);
    $binding = projectLogbookBindWorkspace($agent);

    $this->getJson('/api/hades/v1/capabilities', projectLogbookHeaders($agent['agent_token']))
        ->assertOk()
        ->assertJsonPath('capabilities.write_project_logbook', true)
        ->assertJsonPath('routes.logbook_entries', '/api/hades/v1/logbook/entries');

    $response = $this->postJson('/api/hades/v1/logbook/entries', projectLogbookAgentEntry($agent, $binding), projectLogbookHeaders($agent['agent_token']))
        ->assertCreated()
        ->assertJsonPath('entry.project_id', $agent['project_id'])
        ->assertJsonPath('entry.actor.kind', 'agent')
        ->assertJsonPath('entry.actor.agent_id', $agent['backend_agent_id'])
        ->assertJsonPath('entry.actor.label', 'Project Logbook Agent')
        ->assertJsonPath('replayed', false);

    expect(DB::table('project_logbook_entries')->where('id', $response->json('entry.id'))->value('actor_agent_id'))
        ->toBe($agent['backend_agent_id']);
});

it('rejects an agent without the explicit project logbook capability', function () {
    $agent = projectLogbookRegisteredAgent([]);
    $binding = projectLogbookBindWorkspace($agent);

    $this->postJson('/api/hades/v1/logbook/entries', projectLogbookAgentEntry($agent, $binding), projectLogbookHeaders($agent['agent_token']))
        ->assertForbidden()
        ->assertJsonPath('code', 'logbook_capability_not_allowed');

    $auditQuery = DB::table('audit_logs')->where('action', 'permission.denied');

    expect($auditQuery->count())->toBe(1);

    $audit = $auditQuery->first();
    $payload = json_decode($audit->payload, true, flags: JSON_THROW_ON_ERROR);

    expect($audit->target_type)->toBe('authorization')
        ->and($audit->target_id)->toBe('write_project_logbook')
        ->and($audit->actor_type)->toBe('hades_agent')
        ->and($audit->actor_user_id)->toBeNull()
        ->and($audit->actor_device_id)->toBeNull()
        ->and($payload)->toBe([
            'ability' => 'write_project_logbook',
            'project_id' => $agent['project_id'],
            'workspace_binding_id' => $binding['workspace_binding_id'],
            'hades_agent_id' => $agent['backend_agent_id'],
        ]);
});

it('exposes dashboard logbook reads and allows dashboard notes without client-supplied actor or payload', function () {
    $user = projectLogbookDashboardUser('Developer');
    $project = projectLogbookProject($user);

    $this->actingAs($user)->postJson('/api/dashboard/projects/'.$project['id'].'/logbook/notes', [
        'event_type' => 'note', 'severity' => 'info', 'summary' => 'Documented the supported deployment path.',
        'narrative_markdown' => 'The team agreed to use the supported deployment path.', 'references' => [],
        'correlation_id' => null, 'idempotency_key' => 'dashboard-logbook-note-0001', 'supersedes_entry_id' => null,
        'actor' => ['kind' => 'agent'], 'payload' => ['untrusted' => true],
    ])->assertUnprocessable()->assertJsonValidationErrors(['actor', 'payload']);

    expect(DB::table('project_logbook_entries')->where('project_id', $project['id'])->count())->toBe(0);

    $created = $this->actingAs($user)->postJson('/api/dashboard/projects/'.$project['id'].'/logbook/notes', [
        'event_type' => 'decision', 'severity' => 'info', 'summary' => 'Keep project-logbook entries immutable.',
        'narrative_markdown' => null, 'references' => [], 'correlation_id' => null,
        'idempotency_key' => 'dashboard-logbook-note-0002', 'supersedes_entry_id' => null,
    ])->assertCreated()->assertJsonPath('entry.actor.kind', 'user')->assertJsonPath('entry.actor.user_id', $user->id)
        ->assertJsonPath('entry.event_type', 'decision');

    $entryId = $created->json('entry.id');

    $this->actingAs($user)->getJson('/api/dashboard/projects/'.$project['id'].'/logbook?types[]=decision&actor=user&severity=info')
        ->assertOk()->assertJsonPath('items.0.id', $entryId);
    $this->actingAs($user)->getJson('/api/dashboard/projects/'.$project['id'].'/logbook/'.$entryId)
        ->assertOk()->assertJsonPath('entry.id', $entryId);
});

it('keeps archived project logbooks readable and hides deleted project logbooks', function () {
    $user = projectLogbookDashboardUser('Developer');
    $project = projectLogbookProject($user);

    $created = $this->actingAs($user)->postJson('/api/dashboard/projects/'.$project['id'].'/logbook/notes', [
        'event_type' => 'decision', 'severity' => 'info', 'summary' => 'Retain the lifecycle boundary.',
        'narrative_markdown' => 'Archived remains readable; deleted is hidden.', 'references' => [],
        'correlation_id' => null, 'idempotency_key' => 'dashboard-logbook-lifecycle-0001', 'supersedes_entry_id' => null,
    ])->assertCreated();
    $entryId = $created->json('entry.id');

    DB::table('projects')->where('id', $project['id'])->update([
        'status' => 'archived', 'archived_at' => now(), 'updated_at' => now(),
    ]);

    $this->actingAs($user)->getJson('/api/dashboard/projects/'.$project['id'].'/logbook')
        ->assertOk()->assertJsonPath('items.0.id', $entryId);
    $this->actingAs($user)->getJson('/api/dashboard/projects/'.$project['id'].'/logbook/'.$entryId)
        ->assertOk()->assertJsonPath('entry.id', $entryId);

    DB::table('projects')->where('id', $project['id'])->update([
        'status' => 'deleted', 'deleted_at' => now(), 'updated_at' => now(),
    ]);

    $this->actingAs($user)->getJson('/api/dashboard/projects/'.$project['id'].'/logbook')
        ->assertNotFound();
    $this->actingAs($user)->getJson('/api/dashboard/projects/'.$project['id'].'/logbook/'.$entryId)
        ->assertNotFound();
});

function projectLogbookHeaders(?string $token = null): array
{
    return $token === null ? [] : ['Authorization' => 'Bearer '.$token];
}

/** @param list<string> $allowedCapabilities @return array{project_id:string,external_agent_id:string,backend_agent_id:string,agent_token:string} */
function projectLogbookRegisteredAgent(array $allowedCapabilities): array
{
    $project = projectLogbookProject();
    $bootstrap = app(HadesTokenService::class)->createBootstrapToken($project['id'], 'Project logbook test bootstrap', 30, $allowedCapabilities);
    $externalAgentId = 'project-logbook-agent-'.Str::lower(Str::random(8));
    $registered = test()->postJson('/api/hades/v1/agents/register', [
        'project_id' => $project['id'], 'agent_id' => $externalAgentId, 'label' => 'Project Logbook Agent',
        'platform' => 'linux-x64', 'version' => '0.5.0', 'capabilities' => ['write_project_logbook'],
    ], projectLogbookHeaders($bootstrap['plain_token']))->assertOk();

    return ['project_id' => $project['id'], 'external_agent_id' => $externalAgentId,
        'backend_agent_id' => $registered->json('backend_agent_id'), 'agent_token' => $registered->json('agent_token')];
}

/** @param array{project_id:string,external_agent_id:string,backend_agent_id:string,agent_token:string} $agent @return array{workspace_binding_id:string} */
function projectLogbookBindWorkspace(array $agent): array
{
    $bound = test()->postJson('/api/hades/v1/workspaces/bind', [
        'project_id' => $agent['project_id'], 'agent_id' => $agent['external_agent_id'],
        'workspace_fingerprint' => 'wf_project_logbook_'.Str::lower(Str::random(8)), 'display_path' => '~/Code/project-logbook',
        'git_remote_display' => 'github.com/acme/project-logbook.git', 'git_remote_hash' => hash('sha256', 'git@github.com:acme/project-logbook.git'),
        'head_commit' => str_repeat('7', 40),
    ], projectLogbookHeaders($agent['agent_token']))->assertOk();

    return ['workspace_binding_id' => $bound->json('workspace_binding_id')];
}

/** @param array{project_id:string,external_agent_id:string,backend_agent_id:string,agent_token:string} $agent @param array{workspace_binding_id:string} $binding @return array<string,mixed> */
function projectLogbookAgentEntry(array $agent, array $binding): array
{
    return [
        'project_id' => $agent['project_id'], 'workspace_binding_id' => $binding['workspace_binding_id'],
        'event_type' => 'change', 'severity' => 'info', 'summary' => 'Updated the Hades project logbook API.',
        'narrative_markdown' => null, 'references' => [], 'correlation_id' => 'project-logbook-test',
        'idempotency_key' => 'hades-project-logbook-entry-0001', 'payload' => ['source' => 'test'], 'supersedes_entry_id' => null,
    ];
}

/** @return array{id:string,user_id:int} */
function projectLogbookProject(?User $owner = null): array
{
    $owner ??= User::factory()->create(['status' => 'active']);
    $id = (string) Str::ulid();
    $now = now();
    DB::table('projects')->insert(['id' => $id, 'name' => 'Project Logbook Test Project', 'slug' => 'project-logbook-test-'.Str::lower(Str::random(8)),
        'description' => null, 'status' => 'active', 'default_code_exposure_policy' => 'full_code_artifacts', 'created_by_user_id' => $owner->id,
        'created_at' => $now, 'updated_at' => $now]);

    return ['id' => $id, 'user_id' => $owner->id];
}

function projectLogbookDashboardUser(string $roleName): User
{
    $user = User::factory()->create(['status' => 'active', 'password' => Hash::make('devboard')]);
    $roleId = DB::table('roles')->where('name', $roleName)->value('id');
    DB::table('role_user')->insert(['user_id' => $user->id, 'role_id' => $roleId, 'created_at' => now(), 'updated_at' => now()]);

    return $user;
}
