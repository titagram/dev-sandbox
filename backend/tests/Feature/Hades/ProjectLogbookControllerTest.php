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

it('preserves an empty payload object through Hades storage replay and dashboard serializers', function () {
    $agent = projectLogbookRegisteredAgent(['write_project_logbook']);
    $binding = projectLogbookBindWorkspace($agent);
    $command = [
        ...projectLogbookAgentEntry($agent, $binding),
        'idempotency_key' => 'hades-empty-object-payload-0001',
        'payload' => (object) [],
    ];
    $rawJson = json_encode($command, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    $server = [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_ACCEPT' => 'application/json',
        'HTTP_AUTHORIZATION' => 'Bearer '.$agent['agent_token'],
    ];

    $created = $this->call('POST', '/api/hades/v1/logbook/entries', server: $server, content: $rawJson)
        ->assertCreated()
        ->assertJsonPath('replayed', false);
    $entryId = $created->json('entry.id');
    $createdJson = json_decode($created->getContent(), false, 512, JSON_THROW_ON_ERROR);
    $storedJson = json_decode(
        (string) DB::table('project_logbook_entries')->where('id', $entryId)->value('payload'),
        false,
        512,
        JSON_THROW_ON_ERROR,
    );

    expect($createdJson->entry->payload)->toBeInstanceOf(stdClass::class)
        ->and(get_object_vars($createdJson->entry->payload))->toBe([])
        ->and($storedJson)->toBeInstanceOf(stdClass::class)
        ->and(get_object_vars($storedJson))->toBe([]);

    $replayed = $this->call('POST', '/api/hades/v1/logbook/entries', server: $server, content: $rawJson)
        ->assertOk()
        ->assertJsonPath('entry.id', $entryId)
        ->assertJsonPath('replayed', true);
    $replayedJson = json_decode($replayed->getContent(), false, 512, JSON_THROW_ON_ERROR);

    expect($replayedJson->entry->payload)->toBeInstanceOf(stdClass::class)
        ->and(DB::table('project_logbook_entries')->where('id', $entryId)->count())->toBe(1)
        ->and(DB::table('audit_logs')->where('action', 'project_logbook.appended')->where('target_id', $entryId)->count())->toBe(1);

    $dashboardUser = projectLogbookDashboardUser('Developer');
    $list = $this->actingAs($dashboardUser)->getJson('/api/dashboard/projects/'.$agent['project_id'].'/logbook')->assertOk();
    $detail = $this->actingAs($dashboardUser)->getJson('/api/dashboard/projects/'.$agent['project_id'].'/logbook/'.$entryId)->assertOk();
    $listJson = json_decode($list->getContent(), false, 512, JSON_THROW_ON_ERROR);
    $detailJson = json_decode($detail->getContent(), false, 512, JSON_THROW_ON_ERROR);

    expect($listJson->items[0]->payload)->toBeInstanceOf(stdClass::class)
        ->and($detailJson->entry->payload)->toBeInstanceOf(stdClass::class);
});

it('preserves nested empty JSON objects and arrays through storage and replay', function () {
    $agent = projectLogbookRegisteredAgent(['write_project_logbook']);
    $binding = projectLogbookBindWorkspace($agent);
    $command = [
        ...projectLogbookAgentEntry($agent, $binding),
        'idempotency_key' => 'hades-nested-json-containers-0001',
        'payload' => (object) [
            'metadata' => (object) [],
            'items' => [],
            'nested' => (object) [
                'empty_object' => (object) [],
                'empty_array' => [],
            ],
        ],
    ];
    $rawJson = json_encode($command, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    $server = [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_ACCEPT' => 'application/json',
        'HTTP_AUTHORIZATION' => 'Bearer '.$agent['agent_token'],
    ];

    $created = $this->call('POST', '/api/hades/v1/logbook/entries', server: $server, content: $rawJson)
        ->assertCreated();
    $entryId = $created->json('entry.id');
    $createdJson = json_decode($created->getContent(), false, 512, JSON_THROW_ON_ERROR);
    $storedJson = json_decode(
        (string) DB::table('project_logbook_entries')->where('id', $entryId)->value('payload'),
        false,
        512,
        JSON_THROW_ON_ERROR,
    );

    expect($createdJson->entry->payload->metadata)->toBeInstanceOf(stdClass::class)
        ->and($createdJson->entry->payload->items)->toBeArray()->toBe([])
        ->and($createdJson->entry->payload->nested)->toBeInstanceOf(stdClass::class)
        ->and($createdJson->entry->payload->nested->empty_object)->toBeInstanceOf(stdClass::class)
        ->and($createdJson->entry->payload->nested->empty_array)->toBeArray()->toBe([])
        ->and($storedJson->metadata)->toBeInstanceOf(stdClass::class)
        ->and($storedJson->items)->toBeArray()->toBe([])
        ->and($storedJson->nested->empty_object)->toBeInstanceOf(stdClass::class)
        ->and($storedJson->nested->empty_array)->toBeArray()->toBe([]);

    $replayed = $this->call('POST', '/api/hades/v1/logbook/entries', server: $server, content: $rawJson)
        ->assertOk()
        ->assertJsonPath('entry.id', $entryId)
        ->assertJsonPath('replayed', true);
    $replayedJson = json_decode($replayed->getContent(), false, 512, JSON_THROW_ON_ERROR);

    expect($replayedJson->entry->payload->metadata)->toBeInstanceOf(stdClass::class)
        ->and($replayedJson->entry->payload->items)->toBeArray()->toBe([])
        ->and($replayedJson->entry->payload->nested->empty_object)->toBeInstanceOf(stdClass::class)
        ->and($replayedJson->entry->payload->nested->empty_array)->toBeArray()->toBe([])
        ->and(DB::table('project_logbook_entries')->where('project_id', $agent['project_id'])->count())->toBe(1);
});

it('accepts finite fractional JSON numbers with canonical replay semantics', function () {
    $agent = projectLogbookRegisteredAgent(['write_project_logbook']);
    $binding = projectLogbookBindWorkspace($agent);
    $command = [
        ...projectLogbookAgentEntry($agent, $binding),
        'idempotency_key' => 'hades-finite-json-numbers-0001',
        'payload' => (object) ['ratio' => 1.25, 'delta' => -0.5],
    ];
    $server = [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_ACCEPT' => 'application/json',
        'HTTP_AUTHORIZATION' => 'Bearer '.$agent['agent_token'],
    ];

    $created = $this->call(
        'POST',
        '/api/hades/v1/logbook/entries',
        server: $server,
        content: json_encode($command, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
    )->assertCreated();
    $entryId = $created->json('entry.id');
    $storedJson = json_decode(
        (string) DB::table('project_logbook_entries')->where('id', $entryId)->value('payload'),
        false,
        512,
        JSON_THROW_ON_ERROR,
    );

    expect($created->json('entry.payload.ratio'))->toBe(1.25)
        ->and($created->json('entry.payload.delta'))->toBe(-0.5)
        ->and($storedJson->ratio)->toBe(1.25)
        ->and($storedJson->delta)->toBe(-0.5);

    $reordered = [...$command, 'payload' => (object) ['delta' => -0.5, 'ratio' => 1.25]];
    $this->call(
        'POST',
        '/api/hades/v1/logbook/entries',
        server: $server,
        content: json_encode($reordered, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
    )->assertOk()
        ->assertJsonPath('entry.id', $entryId)
        ->assertJsonPath('replayed', true);

    expect(DB::table('project_logbook_entries')->where('project_id', $agent['project_id'])->count())->toBe(1)
        ->and(DB::table('audit_logs')->where('action', 'project_logbook.appended')->count())->toBe(1);
});

it('rejects a JSON list payload at the Hades request boundary', function () {
    $agent = projectLogbookRegisteredAgent(['write_project_logbook']);
    $binding = projectLogbookBindWorkspace($agent);
    $rawJson = json_encode([
        ...projectLogbookAgentEntry($agent, $binding),
        'idempotency_key' => 'hades-list-payload-rejected-0001',
        'payload' => [],
    ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);

    $this->call('POST', '/api/hades/v1/logbook/entries', server: [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_ACCEPT' => 'application/json',
        'HTTP_AUTHORIZATION' => 'Bearer '.$agent['agent_token'],
    ], content: $rawJson)
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['payload'])
        ->assertJsonPath('errors.payload.0', 'The payload field must be a JSON object.');

    expect(DB::table('project_logbook_entries')->where('project_id', $agent['project_id'])->count())->toBe(0);
});

it('rejects unknown top-level fields before building a logbook command', function () {
    $agent = projectLogbookRegisteredAgent(['write_project_logbook']);
    $binding = projectLogbookBindWorkspace($agent);
    $rawJson = json_encode([
        ...projectLogbookAgentEntry($agent, $binding),
        'idempotency_key' => 'hades-unknown-field-rejected-0001',
        'payload' => (object) [],
        'typo' => 'must not be silently discarded',
    ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);

    $response = $this->call('POST', '/api/hades/v1/logbook/entries', server: [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_ACCEPT' => 'application/json',
        'HTTP_AUTHORIZATION' => 'Bearer '.$agent['agent_token'],
    ], content: $rawJson)
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['typo']);

    expect($response->json('message'))->toBe('The typo field is prohibited.')
        ->and($response->json('error'))->toBeNull()
        ->and(DB::table('project_logbook_entries')->where('project_id', $agent['project_id'])->count())->toBe(0)
        ->and(DB::table('audit_logs')->where('action', 'project_logbook.appended')->count())->toBe(0);
});

it('rejects an agent without the explicit project logbook capability', function () {
    $agent = projectLogbookRegisteredAgent([]);
    $binding = projectLogbookBindWorkspace($agent);

    $response = $this->postJson('/api/hades/v1/logbook/entries', projectLogbookAgentEntry($agent, $binding), projectLogbookHeaders($agent['agent_token']))
        ->assertForbidden();

    expect($response->json())->toBe([
        'error' => [
            'code' => 'logbook_capability_not_allowed',
            'message' => 'The write_project_logbook capability is not enabled for this Hades agent.',
        ],
    ]);

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

it('returns domain conflicts in the standard Hades error envelope', function () {
    $agent = projectLogbookRegisteredAgent(['write_project_logbook']);
    $binding = projectLogbookBindWorkspace($agent);
    $command = [
        ...projectLogbookAgentEntry($agent, $binding),
        'idempotency_key' => 'hades-domain-conflict-envelope-0001',
    ];

    $this->postJson('/api/hades/v1/logbook/entries', $command, projectLogbookHeaders($agent['agent_token']))
        ->assertCreated();
    $response = $this->postJson('/api/hades/v1/logbook/entries', [
        ...$command,
        'summary' => 'Changed content for the same idempotency key.',
    ], projectLogbookHeaders($agent['agent_token']))
        ->assertConflict();

    expect($response->json())->toBe([
        'error' => [
            'code' => 'logbook_idempotency_conflict',
            'message' => 'logbook_idempotency_conflict: The idempotency key was already used for different logbook content.',
        ],
    ])->and(DB::table('project_logbook_entries')->where('project_id', $agent['project_id'])->count())->toBe(1)
        ->and(DB::table('audit_logs')->where('action', 'project_logbook.appended')->count())->toBe(1);
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
