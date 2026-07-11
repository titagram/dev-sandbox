<?php

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

it('advertises the complete v1 queue contract to authenticated agents', function () {
    $agent = persephoneQueueAgent();

    $this->getJson('/api/hades/v1/capabilities', persephoneQueueHeaders($agent['agent_token']))
        ->assertOk()
        ->assertJsonPath('persephone_agent_queue_v1', true)
        ->assertJsonPath('routes.persephone_inbox', '/api/hades/v1/persephone/inbox')
        ->assertJsonPath('routes.persephone_events', '/api/hades/v1/persephone/events')
        ->assertJsonPath('routes.persephone_messages', '/api/hades/v1/persephone/messages')
        ->assertJsonPath('capabilities.read_files', true);
});

it('rejects unauthenticated queue and capability requests', function () {
    $this->getJson('/api/hades/v1/capabilities')->assertUnauthorized();
    $this->postJson('/api/hades/v1/persephone/messages', [])->assertUnauthorized();
    $this->getJson('/api/hades/v1/persephone/inbox')->assertUnauthorized();
    $this->get('/api/hades/v1/persephone/events')->assertUnauthorized();
});

it('stores a valid normalized envelope and returns exactly the envelope plus the server id', function () {
    $sender = persephoneQueueAgent();
    $target = persephoneQueueAgent($sender['project_id'], 'target-agent');
    $binding = persephoneQueueBind($target);
    $envelope = persephoneQueueEnvelope($sender, $target, $binding, [
        'message_id' => '  message-normalized  ',
        'correlation_id' => '  correlation-normalized  ',
        'causation_id' => '  causation-normalized  ',
        'remote_task_id' => '  task-normalized  ',
        'remote_task_version' => '  7  ',
        'capability' => '  source_slice  ',
    ]);

    $response = $this->postJson(
        '/api/hades/v1/persephone/messages',
        $envelope,
        persephoneQueueHeaders($sender['agent_token']),
    )->assertCreated();

    $event = $response->json('event');
    persephoneQueueAssertEventShape($event);
    expect($event['schema'])->toBe('hades.persephone.agent-message.v1')
        ->and($event['message_id'])->toBe('message-normalized')
        ->and($event['correlation_id'])->toBe('correlation-normalized')
        ->and($event['causation_id'])->toBe('causation-normalized')
        ->and($event['remote_task_id'])->toBe('task-normalized')
        ->and($event['remote_task_version'])->toBe('7')
        ->and($event['capability'])->toBe('source_slice')
        ->and($event['id'])->toBeString();
});

it('rejects unknown envelope keys instead of accepting a legacy event shape', function () {
    $sender = persephoneQueueAgent();
    $target = persephoneQueueAgent($sender['project_id'], 'target-agent');
    $binding = persephoneQueueBind($target);
    $envelope = persephoneQueueEnvelope($sender, $target, $binding);
    $envelope['unknown'] = 'must-be-rejected';

    $this->postJson(
        '/api/hades/v1/persephone/messages',
        $envelope,
        persephoneQueueHeaders($sender['agent_token']),
    )->assertUnprocessable();
});

it('rejects invalid schema, message types, effects, expiry, and blank nullable strings', function () {
    $sender = persephoneQueueAgent();
    $target = persephoneQueueAgent($sender['project_id'], 'target-agent');
    $binding = persephoneQueueBind($target);
    $invalid = [
        'schema' => 'wrong.schema.v1',
        'message_type' => 'unknown_message',
        'effect' => 'unknown_effect',
        'expires_at' => now()->timestamp,
        'causation_id' => '   ',
    ];

    foreach ($invalid as $field => $value) {
        $envelope = persephoneQueueEnvelope($sender, $target, $binding, [
            'message_id' => 'invalid-'.$field.'-'.Str::lower(Str::random(8)),
            $field => $value,
        ]);

        $this->postJson(
            '/api/hades/v1/persephone/messages',
            $envelope,
            persephoneQueueHeaders($sender['agent_token']),
        )->assertUnprocessable();
    }
});

it('rejects non-empty and nullable identifier violations and enforces binding-required capabilities', function () {
    $sender = persephoneQueueAgent();
    $target = persephoneQueueAgent($sender['project_id'], 'target-agent');
    $binding = persephoneQueueBind($target);

    foreach (['message_id', 'correlation_id', 'project_id', 'sender_agent_id', 'target_agent_id', 'capability'] as $field) {
        $envelope = persephoneQueueEnvelope($sender, $target, $binding, [
            'message_id' => 'blank-'.$field.'-'.Str::lower(Str::random(8)),
            $field => '   ',
        ]);

        $this->postJson(
            '/api/hades/v1/persephone/messages',
            $envelope,
            persephoneQueueHeaders($sender['agent_token']),
        )->assertUnprocessable();
    }

    foreach (['source_slice', 'source_search', 'symbol_lookup', 'git_metadata', 'artifact_metadata'] as $capability) {
        $envelope = persephoneQueueEnvelope($sender, $target, null, [
            'message_id' => 'binding-'.$capability.'-'.Str::lower(Str::random(8)),
            'capability' => $capability,
            'target_workspace_binding_id' => null,
        ]);

        $this->postJson(
            '/api/hades/v1/persephone/messages',
            $envelope,
            persephoneQueueHeaders($sender['agent_token']),
        )->assertUnprocessable();
    }
});

it('enforces payload property and canonical UTF-8 byte limits', function () {
    $sender = persephoneQueueAgent();
    $target = persephoneQueueAgent($sender['project_id'], 'target-agent');
    $binding = persephoneQueueBind($target);

    $validProperties = [];
    for ($index = 0; $index < 128; $index++) {
        $validProperties['key-'.$index] = $index;
    }

    $this->postJson(
        '/api/hades/v1/persephone/messages',
        persephoneQueueEnvelope($sender, $target, $binding, [
            'message_id' => 'payload-properties-valid',
            'payload' => $validProperties,
        ]),
        persephoneQueueHeaders($sender['agent_token']),
    )->assertCreated();

    $tooManyProperties = $validProperties;
    $tooManyProperties['key-128'] = 128;
    $this->postJson(
        '/api/hades/v1/persephone/messages',
        persephoneQueueEnvelope($sender, $target, $binding, [
            'message_id' => 'payload-properties-invalid',
            'payload' => $tooManyProperties,
        ]),
        persephoneQueueHeaders($sender['agent_token']),
    )->assertUnprocessable();

    $this->postJson(
        '/api/hades/v1/persephone/messages',
        persephoneQueueEnvelope($sender, $target, $binding, [
            'message_id' => 'payload-bytes-valid',
            'payload' => persephoneQueuePayloadOfBytes(65536),
        ]),
        persephoneQueueHeaders($sender['agent_token']),
    )->assertCreated();

    $this->postJson(
        '/api/hades/v1/persephone/messages',
        persephoneQueueEnvelope($sender, $target, $binding, [
            'message_id' => 'payload-bytes-invalid',
            'payload' => persephoneQueuePayloadOfBytes(65537),
        ]),
        persephoneQueueHeaders($sender['agent_token']),
    )->assertUnprocessable();
});

it('rejects cross-project sender and project access without an existence oracle', function () {
    $sender = persephoneQueueAgent();
    $target = persephoneQueueAgent($sender['project_id'], 'target-agent');
    $binding = persephoneQueueBind($target);
    $foreign = persephoneQueueAgent();

    $targetErrors = [];
    foreach (['missing-target', $foreign['external_agent_id']] as $targetAgentId) {
        $response = $this->postJson(
            '/api/hades/v1/persephone/messages',
            persephoneQueueEnvelope($sender, $target, $binding, [
                'message_id' => 'target-not-found-'.Str::lower(Str::random(8)),
                'target_agent_id' => $targetAgentId,
                'target_workspace_binding_id' => null,
                'capability' => 'status_query',
            ]),
            persephoneQueueHeaders($sender['agent_token']),
        )->assertNotFound()->assertJsonPath('error.code', 'target_agent_not_found');

        $targetErrors[] = [$response->status(), $response->json('error.code')];
    }

    expect($targetErrors[0])->toBe($targetErrors[1]);

    $this->postJson(
        '/api/hades/v1/persephone/messages',
        persephoneQueueEnvelope($sender, $target, $binding, [
            'message_id' => 'project-mismatch',
            'project_id' => $foreign['project_id'],
        ]),
        persephoneQueueHeaders($sender['agent_token']),
    )->assertForbidden();

    $this->postJson(
        '/api/hades/v1/persephone/messages',
        persephoneQueueEnvelope($sender, $target, $binding, [
            'message_id' => 'sender-mismatch',
            'sender_agent_id' => $foreign['external_agent_id'],
        ]),
        persephoneQueueHeaders($sender['agent_token']),
    )->assertForbidden();

    $this->getJson('/api/hades/v1/persephone/inbox?'.http_build_query([
        'project_id' => $foreign['project_id'],
        'target_agent_id' => $foreign['external_agent_id'],
    ]), persephoneQueueHeaders($sender['agent_token']))->assertForbidden();

    $this->get('/api/hades/v1/persephone/events?'.http_build_query([
        'project_id' => $foreign['project_id'],
        'target_agent_id' => $foreign['external_agent_id'],
    ]), persephoneQueueHeaders($sender['agent_token']))->assertForbidden();
});

it('rejects unknown, foreign, inactive, or unlinked target agents and bindings', function () {
    $sender = persephoneQueueAgent();
    $target = persephoneQueueAgent($sender['project_id'], 'target-agent');
    $binding = persephoneQueueBind($target);
    $foreign = persephoneQueueAgent();
    $foreignBinding = persephoneQueueBind($foreign);

    $cases = [[
        'message_id' => 'foreign-binding',
        'target_agent_id' => $target['external_agent_id'],
        'target_workspace_binding_id' => $foreignBinding['workspace_binding_id'],
    ]];

    foreach ($cases as $case) {
        $this->postJson(
            '/api/hades/v1/persephone/messages',
            persephoneQueueEnvelope($sender, $target, $binding, $case),
            persephoneQueueHeaders($sender['agent_token']),
        )->assertNotFound();
    }

    DB::table('hades_agents')->where('id', $target['backend_agent_id'])->update([
        'status' => 'revoked',
        'updated_at' => now(),
    ]);

    $this->postJson(
        '/api/hades/v1/persephone/messages',
        persephoneQueueEnvelope($sender, $target, $binding, ['message_id' => 'inactive-target']),
        persephoneQueueHeaders($sender['agent_token']),
    )->assertForbidden();

    DB::table('hades_agents')->where('id', $target['backend_agent_id'])->update([
        'status' => 'active',
        'updated_at' => now(),
    ]);
    DB::table('hades_workspace_bindings')->where('id', $binding['workspace_binding_id'])->update([
        'status' => 'unlinked',
        'unlinked_at' => now(),
        'updated_at' => now(),
    ]);

    $this->postJson(
        '/api/hades/v1/persephone/messages',
        persephoneQueueEnvelope($sender, $target, $binding, ['message_id' => 'unlinked-binding']),
        persephoneQueueHeaders($sender['agent_token']),
    )->assertForbidden();
});

it('supports normalized idempotent replay and rejects conflicting message ids', function () {
    $sender = persephoneQueueAgent();
    $target = persephoneQueueAgent($sender['project_id'], 'target-agent');
    $binding = persephoneQueueBind($target);
    $envelope = persephoneQueueEnvelope($sender, $target, $binding, [
        'message_id' => 'idempotent-message',
        'payload' => ['z' => 'last', 'a' => 'first'],
    ]);

    $first = $this->postJson(
        '/api/hades/v1/persephone/messages',
        $envelope,
        persephoneQueueHeaders($sender['agent_token']),
    )->assertCreated();

    $reordered = [
        'payload' => ['a' => 'first', 'z' => 'last'],
        'expires_at' => $envelope['expires_at'],
        'capability' => $envelope['capability'],
        'effect' => $envelope['effect'],
        'message_type' => $envelope['message_type'],
        'target_workspace_binding_id' => $envelope['target_workspace_binding_id'],
        'target_agent_id' => $envelope['target_agent_id'],
        'sender_agent_id' => $envelope['sender_agent_id'],
        'project_id' => $envelope['project_id'],
        'correlation_id' => $envelope['correlation_id'],
        'remote_task_version' => $envelope['remote_task_version'],
        'remote_task_id' => $envelope['remote_task_id'],
        'causation_id' => $envelope['causation_id'],
        'schema' => $envelope['schema'],
        'message_id' => $envelope['message_id'],
    ];

    $replay = $this->postJson(
        '/api/hades/v1/persephone/messages',
        $reordered,
        persephoneQueueHeaders($sender['agent_token']),
    )->assertOk();

    expect($replay->json('event.id'))->toBe($first->json('event.id'));

    $conflict = $envelope;
    $conflict['payload'] = ['a' => 'changed', 'z' => 'last'];
    $this->postJson(
        '/api/hades/v1/persephone/messages',
        $conflict,
        persephoneQueueHeaders($sender['agent_token']),
    )->assertConflict();
});

it('polls only active target messages in oldest-first cursor pages without marking them read', function () {
    $sender = persephoneQueueAgent();
    $target = persephoneQueueAgent($sender['project_id'], 'target-agent');
    $bindingA = persephoneQueueBind($target, 'wf-queue-a');
    $bindingB = persephoneQueueBind($target, 'wf-queue-b');
    $created = [];

    foreach ([null, $bindingA['workspace_binding_id'], $bindingB['workspace_binding_id']] as $index => $bindingId) {
        $message = persephoneQueueEnvelope($sender, $target, $bindingId === null ? null : $target, [
            'message_id' => 'ordered-'.$index,
            'capability' => 'status_query',
            'target_workspace_binding_id' => $bindingId,
        ]);

        $created[] = $this->postJson(
            '/api/hades/v1/persephone/messages',
            $message,
            persephoneQueueHeaders($sender['agent_token']),
        )->assertCreated()->json('event');
    }

    $page = $this->getJson('/api/hades/v1/persephone/inbox?'.http_build_query([
        'project_id' => $target['project_id'],
        'target_agent_id' => $target['external_agent_id'],
        'limit' => 2,
    ]), persephoneQueueHeaders($target['agent_token']))->assertOk();

    expect(array_keys($page->json()))->toEqualCanonicalizing(['events', 'cursor'])
        ->and($page->json('events'))->toHaveCount(2)
        ->and($page->json('events.0.message_id'))->toBe('ordered-0')
        ->and($page->json('events.1.message_id'))->toBe('ordered-1')
        ->and($page->json('cursor'))->toBe($page->json('events.1.id'));
    persephoneQueueAssertEventShape($page->json('events.0'));

    $resumed = $this->getJson('/api/hades/v1/persephone/inbox?'.http_build_query([
        'project_id' => $target['project_id'],
        'target_agent_id' => $target['external_agent_id'],
        'cursor' => $page->json('cursor'),
    ]), persephoneQueueHeaders($target['agent_token']))->assertOk();

    expect($resumed->json('events.0.message_id'))->toBe('ordered-2')
        ->and($resumed->json('cursor'))->toBe($created[2]['id'])
        ->and(DB::table('hades_persephone_events')->count())->toBe(0);
});

it('filters an inbox by the authenticated target and workspace while retaining unbound messages', function () {
    $sender = persephoneQueueAgent();
    $target = persephoneQueueAgent($sender['project_id'], 'target-agent');
    $bindingA = persephoneQueueBind($target, 'wf-queue-a');
    $bindingB = persephoneQueueBind($target, 'wf-queue-b');

    foreach ([null, $bindingA['workspace_binding_id'], $bindingB['workspace_binding_id']] as $index => $bindingId) {
        $this->postJson(
            '/api/hades/v1/persephone/messages',
            persephoneQueueEnvelope($sender, $target, $target, [
                'message_id' => 'workspace-'.$index,
                'capability' => 'status_query',
                'target_workspace_binding_id' => $bindingId,
            ]),
            persephoneQueueHeaders($sender['agent_token']),
        )->assertCreated();
    }

    $filtered = $this->getJson('/api/hades/v1/persephone/inbox?'.http_build_query([
        'project_id' => $target['project_id'],
        'target_agent_id' => $target['external_agent_id'],
        'target_workspace_binding_id' => $bindingA['workspace_binding_id'],
    ]), persephoneQueueHeaders($target['agent_token']))->assertOk();

    expect($filtered->json('events'))->toHaveCount(2)
        ->and(collect($filtered->json('events'))->pluck('message_id')->all())
        ->toEqual(['workspace-0', 'workspace-1']);
});

it('excludes expired messages and rejects invalid or foreign cursors', function () {
    $sender = persephoneQueueAgent();
    $target = persephoneQueueAgent($sender['project_id'], 'target-agent');
    $binding = persephoneQueueBind($target);

    $this->postJson(
        '/api/hades/v1/persephone/messages',
        persephoneQueueEnvelope($sender, $target, $binding, ['message_id' => 'expires-before-poll']),
        persephoneQueueHeaders($sender['agent_token']),
    )->assertCreated();

    Carbon::setTestNow(now()->addHours(2));
    try {
        $this->getJson('/api/hades/v1/persephone/inbox?'.http_build_query([
            'project_id' => $target['project_id'],
            'target_agent_id' => $target['external_agent_id'],
        ]), persephoneQueueHeaders($target['agent_token']))
            ->assertOk()
            ->assertJsonPath('events', [])
            ->assertJsonPath('cursor', null);
    } finally {
        Carbon::setTestNow();
    }

    $this->getJson('/api/hades/v1/persephone/inbox?'.http_build_query([
        'project_id' => $target['project_id'],
        'target_agent_id' => $target['external_agent_id'],
        'cursor' => 'not-a-valid-cursor',
    ]), persephoneQueueHeaders($target['agent_token']))->assertUnprocessable();

    $foreign = persephoneQueueAgent();
    $this->getJson('/api/hades/v1/persephone/inbox?'.http_build_query([
        'project_id' => $target['project_id'],
        'target_agent_id' => $target['external_agent_id'],
        'cursor' => $foreign['backend_agent_id'],
    ]), persephoneQueueHeaders($target['agent_token']))->assertUnprocessable();
});

it('streams a bounded SSE page with matching ids and an explicit stop event', function () {
    $sender = persephoneQueueAgent();
    $target = persephoneQueueAgent($sender['project_id'], 'target-agent');
    $binding = persephoneQueueBind($target);

    $created = $this->postJson(
        '/api/hades/v1/persephone/messages',
        persephoneQueueEnvelope($sender, $target, $binding, ['message_id' => 'sse-message']),
        persephoneQueueHeaders($sender['agent_token']),
    )->assertCreated()->json('event');

    $response = $this->get('/api/hades/v1/persephone/events?'.http_build_query([
        'project_id' => $target['project_id'],
        'target_agent_id' => $target['external_agent_id'],
        'limit' => 1,
    ]), persephoneQueueHeaders($target['agent_token']))
        ->assertOk()
        ->assertHeader('content-type', 'text/event-stream; charset=UTF-8');

    $blocks = array_values(array_filter(preg_split('/\n\n/', trim($response->getContent()))));
    expect($blocks)->toHaveCount(2);

    $messageLines = preg_split('/\n/', $blocks[0]);
    expect($messageLines[0])->toBe('id: '.$created['id'])
        ->and($messageLines[1])->toBe('event: message');
    $data = json_decode(substr($messageLines[2], strlen('data: ')), true, 512, JSON_THROW_ON_ERROR);
    persephoneQueueAssertEventShape($data);
    expect($data['id'])->toBe($created['id']);

    expect(preg_split('/\n/', $blocks[1]))->toEqual([
        'event: stop',
        'data: '.json_encode(['reason' => 'bounded', 'cursor' => $created['id']], JSON_THROW_ON_ERROR),
    ]);
});

it('returns a bounded stop event for an empty SSE page', function () {
    $target = persephoneQueueAgent();

    $response = $this->get('/api/hades/v1/persephone/events?'.http_build_query([
        'project_id' => $target['project_id'],
        'target_agent_id' => $target['external_agent_id'],
    ]), persephoneQueueHeaders($target['agent_token']))
        ->assertOk()
        ->assertHeader('content-type', 'text/event-stream; charset=UTF-8');

    expect($response->getContent())->toBe(
        "event: stop\ndata: {\"reason\":\"bounded\",\"cursor\":null}\n\n",
    );
});

it('excludes expired messages from the SSE page without marking them read', function () {
    $sender = persephoneQueueAgent();
    $target = persephoneQueueAgent($sender['project_id'], 'sse-expiry-target');

    $this->postJson(
        '/api/hades/v1/persephone/messages',
        persephoneQueueEnvelope($sender, $target, null, [
            'message_id' => 'sse-expired',
            'capability' => 'status_query',
            'target_workspace_binding_id' => null,
        ]),
        persephoneQueueHeaders($sender['agent_token']),
    )->assertCreated();

    Carbon::setTestNow(now()->addHours(2));
    try {
        $response = $this->get('/api/hades/v1/persephone/events?'.http_build_query([
            'project_id' => $target['project_id'],
            'target_agent_id' => $target['external_agent_id'],
        ]), persephoneQueueHeaders($target['agent_token']))->assertOk();
    } finally {
        Carbon::setTestNow();
    }

    expect($response->getContent())->toBe(
        "event: stop\ndata: {\"reason\":\"bounded\",\"cursor\":null}\n\n",
    )
        ->and(DB::table('hades_persephone_agent_messages')->where('message_id', 'sse-expired')->value('envelope'))->not->toBeNull();
});

it('resumes SSE delivery after the last returned ULID', function () {
    $sender = persephoneQueueAgent();
    $target = persephoneQueueAgent($sender['project_id'], 'sse-resume-target');

    foreach (['sse-resume-first', 'sse-resume-second'] as $messageId) {
        $this->postJson(
            '/api/hades/v1/persephone/messages',
            persephoneQueueEnvelope($sender, $target, null, [
                'message_id' => $messageId,
                'capability' => 'status_query',
                'target_workspace_binding_id' => null,
            ]),
            persephoneQueueHeaders($sender['agent_token']),
        )->assertCreated();
    }

    $firstPage = $this->get('/api/hades/v1/persephone/events?'.http_build_query([
        'project_id' => $target['project_id'],
        'target_agent_id' => $target['external_agent_id'],
        'limit' => 1,
    ]), persephoneQueueHeaders($target['agent_token']))->assertOk();
    $firstBlocks = persephoneQueueSseBlocks($firstPage->getContent());
    $firstLines = preg_split('/\n/', $firstBlocks[0]);
    $firstEvent = json_decode(substr($firstLines[2], strlen('data: ')), true, 512, JSON_THROW_ON_ERROR);

    $secondPage = $this->get('/api/hades/v1/persephone/events?'.http_build_query([
        'project_id' => $target['project_id'],
        'target_agent_id' => $target['external_agent_id'],
        'cursor' => $firstEvent['id'],
    ]), persephoneQueueHeaders($target['agent_token']))->assertOk();
    $secondBlocks = persephoneQueueSseBlocks($secondPage->getContent());
    $secondLines = preg_split('/\n/', $secondBlocks[0]);
    $secondEvent = json_decode(substr($secondLines[2], strlen('data: ')), true, 512, JSON_THROW_ON_ERROR);
    $secondId = substr($secondLines[0], strlen('id: '));

    expect($firstEvent['message_id'])->toBe('sse-resume-first')
        ->and($secondEvent['message_id'])->toBe('sse-resume-second')
        ->and($secondEvent['id'])->toBe($secondId)
        ->and($secondBlocks[1])->toBe(
            "event: stop\ndata: ".json_encode(['reason' => 'bounded', 'cursor' => $secondEvent['id']], JSON_THROW_ON_ERROR),
        );
});

it('applies the workspace filter to bounded SSE delivery while retaining unbound messages', function () {
    $sender = persephoneQueueAgent();
    $target = persephoneQueueAgent($sender['project_id'], 'sse-workspace-target');
    $bindingA = persephoneQueueBind($target, 'sse-workspace-a');
    $bindingB = persephoneQueueBind($target, 'sse-workspace-b');

    foreach ([null, $bindingA, $bindingB] as $index => $binding) {
        $this->postJson(
            '/api/hades/v1/persephone/messages',
            persephoneQueueEnvelope($sender, $target, $binding, [
                'message_id' => 'sse-workspace-'.$index,
                'capability' => 'status_query',
            ]),
            persephoneQueueHeaders($sender['agent_token']),
        )->assertCreated();
    }

    $response = $this->get('/api/hades/v1/persephone/events?'.http_build_query([
        'project_id' => $target['project_id'],
        'target_agent_id' => $target['external_agent_id'],
        'target_workspace_binding_id' => $bindingA['workspace_binding_id'],
    ]), persephoneQueueHeaders($target['agent_token']))->assertOk();
    $blocks = persephoneQueueSseBlocks($response->getContent());

    $messageIds = [];
    foreach (array_slice($blocks, 0, -1) as $block) {
        $lines = preg_split('/\n/', $block);
        $messageIds[] = json_decode(substr($lines[2], strlen('data: ')), true, 512, JSON_THROW_ON_ERROR)['message_id'];
    }

    expect($messageIds)->toEqual(['sse-workspace-0', 'sse-workspace-1'])
        ->and($blocks)->toHaveCount(3)
        ->and($blocks[2])->toContain('"cursor":"');
});

it('rejects SSE requests for another target, project, or cursor before streaming', function () {
    $sender = persephoneQueueAgent();
    $target = persephoneQueueAgent($sender['project_id'], 'sse-auth-target');
    $otherTarget = persephoneQueueAgent($sender['project_id'], 'sse-other-target');
    $foreign = persephoneQueueAgent();

    $responses = [
        $this->get('/api/hades/v1/persephone/events?'.http_build_query([
            'project_id' => $target['project_id'],
            'target_agent_id' => $otherTarget['external_agent_id'],
        ]), persephoneQueueHeaders($target['agent_token'])),
        $this->get('/api/hades/v1/persephone/events?'.http_build_query([
            'project_id' => $foreign['project_id'],
            'target_agent_id' => $foreign['external_agent_id'],
        ]), persephoneQueueHeaders($target['agent_token'])),
        $this->get('/api/hades/v1/persephone/events?'.http_build_query([
            'project_id' => $target['project_id'],
            'target_agent_id' => $target['external_agent_id'],
            'cursor' => 'not-a-valid-cursor',
        ]), persephoneQueueHeaders($target['agent_token'])),
        $this->get('/api/hades/v1/persephone/events?'.http_build_query([
            'project_id' => $target['project_id'],
            'target_agent_id' => $target['external_agent_id'],
            'cursor' => (string) Str::ulid(),
        ]), persephoneQueueHeaders($target['agent_token'])),
    ];

    expect($responses[0]->status())->toBe(403)
        ->and($responses[1]->status())->toBe(403)
        ->and($responses[2]->status())->toBe(422)
        ->and($responses[3]->status())->toBe(422);

    foreach ($responses as $response) {
        expect($response->headers->get('content-type'))->not->toContain('text/event-stream');
    }
});

it('keeps an empty object payload as an object in the SSE wire envelope', function () {
    $sender = persephoneQueueAgent();
    $target = persephoneQueueAgent($sender['project_id'], 'sse-empty-object-target');

    $this->postJson(
        '/api/hades/v1/persephone/messages',
        persephoneQueueEnvelope($sender, $target, null, [
            'message_id' => 'sse-empty-object',
            'capability' => 'status_query',
            'target_workspace_binding_id' => null,
            'payload' => new stdClass,
        ]),
        persephoneQueueHeaders($sender['agent_token']),
    )->assertCreated();

    $response = $this->get('/api/hades/v1/persephone/events?'.http_build_query([
        'project_id' => $target['project_id'],
        'target_agent_id' => $target['external_agent_id'],
    ]), persephoneQueueHeaders($target['agent_token']))->assertOk();
    $blocks = persephoneQueueSseBlocks($response->getContent());
    $lines = preg_split('/\n/', $blocks[0]);
    $event = json_decode(substr($lines[2], strlen('data: ')), false, 512, JSON_THROW_ON_ERROR);

    expect($event->payload)->toBeInstanceOf(stdClass::class)
        ->and(property_exists($event, 'created_at'))->toBeFalse()
        ->and(property_exists($event, 'read_at'))->toBeFalse();
});

/**
 * @param  array<string, mixed>  $event
 */
function persephoneQueueAssertEventShape(array $event): void
{
    expect(array_keys($event))->toEqualCanonicalizing([
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
        'id',
    ]);
    expect($event['payload'])->toBeArray()
        ->and($event['id'])->toBeString()->not->toBe('');
}

/**
 * @return list<string>
 */
function persephoneQueueSseBlocks(string $body): array
{
    return array_values(array_filter(explode("\n\n", trim($body))));
}

/**
 * @return array<string, string>
 */
function persephoneQueueHeaders(string $token): array
{
    return ['Authorization' => 'Bearer '.$token];
}

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function persephoneQueueEnvelope(array $sender, array $target, ?array $binding, array $overrides = []): array
{
    $envelope = [
        'schema' => 'hades.persephone.agent-message.v1',
        'message_id' => 'message-'.Str::lower(Str::random(12)),
        'correlation_id' => 'correlation-'.Str::lower(Str::random(12)),
        'project_id' => $sender['project_id'],
        'sender_agent_id' => $sender['external_agent_id'],
        'target_agent_id' => $target['external_agent_id'],
        'target_workspace_binding_id' => $binding['workspace_binding_id'] ?? null,
        'message_type' => 'information_request',
        'effect' => 'information_read',
        'capability' => 'source_slice',
        'expires_at' => now()->addHour()->timestamp,
        'payload' => ['request' => ['path' => 'app/Example.php']],
        'causation_id' => null,
        'remote_task_id' => null,
        'remote_task_version' => null,
    ];

    return array_replace($envelope, $overrides);
}

/**
 * @return array{project_id: string, external_agent_id: string, backend_agent_id: string, agent_token: string}
 */
function persephoneQueueAgent(?string $projectId = null, ?string $externalAgentId = null): array
{
    $projectId ??= persephoneQueueProject();
    $externalAgentId ??= 'queue-agent-'.Str::lower(Str::random(8));
    $bootstrap = persephoneQueueBootstrap($projectId);

    $registered = test()->postJson('/api/hades/v1/agents/register', [
        'project_id' => $projectId,
        'agent_id' => $externalAgentId,
        'label' => 'Persephone queue test agent',
        'platform' => 'linux-x64',
        'version' => '0.1.0',
        'capabilities' => ['read_files', 'read_source_slice', 'sync_git_tree'],
    ], persephoneQueueHeaders($bootstrap['plain_token']))->assertOk();

    return [
        'project_id' => $projectId,
        'external_agent_id' => $externalAgentId,
        'backend_agent_id' => $registered->json('backend_agent_id'),
        'agent_token' => $registered->json('agent_token'),
    ];
}

/**
 * @return array{workspace_binding_id: string}
 */
function persephoneQueueBind(array $agent, ?string $fingerprint = null): array
{
    $response = test()->postJson('/api/hades/v1/workspaces/bind', [
        'project_id' => $agent['project_id'],
        'agent_id' => $agent['external_agent_id'],
        'workspace_fingerprint' => $fingerprint ?? 'wf-queue-'.Str::lower(Str::random(8)),
        'display_path' => '~/Code/persephone-queue',
        'git_remote_display' => 'github.com/example/persephone-queue.git',
        'git_remote_hash' => hash('sha256', 'git@example/persephone-queue'),
        'head_commit' => str_repeat('a', 40),
        'platform' => 'linux-x64',
    ], persephoneQueueHeaders($agent['agent_token']))->assertOk();

    return ['workspace_binding_id' => $response->json('workspace_binding_id')];
}

function persephoneQueueProject(): string
{
    $user = User::factory()->create(['status' => 'active']);
    $projectId = (string) Str::ulid();
    $now = now();

    DB::table('projects')->insert([
        'id' => $projectId,
        'name' => 'Persephone Queue Test Project',
        'slug' => 'persephone-queue-'.Str::lower(Str::random(8)),
        'description' => null,
        'status' => 'active',
        'default_code_exposure_policy' => 'full_code_artifacts',
        'created_by_user_id' => $user->id,
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    return $projectId;
}

/**
 * @return array{plain_token: string}
 */
function persephoneQueueBootstrap(string $projectId): array
{
    $id = (string) Str::ulid();
    $secret = 'persephone-queue-test-secret-'.Str::random(16);
    $prefix = 'hades_bootstrap_'.$id;
    $now = now();

    DB::table('hades_bootstrap_tokens')->insert([
        'id' => $id,
        'project_id' => $projectId,
        'token_prefix' => $prefix,
        'token_hash' => hash('sha256', $secret),
        'name' => 'Persephone queue test bootstrap',
        'scopes' => json_encode(['hades.bootstrap'], JSON_THROW_ON_ERROR),
        'allowed_capabilities' => json_encode(['read_files', 'read_source_slice', 'sync_git_tree'], JSON_THROW_ON_ERROR),
        'expires_at' => now()->addHour(),
        'revoked_at' => null,
        'last_used_at' => null,
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    return ['plain_token' => $prefix.'|'.$secret];
}

/**
 * @return array<string, string>
 */
function persephoneQueuePayloadOfBytes(int $bytes): array
{
    $overhead = strlen(json_encode(['data' => ''], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));

    return ['data' => str_repeat('a', $bytes - $overhead)];
}
