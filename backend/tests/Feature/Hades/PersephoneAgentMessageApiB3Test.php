<?php

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

it('rejects JSON array payloads and enforces bounded inbox query parameters', function () {
    $sender = b3PersephoneAgent();
    $target = b3PersephoneAgent($sender['project_id'], 'b3-target');
    $binding = b3PersephoneBinding($target);
    $envelope = b3PersephoneEnvelope($sender, $target, $binding);
    $envelope['payload'] = ['not', 'an', 'object'];

    $this->postJson(
        '/api/hades/v1/persephone/messages',
        $envelope,
        b3PersephoneHeaders($sender['agent_token']),
    )->assertUnprocessable();

    $this->getJson('/api/hades/v1/persephone/inbox?'.http_build_query([
        'project_id' => $target['project_id'],
        'target_agent_id' => $target['external_agent_id'],
        'limit' => 101,
    ]), b3PersephoneHeaders($target['agent_token']))->assertUnprocessable();

    $this->getJson('/api/hades/v1/persephone/inbox?'.http_build_query([
        'project_id' => $target['project_id'],
        'limit' => 1,
    ]), b3PersephoneHeaders($target['agent_token']))->assertUnprocessable();
});

it('rejects inbox workspace filters that are not active bindings of the authenticated target', function () {
    $sender = b3PersephoneAgent();
    $target = b3PersephoneAgent($sender['project_id'], 'b3-target');
    $foreign = b3PersephoneAgent();
    $foreignBinding = b3PersephoneBinding($foreign);

    $this->getJson('/api/hades/v1/persephone/inbox?'.http_build_query([
        'project_id' => $target['project_id'],
        'target_agent_id' => $target['external_agent_id'],
        'target_workspace_binding_id' => $foreignBinding['workspace_binding_id'],
    ]), b3PersephoneHeaders($target['agent_token']))->assertNotFound();
});

it('round-trips and replays an empty object payload without changing its wire shape', function () {
    $sender = b3PersephoneAgent();
    $target = b3PersephoneAgent($sender['project_id'], 'b3-target');
    $binding = b3PersephoneBinding($target);
    $envelope = b3PersephoneEnvelope($sender, $target, $binding);
    $envelope['message_id'] = 'b3-empty-object';
    $envelope['payload'] = new stdClass;

    $first = $this->postJson(
        '/api/hades/v1/persephone/messages',
        $envelope,
        b3PersephoneHeaders($sender['agent_token']),
    )->assertCreated();
    $firstWire = json_decode($first->getContent(), false, 512, JSON_THROW_ON_ERROR);

    $replay = $this->postJson(
        '/api/hades/v1/persephone/messages',
        $envelope,
        b3PersephoneHeaders($sender['agent_token']),
    )->assertOk();
    $replayWire = json_decode($replay->getContent(), false, 512, JSON_THROW_ON_ERROR);

    $poll = $this->getJson('/api/hades/v1/persephone/inbox?'.http_build_query([
        'project_id' => $target['project_id'],
        'target_agent_id' => $target['external_agent_id'],
    ]), b3PersephoneHeaders($target['agent_token']))->assertOk();
    $pollWire = json_decode($poll->getContent(), false, 512, JSON_THROW_ON_ERROR);

    expect($firstWire->event->payload)->toBeInstanceOf(stdClass::class)
        ->and($replayWire->event->payload)->toBeInstanceOf(stdClass::class)
        ->and($replayWire->event->id)->toBe($firstWire->event->id)
        ->and($pollWire->events[0]->payload)->toBeInstanceOf(stdClass::class);
});

/**
 * @return array<string, string>
 */
function b3PersephoneHeaders(string $token): array
{
    return ['Authorization' => 'Bearer '.$token];
}

/**
 * @return array{project_id: string, external_agent_id: string, backend_agent_id: string, agent_token: string}
 */
function b3PersephoneAgent(?string $projectId = null, ?string $externalAgentId = null): array
{
    $projectId ??= b3PersephoneProject();
    $externalAgentId ??= 'b3-agent-'.Str::lower(Str::random(8));
    $bootstrapId = (string) Str::ulid();
    $secret = 'b3-persephone-secret-'.Str::random(12);
    $prefix = 'hades_bootstrap_'.$bootstrapId;
    $now = now();

    DB::table('hades_bootstrap_tokens')->insert([
        'id' => $bootstrapId,
        'project_id' => $projectId,
        'token_prefix' => $prefix,
        'token_hash' => hash('sha256', $secret),
        'name' => 'B3 Persephone bootstrap',
        'scopes' => json_encode(['hades.bootstrap'], JSON_THROW_ON_ERROR),
        'allowed_capabilities' => json_encode(['read_files', 'read_source_slice'], JSON_THROW_ON_ERROR),
        'expires_at' => now()->addHour(),
        'revoked_at' => null,
        'last_used_at' => null,
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    $registered = test()->postJson('/api/hades/v1/agents/register', [
        'project_id' => $projectId,
        'agent_id' => $externalAgentId,
        'label' => 'B3 Persephone agent',
        'platform' => 'linux-x64',
        'version' => '0.1.0',
        'capabilities' => ['read_files', 'read_source_slice'],
    ], b3PersephoneHeaders($prefix.'|'.$secret))->assertOk();

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
function b3PersephoneBinding(array $agent): array
{
    $response = test()->postJson('/api/hades/v1/workspaces/bind', [
        'project_id' => $agent['project_id'],
        'agent_id' => $agent['external_agent_id'],
        'workspace_fingerprint' => 'b3-wf-'.Str::lower(Str::random(8)),
        'display_path' => '~/Code/b3-persephone',
        'git_remote_display' => 'github.com/example/b3-persephone.git',
        'git_remote_hash' => hash('sha256', 'b3-persephone'),
        'head_commit' => str_repeat('b', 40),
    ], b3PersephoneHeaders($agent['agent_token']))->assertOk();

    return ['workspace_binding_id' => $response->json('workspace_binding_id')];
}

/**
 * @return array<string, mixed>
 */
function b3PersephoneEnvelope(array $sender, array $target, array $binding): array
{
    return [
        'schema' => 'hades.persephone.agent-message.v1',
        'message_id' => 'b3-message-'.Str::lower(Str::random(8)),
        'correlation_id' => 'b3-correlation-'.Str::lower(Str::random(8)),
        'project_id' => $sender['project_id'],
        'sender_agent_id' => $sender['external_agent_id'],
        'target_agent_id' => $target['external_agent_id'],
        'target_workspace_binding_id' => $binding['workspace_binding_id'],
        'message_type' => 'information_request',
        'effect' => 'information_read',
        'capability' => 'source_slice',
        'expires_at' => now()->addHour()->timestamp,
        'payload' => ['request' => ['path' => 'app/Example.php']],
        'causation_id' => null,
        'remote_task_id' => null,
        'remote_task_version' => null,
    ];
}

function b3PersephoneProject(): string
{
    $user = User::factory()->create(['status' => 'active']);
    $projectId = (string) Str::ulid();
    $now = now();

    DB::table('projects')->insert([
        'id' => $projectId,
        'name' => 'B3 Persephone Test Project',
        'slug' => 'b3-persephone-'.Str::lower(Str::random(8)),
        'description' => null,
        'status' => 'active',
        'default_code_exposure_policy' => 'full_code_artifacts',
        'created_by_user_id' => $user->id,
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    return $projectId;
}
