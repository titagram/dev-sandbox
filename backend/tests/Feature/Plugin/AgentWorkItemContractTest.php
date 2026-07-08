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

it('pins local agent work item API response and lifecycle contract', function () {
    $token = agentWorkContractTokenWithDevice();
    $projectId = (string) DB::table('projects')->where('slug', 'demo-project')->value('id');
    $repositoryId = (string) DB::table('repositories')->where('project_id', $projectId)->value('id');
    $workspaceId = agentWorkContractWorkspace($repositoryId, $token['device_id']);
    $taskId = agentWorkContractTask($projectId);
    $payload = agentWorkContractKanbanPayload($projectId, $repositoryId, $taskId);
    $workItemId = agentWorkContractItem($projectId, $repositoryId, $payload);

    $this->getJson("/api/plugin/v1/agent-work-items?project_id={$projectId}&repository_id={$repositoryId}", agentWorkContractHeaders($token))
        ->assertOk()
        ->assertJsonPath('protocol_version', 'v1')
        ->assertJsonPath('items.0.id', $workItemId)
        ->assertJsonPath('items.0.assigned_agent_key', 'local_agent')
        ->assertJsonPath('items.0.status', 'queued')
        ->assertJsonPath('items.0.requires_memory_entry', true)
        ->assertJsonPath('items.0.payload.schema', 'hades.kanban_task_work.v1')
        ->assertJsonPath('items.0.payload.ready_for_agent_work', true)
        ->assertJsonPath('items.0.payload.memory_required', true)
        ->assertJsonPath('items.0.payload.source_access_policy.mode', 'source_free_first');

    $claim = $this->postJson("/api/plugin/v1/agent-work-items/{$workItemId}/claim", [
        'protocol_version' => 'v1',
        'local_workspace_id' => $workspaceId,
    ], agentWorkContractHeaders($token))
        ->assertOk()
        ->assertJsonPath('protocol_version', 'v1')
        ->assertJsonPath('item.id', $workItemId)
        ->assertJsonPath('item.status', 'claimed')
        ->assertJsonStructure(['lease_token'])
        ->json();

    $this->postJson("/api/plugin/v1/agent-work-items/{$workItemId}/complete", [
        'protocol_version' => 'v1',
        'lease_token' => $claim['lease_token'],
        'chat_message' => 'Local agent diagnosed the checkout failure from Hades evidence.',
        'memory_entry' => [
            'kind' => 'implementation',
            'summary' => 'Local agent completed the backend task contract fixture.',
            'payload' => [
                'why' => 'Pinned plugin work-item completion contract.',
                'changed' => ['tests/Feature/Plugin/AgentWorkItemContractTest.php'],
                'tests' => ['php artisan test tests/Feature/Plugin/AgentWorkItemContractTest.php'],
                'skipped_checks' => [],
                'risks' => [],
            ],
        ],
    ], agentWorkContractHeaders($token))
        ->assertOk()
        ->assertJsonPath('protocol_version', 'v1')
        ->assertJsonPath('item.id', $workItemId)
        ->assertJsonPath('item.status', 'completed')
        ->assertJsonPath('memory_entry.source', 'local_agent')
        ->assertJsonPath('memory_entry.payload.why', 'Pinned plugin work-item completion contract.');
});

/**
 * @return array{id: string, plain_token: string, device_id: string}
 */
function agentWorkContractTokenWithDevice(): array
{
    $user = User::factory()->create(['status' => 'active']);
    $tokenId = (string) Str::ulid();
    $deviceId = (string) Str::ulid();
    $secret = 'agent-work-contract-secret';
    $prefix = 'devb_live_'.$tokenId;
    $now = now();

    DB::table('devices')->insert([
        'id' => $deviceId,
        'user_id' => $user->id,
        'name' => 'Contract Device',
        'fingerprint_hash' => 'sha256:agent-work-contract-device',
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
        'user_id' => $user->id,
        'device_id' => $deviceId,
        'name' => 'Agent Work Contract Token',
        'scopes' => json_encode(['projects.read', 'runs.write'], JSON_THROW_ON_ERROR),
        'expires_at' => now()->addMonth(),
        'revoked_at' => null,
        'last_used_at' => null,
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    return [
        'id' => $tokenId,
        'plain_token' => $prefix.'|'.$secret,
        'device_id' => $deviceId,
    ];
}

/**
 * @param  array{id: string, plain_token: string, device_id: string}  $token
 * @return array<string, string>
 */
function agentWorkContractHeaders(array $token): array
{
    return [
        'Authorization' => 'Bearer '.$token['plain_token'],
        'X-DevBoard-Protocol' => 'v1',
        'X-DevBoard-Plugin-Version' => '0.1.0',
        'X-DevBoard-Device-Id' => $token['device_id'],
        'Accept' => 'application/json',
    ];
}

function agentWorkContractWorkspace(string $repositoryId, string $deviceId): string
{
    $id = (string) Str::ulid();
    $now = now();

    DB::table('local_workspaces')->insert([
        'id' => $id,
        'repository_id' => $repositoryId,
        'device_id' => $deviceId,
        'local_root_hash' => 'sha256:agent-work-contract-root',
        'display_path' => '/workspace/contract',
        'current_branch' => 'main',
        'last_head_sha' => str_repeat('b', 40),
        'dirty_status' => 'clean',
        'last_snapshot_id' => null,
        'last_seen_at' => $now,
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    return $id;
}

function agentWorkContractTask(string $projectId): string
{
    $taskId = (string) Str::ulid();
    $columnId = (string) DB::table('kanban_columns')
        ->join('kanban_boards', 'kanban_boards.id', '=', 'kanban_columns.board_id')
        ->where('kanban_boards.project_id', $projectId)
        ->orderBy('kanban_columns.position')
        ->value('kanban_columns.id');
    $adminId = (int) DB::table('users')->where('email', 'admin@example.com')->value('id');
    $now = now();

    DB::table('tasks')->insert([
        'id' => $taskId,
        'project_id' => $projectId,
        'title' => 'Diagnose checkout contract failure',
        'description' => 'Checkout fails after the customer address step.',
        'acceptance_criteria' => json_encode(['Explain root cause with evidence refs.'], JSON_THROW_ON_ERROR),
        'status_column_id' => $columnId,
        'priority' => 'high',
        'risk_level' => 'medium',
        'owner_user_id' => null,
        'created_by_user_id' => $adminId,
        'due_at' => null,
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    return $taskId;
}

/**
 * @return array<string, mixed>
 */
function agentWorkContractKanbanPayload(string $projectId, string $repositoryId, string $taskId): array
{
    return [
        'schema' => 'hades.kanban_task_work.v1',
        'task_id' => $taskId,
        'project_id' => $projectId,
        'repository_id' => $repositoryId,
        'workspace_binding_id' => 'wb_contract_1',
        'title' => 'Diagnose checkout contract failure',
        'description' => 'Checkout fails after the customer address step.',
        'acceptance_criteria' => ['Explain root cause with evidence refs.'],
        'priority' => 'high',
        'risk' => 'medium',
        'normalized_problem' => 'Diagnose checkout failure from shared Hades evidence.',
        'task_type' => 'bug',
        'clarification_status' => 'ready',
        'ready_for_agent_work' => true,
        'required_context' => ['shared_project_memory', 'project_awareness_status', 'bug_evidence'],
        'source_access_policy' => ['mode' => 'source_free_first', 'source_slice_jobs_allowed' => true],
        'project_awareness_required' => true,
        'memory_required' => true,
        'created_from' => ['type' => 'kanban_task', 'source' => 'contract_test'],
        'bug_report_id' => 'bug_contract_1',
        'evidence_refs' => [['kind' => 'bug_evidence', 'id' => 'ev_contract_1']],
        'bug_intake' => ['status' => 'existing'],
    ];
}

/**
 * @param  array<string, mixed>  $payload
 */
function agentWorkContractItem(string $projectId, string $repositoryId, array $payload): string
{
    $id = (string) Str::ulid();
    $now = now();

    DB::table('agent_work_items')->insert([
        'id' => $id,
        'project_id' => $projectId,
        'repository_id' => $repositoryId,
        'task_id' => $payload['task_id'],
        'requested_by_user_id' => null,
        'assigned_agent_key' => 'local_agent',
        'status' => 'queued',
        'priority' => 'high',
        'title' => 'Diagnose checkout contract failure',
        'prompt' => 'Use shared Hades memory and project awareness evidence.',
        'payload' => json_encode($payload, JSON_THROW_ON_ERROR),
        'requires_memory_entry' => true,
        'result_memory_entry_id' => null,
        'claimed_by_device_id' => null,
        'claimed_at' => null,
        'heartbeat_at' => null,
        'completed_at' => null,
        'failed_at' => null,
        'canceled_at' => null,
        'failure_reason' => null,
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    return $id;
}
