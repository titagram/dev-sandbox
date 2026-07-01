<?php

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

it('reports public Hades M1 health', function () {
    $this->getJson('/api/hades/v1/health')
        ->assertOk()
        ->assertJsonPath('protocol_version', 'v1')
        ->assertJsonPath('service', 'hades-backend')
        ->assertJsonPath('status', 'ok')
        ->assertJsonStructure(['server_time', 'routes']);
});

it('verifies a project scoped bootstrap token without creating an agent', function () {
    $token = createHadesM1BootstrapToken();

    $this->postJson('/api/hades/v1/token/verify', [
        'project_id' => $token['project_id'],
    ], hadesM1Headers($token['plain_token']))
        ->assertOk()
        ->assertJsonPath('protocol_version', 'v1')
        ->assertJsonPath('project_id', $token['project_id'])
        ->assertJsonPath('token.token_id', $token['id'])
        ->assertJsonPath('token.type', 'bootstrap')
        ->assertJsonPath('token.revoked', false)
        ->assertJsonPath('capabilities.registration', true);

    expect(DB::table('hades_bootstrap_tokens')->where('id', $token['id'])->value('last_used_at'))->not->toBeNull();
    expect(DB::table('hades_agents')->count())->toBe(0);
});

it('rejects invalid bootstrap token state for verification', function () {
    $revoked = createHadesM1BootstrapToken(['revoked_at' => now()]);

    $this->postJson('/api/hades/v1/token/verify', [
        'project_id' => $revoked['project_id'],
    ], hadesM1Headers($revoked['plain_token']))
        ->assertUnauthorized()
        ->assertJsonPath('error.code', 'token_revoked');

    $expired = createHadesM1BootstrapToken(['expires_at' => now()->subMinute()]);

    $this->postJson('/api/hades/v1/token/verify', [
        'project_id' => $expired['project_id'],
    ], hadesM1Headers($expired['plain_token']))
        ->assertUnauthorized()
        ->assertJsonPath('error.code', 'token_expired');

    $token = createHadesM1BootstrapToken();
    $otherProject = createHadesM1Project();

    $this->postJson('/api/hades/v1/token/verify', [
        'project_id' => $otherProject['id'],
    ], hadesM1Headers($token['plain_token']))
        ->assertUnauthorized()
        ->assertJsonPath('error.code', 'token_project_mismatch');
});

it('registers an external Hades agent and intersects capabilities with backend policy', function () {
    $token = createHadesM1BootstrapToken([
        'allowed_capabilities' => json_encode(['read_files', 'sync_git_tree'], JSON_THROW_ON_ERROR),
    ]);

    $response = $this->postJson('/api/hades/v1/agents/register', [
        'project_id' => $token['project_id'],
        'agent_id' => 'local-agent-1',
        'label' => 'Gabriele MacBook Hermes',
        'platform' => 'darwin-arm64',
        'version' => '0.1.0',
        'capabilities' => ['read_files', 'write_files', 'sync_git_tree'],
    ], hadesM1Headers($token['plain_token']))
        ->assertOk()
        ->assertJsonPath('protocol_version', 'v1')
        ->assertJsonPath('project_id', $token['project_id'])
        ->assertJsonPath('agent_id', 'local-agent-1')
        ->assertJsonPath('external_agent_id', 'local-agent-1')
        ->assertJsonPath('capabilities.read_files', true)
        ->assertJsonPath('capabilities.sync_git_tree', true)
        ->assertJsonPath('policy.memory', true)
        ->assertJsonPath('policy.jobs', true)
        ->assertJsonPath('policy.artifacts', false)
        ->assertJsonPath('policy.persephone', false);

    $agentToken = $response->json('agent_token');
    $backendAgentId = $response->json('backend_agent_id');

    expect($backendAgentId)->toBeString()->not->toBe('local-agent-1');
    expect($agentToken)->toBeString();
    expect(str_starts_with($agentToken, 'hades_agent_'))->toBeTrue();
    expect(array_key_exists('write_files', $response->json('capabilities')))->toBeFalse();

    expect(DB::table('hades_agents')->where('id', $backendAgentId)->value('external_agent_id'))->toBe('local-agent-1');
    expect(DB::table('hades_agent_tokens')->where('hades_agent_id', $backendAgentId)->count())->toBe(1);
});

it('updates an existing backend agent when the same external agent registers again', function () {
    $token = createHadesM1BootstrapToken();

    $first = $this->postJson('/api/hades/v1/agents/register', [
        'project_id' => $token['project_id'],
        'agent_id' => 'stable-local-agent',
        'label' => 'Original Label',
        'platform' => 'linux-x64',
        'version' => '0.1.0',
        'capabilities' => ['read_files'],
    ], hadesM1Headers($token['plain_token']))->assertOk();

    $second = $this->postJson('/api/hades/v1/agents/register', [
        'project_id' => $token['project_id'],
        'agent_id' => 'stable-local-agent',
        'label' => 'Renamed Agent',
        'platform' => 'linux-x64',
        'version' => '0.2.0',
        'capabilities' => ['read_files', 'populate_backend_ast'],
    ], hadesM1Headers($token['plain_token']))->assertOk();

    expect($second->json('backend_agent_id'))->toBe($first->json('backend_agent_id'));
    expect(DB::table('hades_agents')->where('project_id', $token['project_id'])->where('external_agent_id', 'stable-local-agent')->count())->toBe(1);
    expect(DB::table('hades_agents')->where('id', $first->json('backend_agent_id'))->value('label'))->toBe('Renamed Agent');
    expect(DB::table('hades_agents')->where('id', $first->json('backend_agent_id'))->value('version'))->toBe('0.2.0');
});

it('returns effective capabilities for an authenticated agent token', function () {
    $token = createHadesM1BootstrapToken();

    $registered = $this->postJson('/api/hades/v1/agents/register', [
        'project_id' => $token['project_id'],
        'agent_id' => 'capability-agent',
        'label' => 'Capability Agent',
        'platform' => 'linux-x64',
        'version' => '0.1.0',
        'capabilities' => ['read_files', 'populate_backend_ast'],
    ], hadesM1Headers($token['plain_token']))->assertOk();

    $this->getJson('/api/hades/v1/capabilities', hadesM1Headers($registered->json('agent_token')))
        ->assertOk()
        ->assertJsonPath('protocol_version', 'v1')
        ->assertJsonPath('project_id', $token['project_id'])
        ->assertJsonPath('backend_agent_id', $registered->json('backend_agent_id'))
        ->assertJsonPath('agent_id', 'capability-agent')
        ->assertJsonPath('external_agent_id', 'capability-agent')
        ->assertJsonPath('capabilities.read_files', true)
        ->assertJsonPath('capabilities.populate_backend_ast', true)
        ->assertJsonStructure(['limits', 'policy', 'routes', 'server_time']);
});

it('rejects bootstrap or revoked agent tokens on operational capability discovery', function () {
    $token = createHadesM1BootstrapToken();

    $this->getJson('/api/hades/v1/capabilities', hadesM1Headers($token['plain_token']))
        ->assertUnauthorized()
        ->assertJsonPath('error.code', 'wrong_token_type');

    $registered = $this->postJson('/api/hades/v1/agents/register', [
        'project_id' => $token['project_id'],
        'agent_id' => 'revoked-agent',
        'label' => 'Revoked Agent',
        'platform' => 'linux-x64',
        'version' => '0.1.0',
        'capabilities' => ['read_files'],
    ], hadesM1Headers($token['plain_token']))->assertOk();

    DB::table('hades_agent_tokens')->where('id', $registered->json('agent_token_id'))->update([
        'revoked_at' => now(),
        'updated_at' => now(),
    ]);

    $this->getJson('/api/hades/v1/capabilities', hadesM1Headers($registered->json('agent_token')))
        ->assertUnauthorized()
        ->assertJsonPath('error.code', 'token_revoked');
});

function hadesM1Headers(?string $token = null): array
{
    return $token === null ? [] : ['Authorization' => 'Bearer '.$token];
}

/**
 * @return array{id: string, user_id: int}
 */
function createHadesM1Project(): array
{
    $user = User::factory()->create(['status' => 'active']);
    $id = (string) Str::ulid();
    $now = now();

    DB::table('projects')->insert([
        'id' => $id,
        'name' => 'Hades M1 Test Project',
        'slug' => 'hades-m1-test-'.Str::lower(Str::random(8)),
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
 * @param  array<string, mixed>  $overrides
 * @return array{id: string, prefix: string, plain_token: string, secret: string, project_id: string}
 */
function createHadesM1BootstrapToken(array $overrides = []): array
{
    $projectId = $overrides['project_id'] ?? createHadesM1Project()['id'];
    unset($overrides['project_id']);

    $id = (string) Str::ulid();
    $secret = $overrides['secret'] ?? 'hades-bootstrap-test-secret';
    unset($overrides['secret']);

    $prefix = 'hades_bootstrap_'.$id;
    $now = now();

    DB::table('hades_bootstrap_tokens')->insert(array_merge([
        'id' => $id,
        'project_id' => $projectId,
        'token_prefix' => $prefix,
        'token_hash' => hash('sha256', $secret),
        'name' => 'Hades M1 Bootstrap Token',
        'scopes' => json_encode(['hades.bootstrap'], JSON_THROW_ON_ERROR),
        'allowed_capabilities' => json_encode(['read_files', 'sync_git_tree', 'populate_backend_ast'], JSON_THROW_ON_ERROR),
        'expires_at' => now()->addMonth(),
        'revoked_at' => null,
        'last_used_at' => null,
        'created_at' => $now,
        'updated_at' => $now,
    ], $overrides));

    return [
        'id' => $id,
        'prefix' => $prefix,
        'plain_token' => $prefix.'|'.$secret,
        'secret' => $secret,
        'project_id' => $projectId,
    ];
}
