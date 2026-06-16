<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(\Database\Seeders\DevBoardSeeder::class);
});

it('creates a started run from a plugin payload', function () {
    $context = createRunApiContext();

    $response = $this->postJson('/api/plugin/v1/runs', runStartPayload($context), runApiHeaders($context));

    $response
        ->assertOk()
        ->assertJsonPath('status', 'started')
        ->assertJsonPath('heartbeat_interval_seconds', 30)
        ->assertJsonStructure(['run_id']);

    expect(DB::table('runs')->where('id', $response->json('run_id'))->value('status'))->toBe('started');
    expect(DB::table('run_events')->where('run_id', $response->json('run_id'))->value('event_type'))->toBe('run.started');
});

it('records heartbeat status and appends a heartbeat event', function () {
    $context = createRunApiContext();
    $runId = createStartedRun($context);

    $this->postJson("/api/plugin/v1/runs/{$runId}/heartbeat", [
        'protocol_version' => 'v1',
        'message' => 'still working',
    ], runApiHeaders($context))
        ->assertOk()
        ->assertJsonPath('status', 'heartbeat');

    expect(DB::table('runs')->where('id', $runId)->value('status'))->toBe('heartbeat');
    expect(DB::table('run_events')->where('run_id', $runId)->where('event_type', 'run.heartbeat')->exists())->toBeTrue();
});

it('appends run events without mutating previous timeline rows', function () {
    $context = createRunApiContext();
    $runId = createStartedRun($context);
    $firstEventId = DB::table('run_events')->where('run_id', $runId)->value('id');
    $firstMessage = DB::table('run_events')->where('id', $firstEventId)->value('message');

    $this->postJson("/api/plugin/v1/runs/{$runId}/events", [
        'protocol_version' => 'v1',
        'event_type' => 'artifact_uploaded',
        'severity' => 'info',
        'message' => 'artifact accepted',
        'payload' => ['artifact_id' => 'art_123'],
    ], runApiHeaders($context))
        ->assertOk()
        ->assertJsonPath('status', 'recorded');

    expect(DB::table('run_events')->where('run_id', $runId)->count())->toBe(2);
    expect(DB::table('run_events')->where('id', $firstEventId)->value('message'))->toBe($firstMessage);
});

it('finishes a run with finished status', function () {
    $context = createRunApiContext();
    $runId = createStartedRun($context);

    $this->postJson("/api/plugin/v1/runs/{$runId}/finish", [
        'protocol_version' => 'v1',
        'status' => 'finished',
        'summary' => 'Genesis completed.',
    ], runApiHeaders($context))
        ->assertOk()
        ->assertJsonPath('status', 'finished');

    expect(DB::table('runs')->where('id', $runId)->value('status'))->toBe('finished');
    expect(DB::table('runs')->where('id', $runId)->value('finished_at'))->not->toBeNull();
});

it('records a failed finish risk summary', function () {
    $context = createRunApiContext();
    $runId = createStartedRun($context);

    $this->postJson("/api/plugin/v1/runs/{$runId}/finish", [
        'protocol_version' => 'v1',
        'status' => 'failed',
        'summary' => 'Genesis failed.',
        'risk_report' => [
            'risk_level' => 'high',
            'summary' => 'Large migration change.',
            'triggers' => ['migrations_changed'],
        ],
    ], runApiHeaders($context))
        ->assertOk()
        ->assertJsonPath('status', 'failed');

    $eventPayload = DB::table('run_events')
        ->where('run_id', $runId)
        ->where('event_type', 'run.finished')
        ->value('payload');

    expect(json_decode($eventPayload, true)['risk_report']['summary'])->toBe('Large migration change.');
    expect(DB::table('runs')->where('id', $runId)->value('risk_level'))->toBe('high');
});

it('rejects artifact events after a run is finished', function () {
    $context = createRunApiContext();
    $runId = createStartedRun($context);

    $this->postJson("/api/plugin/v1/runs/{$runId}/finish", [
        'protocol_version' => 'v1',
        'status' => 'finished',
        'summary' => 'Done.',
    ], runApiHeaders($context))->assertOk();

    $this->postJson("/api/plugin/v1/runs/{$runId}/events", [
        'protocol_version' => 'v1',
        'event_type' => 'artifact_uploaded',
        'severity' => 'info',
        'message' => 'late artifact',
        'payload' => [],
    ], runApiHeaders($context))
        ->assertConflict()
        ->assertJsonPath('error.code', 'run_not_active');
});

/**
 * @return array<string, string>
 */
function createRunApiContext(): array
{
    $userId = DB::table('users')->where('email', 'admin@example.com')->value('id');
    $projectId = DB::table('projects')->where('slug', 'demo-project')->value('id');
    $repositoryId = DB::table('repositories')->where('slug', 'demo-repository')->value('id');
    $now = now();
    $deviceId = (string) Str::ulid();
    $tokenId = (string) Str::ulid();
    $workspaceId = (string) Str::ulid();
    $secret = 'run-api-secret';
    $prefix = 'devb_live_'.$tokenId;

    DB::table('devices')->insert([
        'id' => $deviceId,
        'user_id' => $userId,
        'name' => 'Run API Device',
        'fingerprint_hash' => 'sha256:run-api-device',
        'platform_os' => 'darwin',
        'platform_arch' => 'arm64',
        'plugin_version' => '0.1.0',
        'last_seen_at' => $now,
        'status' => 'active',
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    DB::table('api_tokens')->insert([
        'id' => $tokenId,
        'token_prefix' => $prefix,
        'token_hash' => hash('sha256', $secret),
        'user_id' => $userId,
        'device_id' => $deviceId,
        'name' => 'Run API Test Token',
        'scopes' => json_encode(['runs.write', 'repositories.read'], JSON_THROW_ON_ERROR),
        'expires_at' => now()->addMonth(),
        'revoked_at' => null,
        'last_used_at' => null,
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    DB::table('local_workspaces')->insert([
        'id' => $workspaceId,
        'repository_id' => $repositoryId,
        'device_id' => $deviceId,
        'local_root_hash' => 'sha256:run-api-workspace',
        'display_path' => '/tmp/run-api-workspace',
        'current_branch' => 'main',
        'last_head_sha' => 'abc123',
        'dirty_status' => 'clean',
        'last_snapshot_id' => null,
        'last_seen_at' => $now,
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    return [
        'token' => $prefix.'|'.$secret,
        'device_id' => $deviceId,
        'user_id' => (string) $userId,
        'project_id' => $projectId,
        'repository_id' => $repositoryId,
        'local_workspace_id' => $workspaceId,
    ];
}

/**
 * @param array<string, string> $context
 * @return array<string, mixed>
 */
function runStartPayload(array $context): array
{
    return [
        'protocol_version' => 'v1',
        'project_id' => $context['project_id'],
        'repository_id' => $context['repository_id'],
        'local_workspace_id' => $context['local_workspace_id'],
        'task_id' => null,
        'run_type' => 'genesis_import',
        'runtime_profile' => 'agent_plugin',
        'branch' => 'main',
        'base_branch' => 'main',
        'base_sha' => 'abc123',
        'head_sha' => 'abc123',
        'dirty_status' => 'clean',
    ];
}

/**
 * @param array<string, string> $context
 * @return array<string, string>
 */
function runApiHeaders(array $context): array
{
    return [
        'Authorization' => 'Bearer '.$context['token'],
        'X-DevBoard-Protocol' => 'v1',
        'X-DevBoard-Plugin-Version' => '0.1.0',
        'X-DevBoard-Device-Id' => $context['device_id'],
    ];
}

/**
 * @param array<string, string> $context
 */
function createStartedRun(array $context): string
{
    $response = test()->postJson('/api/plugin/v1/runs', runStartPayload($context), runApiHeaders($context));
    $response->assertOk();

    return $response->json('run_id');
}
