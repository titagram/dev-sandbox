<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(\Database\Seeders\DevBoardSeeder::class);
});

it('returns project and matching repository memory without leaking other memory', function () {
    $token = pluginSharedMemoryToken();
    $projectId = pluginSharedMemoryProjectId();
    $repositoryId = pluginSharedMemoryRepositoryId();
    $otherRepositoryId = pluginSharedMemoryRepository($projectId, 'Other Repository', 'other-repository');
    $otherProjectId = pluginSharedMemoryProject('Other Project', 'other-memory-project');
    $otherProjectRepositoryId = pluginSharedMemoryRepository($otherProjectId, 'External Repository', 'external-repository');

    $projectMemoryId = pluginSharedMemoryEntry($projectId, null, 'Project level decision', ['scope' => 'project'], now()->subMinutes(3));
    $repositoryMemoryId = pluginSharedMemoryEntry($projectId, $repositoryId, 'Repository implementation', ['scope' => 'repository'], now()->subMinutes(2));
    pluginSharedMemoryEntry($projectId, $otherRepositoryId, 'Other repository risk', ['scope' => 'other-repository'], now()->subMinute());
    pluginSharedMemoryEntry($otherProjectId, $otherProjectRepositoryId, 'Other project incident', ['scope' => 'other-project'], now());

    $response = $this->getJson(
        "/api/plugin/v1/projects/{$projectId}/shared-memory-pack?repository_id={$repositoryId}",
        pluginSharedMemoryHeaders($token),
    )
        ->assertOk()
        ->assertJsonPath('protocol_version', 'v1')
        ->assertJsonPath('project_id', $projectId)
        ->assertJsonPath('repository_id', $repositoryId);

    $entries = collect($response->json('entries'));

    expect($entries->pluck('id')->all())
        ->toBe([$repositoryMemoryId, $projectMemoryId])
        ->and($entries->firstWhere('id', $repositoryMemoryId)['payload'])->toBe(['scope' => 'repository']);
});

it('lets the local agent list, claim, heartbeat, and complete work with memory', function () {
    $token = pluginSharedMemoryTokenWithDevice();
    $projectId = pluginSharedMemoryProjectId();
    $repositoryId = pluginSharedMemoryRepositoryId();
    $workspaceId = pluginSharedMemoryWorkspace($repositoryId, $token['device_id']);
    $workItemId = pluginSharedMemoryWorkItem($projectId, $repositoryId, [
        'priority' => 'urgent',
        'payload' => ['from' => 'test'],
    ]);

    $this->getJson('/api/plugin/v1/agent-work-items', pluginSharedMemoryHeaders($token))
        ->assertOk()
        ->assertJsonPath('protocol_version', 'v1')
        ->assertJsonPath('items.0.id', $workItemId)
        ->assertJsonPath('items.0.payload.from', 'test');

    $claim = $this->postJson("/api/plugin/v1/agent-work-items/{$workItemId}/claim", [
        'protocol_version' => 'v1',
        'local_workspace_id' => $workspaceId,
    ], pluginSharedMemoryHeaders($token))
        ->assertOk()
        ->assertJsonPath('protocol_version', 'v1')
        ->assertJsonPath('item.id', $workItemId)
        ->assertJsonPath('item.status', 'claimed')
        ->assertJsonStructure(['lease_token']);

    $leaseToken = $claim->json('lease_token');

    $this->postJson("/api/plugin/v1/agent-work-items/{$workItemId}/heartbeat", [
        'protocol_version' => 'v1',
        'lease_token' => $leaseToken,
    ], pluginSharedMemoryHeaders($token))
        ->assertOk()
        ->assertJsonPath('protocol_version', 'v1')
        ->assertJsonPath('item.status', 'running');

    $this->postJson("/api/plugin/v1/agent-work-items/{$workItemId}/complete", [
        'protocol_version' => 'v1',
        'lease_token' => $leaseToken,
        'memory_entry' => [
            'kind' => 'implementation',
            'summary' => 'Implemented local agent requested changes.',
            'payload' => pluginSharedMemoryCompletionPayload(),
        ],
    ], pluginSharedMemoryHeaders($token))
        ->assertOk()
        ->assertJsonPath('protocol_version', 'v1')
        ->assertJsonPath('item.status', 'completed')
        ->assertJsonPath('memory_entry.source', 'local_agent');

    $item = DB::table('agent_work_items')->where('id', $workItemId)->first();

    expect($item->status)->toBe('completed')
        ->and($item->result_memory_entry_id)->not->toBeNull()
        ->and(DB::table('project_memory_entries')->where('id', $item->result_memory_entry_id)->value('source'))->toBe('local_agent')
        ->and(DB::table('agent_work_item_leases')->where('agent_work_item_id', $workItemId)->whereNull('released_at')->exists())->toBeFalse();
});

it('renews the active lease on heartbeat so completion works after the original expiry', function () {
    $start = now()->startOfSecond();
    $this->travelTo($start);

    $token = pluginSharedMemoryTokenWithDevice();
    $projectId = pluginSharedMemoryProjectId();
    $repositoryId = pluginSharedMemoryRepositoryId();
    $workspaceId = pluginSharedMemoryWorkspace($repositoryId, $token['device_id']);
    $workItemId = pluginSharedMemoryWorkItem($projectId, $repositoryId);
    $leaseToken = pluginSharedMemoryClaim($this, $token, $workItemId, $workspaceId);

    $this->travelTo($start->copy()->addMinutes(20));

    $this->postJson("/api/plugin/v1/agent-work-items/{$workItemId}/heartbeat", [
        'protocol_version' => 'v1',
        'lease_token' => $leaseToken,
    ], pluginSharedMemoryHeaders($token))
        ->assertOk()
        ->assertJsonPath('item.status', 'running');

    $leaseExpiry = DB::table('agent_work_item_leases')
        ->where('agent_work_item_id', $workItemId)
        ->whereNull('released_at')
        ->value('expires_at');

    expect($leaseExpiry)->not->toBeNull()
        ->and(\Illuminate\Support\Carbon::parse($leaseExpiry)->equalTo($start->copy()->addMinutes(50)))->toBeTrue();

    $this->travelTo($start->copy()->addMinutes(35));

    $this->postJson("/api/plugin/v1/agent-work-items/{$workItemId}/complete", [
        'protocol_version' => 'v1',
        'lease_token' => $leaseToken,
        'memory_entry' => [
            'kind' => 'implementation',
            'summary' => 'Completed after the original lease expiry.',
            'payload' => pluginSharedMemoryCompletionPayload(),
        ],
    ], pluginSharedMemoryHeaders($token))
        ->assertOk()
        ->assertJsonPath('item.status', 'completed');

    $this->travelBack();
});

it('marks required-memory completion incomplete when no memory entry is supplied', function () {
    $token = pluginSharedMemoryTokenWithDevice();
    $projectId = pluginSharedMemoryProjectId();
    $repositoryId = pluginSharedMemoryRepositoryId();
    $workspaceId = pluginSharedMemoryWorkspace($repositoryId, $token['device_id']);
    $workItemId = pluginSharedMemoryWorkItem($projectId, $repositoryId, ['requires_memory_entry' => true]);
    $leaseToken = pluginSharedMemoryClaim($this, $token, $workItemId, $workspaceId);

    $this->postJson("/api/plugin/v1/agent-work-items/{$workItemId}/complete", [
        'protocol_version' => 'v1',
        'lease_token' => $leaseToken,
    ], pluginSharedMemoryHeaders($token))
        ->assertOk()
        ->assertJsonPath('item.status', 'completed_with_incomplete_memory')
        ->assertJsonPath('memory_entry', null);

    expect(DB::table('agent_work_items')->where('id', $workItemId)->value('status'))
        ->toBe('completed_with_incomplete_memory');
});

it('marks failed and releases active leases', function () {
    $token = pluginSharedMemoryTokenWithDevice();
    $projectId = pluginSharedMemoryProjectId();
    $repositoryId = pluginSharedMemoryRepositoryId();
    $workspaceId = pluginSharedMemoryWorkspace($repositoryId, $token['device_id']);
    $workItemId = pluginSharedMemoryWorkItem($projectId, $repositoryId);
    $leaseToken = pluginSharedMemoryClaim($this, $token, $workItemId, $workspaceId);

    $this->postJson("/api/plugin/v1/agent-work-items/{$workItemId}/fail", [
        'protocol_version' => 'v1',
        'lease_token' => $leaseToken,
        'failure_reason' => 'Repository checkout failed.',
    ], pluginSharedMemoryHeaders($token))
        ->assertOk()
        ->assertJsonPath('item.status', 'failed')
        ->assertJsonPath('item.failure_reason', 'Repository checkout failed.');

    expect(DB::table('agent_work_items')->where('id', $workItemId)->value('status'))->toBe('failed')
        ->and(DB::table('agent_work_item_leases')->where('agent_work_item_id', $workItemId)->whereNull('released_at')->exists())->toBeFalse();
});

it('prevents a second device from claiming or completing another device claim', function () {
    $firstToken = pluginSharedMemoryTokenWithDevice('first-device-secret');
    $secondToken = pluginSharedMemoryTokenWithDevice('second-device-secret');
    $projectId = pluginSharedMemoryProjectId();
    $repositoryId = pluginSharedMemoryRepositoryId();
    $firstWorkspaceId = pluginSharedMemoryWorkspace($repositoryId, $firstToken['device_id'], 'sha256:first-device');
    $secondWorkspaceId = pluginSharedMemoryWorkspace($repositoryId, $secondToken['device_id'], 'sha256:second-device');
    $workItemId = pluginSharedMemoryWorkItem($projectId, $repositoryId);
    $leaseToken = pluginSharedMemoryClaim($this, $firstToken, $workItemId, $firstWorkspaceId);

    $this->postJson("/api/plugin/v1/agent-work-items/{$workItemId}/claim", [
        'protocol_version' => 'v1',
        'local_workspace_id' => $secondWorkspaceId,
    ], pluginSharedMemoryHeaders($secondToken))
        ->assertConflict();

    $this->postJson("/api/plugin/v1/agent-work-items/{$workItemId}/complete", [
        'protocol_version' => 'v1',
        'lease_token' => $leaseToken,
        'memory_entry' => [
            'kind' => 'verification',
            'summary' => 'Second device should not complete.',
            'payload' => pluginSharedMemoryCompletionPayload(),
        ],
    ], pluginSharedMemoryHeaders($secondToken))
        ->assertConflict();
});

it('does not create a replacement lease when same-device reclaim loses the conditional update', function () {
    $token = pluginSharedMemoryTokenWithDevice();
    $projectId = pluginSharedMemoryProjectId();
    $repositoryId = pluginSharedMemoryRepositoryId();
    $workspaceId = pluginSharedMemoryWorkspace($repositoryId, $token['device_id']);
    $workItemId = pluginSharedMemoryWorkItem($projectId, $repositoryId);
    pluginSharedMemoryClaim($this, $token, $workItemId, $workspaceId);

    $initialLeaseCount = DB::table('agent_work_item_leases')->where('agent_work_item_id', $workItemId)->count();
    $initialClaimedEventCount = DB::table('agent_work_item_events')
        ->where('agent_work_item_id', $workItemId)
        ->where('event_type', 'claimed')
        ->count();
    $completedDuringClaim = false;

    DB::listen(function (Illuminate\Database\Events\QueryExecuted $query) use (&$completedDuringClaim, $workItemId): void {
        if ($completedDuringClaim || ! str_contains($query->sql, 'from "agent_work_items"')) {
            return;
        }

        if (($query->bindings[0] ?? null) !== $workItemId) {
            return;
        }

        $completedDuringClaim = true;

        DB::table('agent_work_items')->where('id', $workItemId)->update([
            'status' => 'completed',
            'completed_at' => now(),
            'updated_at' => now(),
        ]);
    });

    $this->postJson("/api/plugin/v1/agent-work-items/{$workItemId}/claim", [
        'protocol_version' => 'v1',
        'local_workspace_id' => $workspaceId,
    ], pluginSharedMemoryHeaders($token))
        ->assertConflict();

    expect($completedDuringClaim)->toBeTrue()
        ->and(DB::table('agent_work_items')->where('id', $workItemId)->value('status'))->toBe('claimed')
        ->and(DB::table('agent_work_item_leases')->where('agent_work_item_id', $workItemId)->count())->toBe($initialLeaseCount)
        ->and(DB::table('agent_work_item_leases')->where('agent_work_item_id', $workItemId)->whereNull('released_at')->count())->toBe(1)
        ->and(DB::table('agent_work_item_events')->where('agent_work_item_id', $workItemId)->where('event_type', 'claimed')->count())->toBe($initialClaimedEventCount);
});

it('rejects claiming project-scoped work from a workspace in another project', function () {
    $token = pluginSharedMemoryTokenWithDevice();
    $projectId = pluginSharedMemoryProjectId();
    $otherProjectId = pluginSharedMemoryProject('Other Workspace Project', 'other-workspace-project');
    $otherRepositoryId = pluginSharedMemoryRepository($otherProjectId, 'Other Workspace Repository', 'other-workspace-repository');
    $workspaceId = pluginSharedMemoryWorkspace($otherRepositoryId, $token['device_id']);
    $workItemId = pluginSharedMemoryWorkItem($projectId, null);

    $this->postJson("/api/plugin/v1/agent-work-items/{$workItemId}/claim", [
        'protocol_version' => 'v1',
        'local_workspace_id' => $workspaceId,
    ], pluginSharedMemoryHeaders($token))
        ->assertUnprocessable();

    expect(DB::table('agent_work_items')->where('id', $workItemId)->value('status'))->toBe('queued')
        ->and(DB::table('agent_work_item_leases')->where('agent_work_item_id', $workItemId)->exists())->toBeFalse();
});

it('includes project-scoped work when listing with a repository filter', function () {
    $token = pluginSharedMemoryTokenWithDevice();
    $projectId = pluginSharedMemoryProjectId();
    $repositoryId = pluginSharedMemoryRepositoryId();
    $otherRepositoryId = pluginSharedMemoryRepository($projectId, 'Other Queue Repository', 'other-queue-repository');
    $otherProjectId = pluginSharedMemoryProject('Other Queue Project', 'other-queue-project');

    $projectWorkItemId = pluginSharedMemoryWorkItem($projectId, null, ['title' => 'Project-scoped queue work']);
    $repositoryWorkItemId = pluginSharedMemoryWorkItem($projectId, $repositoryId, ['title' => 'Repository queue work']);
    $otherRepositoryWorkItemId = pluginSharedMemoryWorkItem($projectId, $otherRepositoryId, ['title' => 'Other repository work']);
    $otherProjectWorkItemId = pluginSharedMemoryWorkItem($otherProjectId, null, ['title' => 'Other project work']);

    $items = $this->getJson("/api/plugin/v1/agent-work-items?repository_id={$repositoryId}", pluginSharedMemoryHeaders($token))
        ->assertOk()
        ->json('items');

    expect(collect($items)->pluck('id')->all())->toContain($projectWorkItemId, $repositoryWorkItemId)
        ->and(collect($items)->pluck('id')->all())->not->toContain($otherRepositoryWorkItemId, $otherProjectWorkItemId);
});

it('hides archived and deleted project work from the unfiltered queue listing', function () {
    $token = pluginSharedMemoryTokenWithDevice();
    $projectId = pluginSharedMemoryProjectId();
    $activeWorkItemId = pluginSharedMemoryWorkItem($projectId, null, ['title' => 'Active project work']);
    $archivedProjectId = pluginSharedMemoryProject('Archived Queue Project', 'archived-queue-project', 'archived');
    $deletedProjectId = pluginSharedMemoryProject('Deleted Queue Project', 'deleted-queue-project', 'deleted');
    $archivedWorkItemId = pluginSharedMemoryWorkItem($archivedProjectId, null, ['title' => 'Archived project work']);
    $deletedWorkItemId = pluginSharedMemoryWorkItem($deletedProjectId, null, ['title' => 'Deleted project work']);

    $items = $this->getJson('/api/plugin/v1/agent-work-items', pluginSharedMemoryHeaders($token))
        ->assertOk()
        ->json('items');

    expect(collect($items)->pluck('id')->all())->toContain($activeWorkItemId)
        ->and(collect($items)->pluck('id')->all())->not->toContain($archivedWorkItemId, $deletedWorkItemId);
});

it('rejects memory completion with an empty payload', function () {
    $token = pluginSharedMemoryTokenWithDevice();
    $projectId = pluginSharedMemoryProjectId();
    $repositoryId = pluginSharedMemoryRepositoryId();
    $workspaceId = pluginSharedMemoryWorkspace($repositoryId, $token['device_id']);
    $workItemId = pluginSharedMemoryWorkItem($projectId, $repositoryId);
    $leaseToken = pluginSharedMemoryClaim($this, $token, $workItemId, $workspaceId);

    $this->postJson("/api/plugin/v1/agent-work-items/{$workItemId}/complete", [
        'protocol_version' => 'v1',
        'lease_token' => $leaseToken,
        'memory_entry' => [
            'kind' => 'implementation',
            'summary' => 'Payload shape should be validated.',
            'payload' => [],
        ],
    ], pluginSharedMemoryHeaders($token))
        ->assertUnprocessable()
        ->assertJsonValidationErrors([
            'memory_entry.payload.why',
            'memory_entry.payload.changed',
            'memory_entry.payload.tests',
            'memory_entry.payload.skipped_checks',
            'memory_entry.payload.risks',
        ]);

    expect(DB::table('agent_work_items')->where('id', $workItemId)->value('status'))->toBe('claimed')
        ->and(DB::table('project_memory_entries')->where('project_id', $projectId)->where('source', 'local_agent')->exists())->toBeFalse();
});

it('accepts memory completion with the required payload summary fields', function () {
    $token = pluginSharedMemoryTokenWithDevice();
    $projectId = pluginSharedMemoryProjectId();
    $repositoryId = pluginSharedMemoryRepositoryId();
    $workspaceId = pluginSharedMemoryWorkspace($repositoryId, $token['device_id']);
    $workItemId = pluginSharedMemoryWorkItem($projectId, $repositoryId);
    $leaseToken = pluginSharedMemoryClaim($this, $token, $workItemId, $workspaceId);

    $this->postJson("/api/plugin/v1/agent-work-items/{$workItemId}/complete", [
        'protocol_version' => 'v1',
        'lease_token' => $leaseToken,
        'memory_entry' => [
            'kind' => 'implementation',
            'summary' => 'Payload shape includes required fields.',
            'payload' => pluginSharedMemoryCompletionPayload([
                'changed' => [['path' => 'app/Foo.php']],
                'tests' => [['command' => 'php artisan test']],
            ]),
        ],
    ], pluginSharedMemoryHeaders($token))
        ->assertOk()
        ->assertJsonPath('item.status', 'completed')
        ->assertJsonPath('memory_entry.payload.why', 'Completed the requested local-agent work.');
});

it('rejects plugin tokens without required scopes', function () {
    $token = pluginSharedMemoryToken(scopes: ['runs.write']);
    $projectId = pluginSharedMemoryProjectId();

    $this->getJson("/api/plugin/v1/projects/{$projectId}/shared-memory-pack", pluginSharedMemoryHeaders($token))
        ->assertForbidden()
        ->assertJsonPath('error.code', 'scope_missing');
});

it('guards archived and deleted projects', function () {
    $token = pluginSharedMemoryToken();
    $archivedProjectId = pluginSharedMemoryProject('Archived Memory Project', 'archived-memory-project', 'archived');
    $deletedProjectId = pluginSharedMemoryProject('Deleted Memory Project', 'deleted-memory-project', 'deleted');

    $this->getJson("/api/plugin/v1/projects/{$archivedProjectId}/shared-memory-pack", pluginSharedMemoryHeaders($token))
        ->assertConflict()
        ->assertJsonPath('error.code', 'project_archived');

    $this->getJson("/api/plugin/v1/projects/{$deletedProjectId}/shared-memory-pack", pluginSharedMemoryHeaders($token))
        ->assertNotFound();
});

it('rejects work queue access when the token is not bound to an active device', function () {
    $unboundToken = pluginSharedMemoryToken();
    $inactiveToken = pluginSharedMemoryTokenWithDevice('inactive-device-secret', 'revoked');

    $this->getJson('/api/plugin/v1/agent-work-items', pluginSharedMemoryHeaders($unboundToken))
        ->assertUnauthorized()
        ->assertJsonPath('error.code', 'device_required');

    $this->getJson('/api/plugin/v1/agent-work-items', pluginSharedMemoryHeaders($inactiveToken))
        ->assertUnauthorized()
        ->assertJsonPath('error.code', 'device_required');
});

/**
 * @param list<string>|null $scopes
 * @return array{id: string, prefix: string, plain_token: string, secret: string}
 */
function pluginSharedMemoryToken(string $secret = 'shared-memory-secret', ?array $scopes = null): array
{
    $userId = DB::table('users')->where('email', 'admin@example.com')->value('id');
    $id = (string) Str::ulid();
    $prefix = 'devb_live_'.$id;
    $now = now();

    DB::table('api_tokens')->insert([
        'id' => $id,
        'token_prefix' => $prefix,
        'token_hash' => hash('sha256', $secret),
        'user_id' => $userId,
        'device_id' => null,
        'name' => 'Shared Memory Plugin Test Token',
        'scopes' => json_encode($scopes ?? ['projects.read', 'repositories.read', 'runs.write'], JSON_THROW_ON_ERROR),
        'expires_at' => now()->addDay(),
        'revoked_at' => null,
        'last_used_at' => null,
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    return [
        'id' => $id,
        'prefix' => $prefix,
        'plain_token' => $prefix.'|'.$secret,
        'secret' => $secret,
    ];
}

/**
 * @return array{id: string, prefix: string, plain_token: string, secret: string, device_id: string}
 */
function pluginSharedMemoryTokenWithDevice(string $secret = 'shared-memory-device-secret', string $deviceStatus = 'active'): array
{
    $token = pluginSharedMemoryToken($secret);
    $deviceId = (string) Str::ulid();
    $userId = DB::table('api_tokens')->where('id', $token['id'])->value('user_id');
    $now = now();

    DB::table('devices')->insert([
        'id' => $deviceId,
        'user_id' => $userId,
        'name' => 'Shared Memory Test Device',
        'fingerprint_hash' => 'sha256:shared-memory-device-'.$deviceId,
        'platform_os' => 'linux',
        'platform_arch' => 'amd64',
        'plugin_version' => '0.1.0',
        'last_seen_at' => $now,
        'status' => $deviceStatus,
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    DB::table('api_tokens')->where('id', $token['id'])->update([
        'device_id' => $deviceId,
        'updated_at' => $now,
    ]);

    $token['device_id'] = $deviceId;

    return $token;
}

/**
 * @param array{id: string, prefix: string, plain_token: string, secret: string, device_id?: string} $token
 * @return array<string, string>
 */
function pluginSharedMemoryHeaders(array $token): array
{
    $headers = [
        'Authorization' => 'Bearer '.$token['plain_token'],
        'X-DevBoard-Protocol' => 'v1',
        'X-DevBoard-Plugin-Version' => '0.1.0',
    ];

    if (array_key_exists('device_id', $token)) {
        $headers['X-DevBoard-Device-Id'] = $token['device_id'];
    }

    return $headers;
}

function pluginSharedMemoryProjectId(): string
{
    return DB::table('projects')->where('slug', 'demo-project')->value('id');
}

function pluginSharedMemoryRepositoryId(): string
{
    return DB::table('repositories')->where('slug', 'demo-repository')->value('id');
}

function pluginSharedMemoryProject(string $name, string $slug, string $status = 'active'): string
{
    $id = (string) Str::ulid();
    $adminId = DB::table('users')->where('email', 'admin@example.com')->value('id');
    $now = now();

    DB::table('projects')->insert([
        'id' => $id,
        'name' => $name,
        'slug' => $slug,
        'description' => "{$name} description.",
        'status' => $status,
        'default_code_exposure_policy' => 'full_code_artifacts',
        'created_by_user_id' => $adminId,
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    return $id;
}

function pluginSharedMemoryRepository(string $projectId, string $name, string $slug): string
{
    $id = (string) Str::ulid();
    $now = now();

    DB::table('repositories')->insert([
        'id' => $id,
        'project_id' => $projectId,
        'name' => $name,
        'slug' => $slug,
        'default_branch' => 'main',
        'local_only' => true,
        'code_exposure_policy' => 'full_code_artifacts',
        'protected_paths' => json_encode([], JSON_THROW_ON_ERROR),
        'excluded_paths' => json_encode([], JSON_THROW_ON_ERROR),
        'stack_hints' => json_encode(['php'], JSON_THROW_ON_ERROR),
        'graph_enabled' => true,
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    return $id;
}

function pluginSharedMemoryWorkspace(string $repositoryId, string $deviceId, string $rootHash = 'sha256:shared-memory-root'): string
{
    $id = (string) Str::ulid();
    $now = now();

    DB::table('local_workspaces')->insert([
        'id' => $id,
        'repository_id' => $repositoryId,
        'device_id' => $deviceId,
        'local_root_hash' => $rootHash,
        'display_path' => '/workspace/demo',
        'current_branch' => 'main',
        'last_head_sha' => 'abc123',
        'dirty_status' => 'clean',
        'last_snapshot_id' => null,
        'last_seen_at' => $now,
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    return $id;
}

function pluginSharedMemoryEntry(string $projectId, ?string $repositoryId, string $summary, array $payload, mixed $occurredAt): string
{
    $id = (string) Str::ulid();
    $now = now();

    DB::table('project_memory_entries')->insert([
        'id' => $id,
        'project_id' => $projectId,
        'repository_id' => $repositoryId,
        'task_id' => null,
        'run_id' => null,
        'author_user_id' => null,
        'agent_key' => 'socrates',
        'source' => 'server_agent',
        'kind' => 'decision',
        'completeness' => 'complete',
        'summary' => $summary,
        'payload' => json_encode($payload, JSON_THROW_ON_ERROR),
        'occurred_at' => $occurredAt,
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    return $id;
}

function pluginSharedMemoryWorkItem(string $projectId, ?string $repositoryId, array $overrides = []): string
{
    $id = (string) Str::ulid();
    $now = now();

    DB::table('agent_work_items')->insert([
        'id' => $id,
        'project_id' => $projectId,
        'repository_id' => $repositoryId,
        'task_id' => $overrides['task_id'] ?? null,
        'requested_by_user_id' => null,
        'assigned_agent_key' => $overrides['assigned_agent_key'] ?? 'local_agent',
        'status' => $overrides['status'] ?? 'queued',
        'priority' => $overrides['priority'] ?? 'normal',
        'title' => $overrides['title'] ?? 'Run local implementation',
        'prompt' => $overrides['prompt'] ?? 'Please make the requested local workspace changes.',
        'payload' => json_encode($overrides['payload'] ?? [], JSON_THROW_ON_ERROR),
        'requires_memory_entry' => $overrides['requires_memory_entry'] ?? true,
        'result_memory_entry_id' => null,
        'claimed_by_device_id' => $overrides['claimed_by_device_id'] ?? null,
        'claimed_at' => $overrides['claimed_at'] ?? null,
        'heartbeat_at' => $overrides['heartbeat_at'] ?? null,
        'completed_at' => null,
        'failed_at' => null,
        'canceled_at' => null,
        'failure_reason' => null,
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    return $id;
}

function pluginSharedMemoryClaim(mixed $test, array $token, string $workItemId, string $workspaceId): string
{
    return $test->postJson("/api/plugin/v1/agent-work-items/{$workItemId}/claim", [
        'protocol_version' => 'v1',
        'local_workspace_id' => $workspaceId,
    ], pluginSharedMemoryHeaders($token))
        ->assertOk()
        ->json('lease_token');
}

/**
 * @param array<string, mixed> $overrides
 * @return array{why: string, changed: array<int, mixed>, tests: array<int, mixed>, skipped_checks: array<int, mixed>, risks: array<int, mixed>}
 */
function pluginSharedMemoryCompletionPayload(array $overrides = []): array
{
    return array_merge([
        'why' => 'Completed the requested local-agent work.',
        'changed' => ['app/Foo.php'],
        'tests' => ['php artisan test'],
        'skipped_checks' => [],
        'risks' => [],
    ], $overrides);
}
