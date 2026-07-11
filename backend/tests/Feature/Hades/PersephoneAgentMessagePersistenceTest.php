<?php

use App\Models\User;
use App\Services\Hades\PersephoneAgentMessageConflict;
use App\Services\Hades\PersephoneAgentMessageStore;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

it('creates dedicated Persephone message storage with envelope, payload, expiry, and cursor columns', function () {
    expect(Schema::hasTable('hades_persephone_agent_messages'))->toBeTrue();

    $columns = Schema::getColumnListing('hades_persephone_agent_messages');

    expect($columns)->toEqualCanonicalizing([
        'id',
        'project_id',
        'sender_agent_id',
        'target_agent_id',
        'target_workspace_binding_id',
        'schema',
        'message_id',
        'correlation_id',
        'causation_id',
        'remote_task_id',
        'remote_task_version',
        'message_type',
        'effect',
        'capability',
        'expires_at',
        'payload',
        'envelope',
        'envelope_hash',
        'created_at',
        'updated_at',
    ]);

    if (DB::getDriverName() === 'pgsql') {
        expect(Schema::getColumnType('hades_persephone_agent_messages', 'payload'))->toBe('jsonb')
            ->and(Schema::getColumnType('hades_persephone_agent_messages', 'envelope'))->toBe('jsonb');
    }
});

it('normalizes all envelope strings and recursively canonicalizes object keys', function () {
    $store = app(PersephoneAgentMessageStore::class);
    $projectId = persephonePersistenceProject();

    $normalized = $store->normalizeEnvelope([
        'schema' => '  hades.persephone.agent-message.v1  ',
        'message_id' => '  message-1  ',
        'correlation_id' => ' correlation-1 ',
        'project_id' => ' '.$projectId.' ',
        'sender_agent_id' => ' sender-1 ',
        'target_agent_id' => ' target-1 ',
        'target_workspace_binding_id' => null,
        'message_type' => ' information_request ',
        'effect' => ' information_read ',
        'capability' => ' source_slice ',
        'expires_at' => 1_900_000_000,
        'payload' => [
            'z' => ['b' => 2, 'a' => 1],
            'a' => 'first',
            'list' => [['z' => true, 'a' => false]],
        ],
        'causation_id' => ' causation-1 ',
        'remote_task_id' => null,
        'remote_task_version' => ' 3 ',
    ]);

    expect(array_keys($normalized))->toEqualCanonicalizing([
        'schema',
        'message_id',
        'correlation_id',
        'project_id',
        'sender_agent_id',
        'target_agent_id',
        'target_workspace_binding_id',
        'message_type',
        'effect',
        'capability',
        'expires_at',
        'payload',
        'causation_id',
        'remote_task_id',
        'remote_task_version',
    ])
        ->and($normalized['schema'])->toBe('hades.persephone.agent-message.v1')
        ->and($normalized['message_id'])->toBe('message-1')
        ->and($normalized['project_id'])->toBe($projectId)
        ->and($normalized['payload'])->toBe([
            'a' => 'first',
            'list' => [['a' => false, 'z' => true]],
            'z' => ['a' => 1, 'b' => 2],
        ]);
});

it('uses the normalized envelope for stable idempotency fingerprints', function () {
    $store = app(PersephoneAgentMessageStore::class);
    $projectId = persephonePersistenceProject();
    $first = persephonePersistenceEnvelope($projectId, [
        'payload' => ['z' => 'last', 'a' => 'first'],
    ]);
    $reordered = [
        'payload' => ['a' => 'first', 'z' => 'last'],
        'expires_at' => $first['expires_at'],
        'capability' => $first['capability'],
        'effect' => $first['effect'],
        'message_type' => $first['message_type'],
        'target_workspace_binding_id' => $first['target_workspace_binding_id'],
        'target_agent_id' => $first['target_agent_id'],
        'sender_agent_id' => $first['sender_agent_id'],
        'project_id' => $first['project_id'],
        'correlation_id' => $first['correlation_id'],
        'remote_task_version' => $first['remote_task_version'],
        'remote_task_id' => $first['remote_task_id'],
        'causation_id' => $first['causation_id'],
        'schema' => $first['schema'],
        'message_id' => $first['message_id'],
    ];

    expect($store->fingerprint($first))->toBe($store->fingerprint($reordered));
});

it('persists a ULID cursor and replays identical envelopes without duplicate rows', function () {
    $store = app(PersephoneAgentMessageStore::class);
    $projectId = persephonePersistenceProject();
    $envelope = persephonePersistenceEnvelope($projectId, ['message_id' => 'idempotent-message']);

    $first = $store->store($envelope);
    $replay = $store->store(array_replace($envelope, [
        'payload' => ['request' => ['path' => 'app/Example.php']],
    ]));

    expect($first['replayed'])->toBeFalse()
        ->and($replay['replayed'])->toBeTrue()
        ->and($replay['message']->id)->toBe($first['message']->id)
        ->and(Str::isUlid($first['message']->id))->toBeTrue()
        ->and(DB::table('hades_persephone_agent_messages')->where('project_id', $projectId)->count())->toBe(1);
});

it('rejects a conflicting normalized envelope under the same project message id', function () {
    $store = app(PersephoneAgentMessageStore::class);
    $projectId = persephonePersistenceProject();
    $envelope = persephonePersistenceEnvelope($projectId, ['message_id' => 'conflicting-message']);

    $store->store($envelope);

    expect(fn () => $store->store(array_replace($envelope, [
        'payload' => ['request' => ['path' => 'app/Changed.php']],
    ])))->toThrow(PersephoneAgentMessageConflict::class);
});

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function persephonePersistenceEnvelope(string $projectId, array $overrides = []): array
{
    return array_replace([
        'schema' => 'hades.persephone.agent-message.v1',
        'message_id' => 'message-'.Str::lower(Str::random(8)),
        'correlation_id' => 'correlation-'.Str::lower(Str::random(8)),
        'project_id' => $projectId,
        'sender_agent_id' => 'sender-agent',
        'target_agent_id' => 'target-agent',
        'target_workspace_binding_id' => null,
        'message_type' => 'information_request',
        'effect' => 'information_read',
        'capability' => 'status_query',
        'expires_at' => now()->addHour()->timestamp,
        'payload' => ['request' => ['path' => 'app/Example.php']],
        'causation_id' => null,
        'remote_task_id' => null,
        'remote_task_version' => null,
    ], $overrides);
}

function persephonePersistenceProject(): string
{
    $user = User::factory()->create(['status' => 'active']);
    $projectId = (string) Str::ulid();
    $now = now();

    DB::table('projects')->insert([
        'id' => $projectId,
        'name' => 'Persephone Persistence Test Project',
        'slug' => 'persephone-persistence-'.Str::lower(Str::random(8)),
        'description' => null,
        'status' => 'active',
        'default_code_exposure_policy' => 'full_code_artifacts',
        'created_by_user_id' => $user->id,
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    return $projectId;
}
