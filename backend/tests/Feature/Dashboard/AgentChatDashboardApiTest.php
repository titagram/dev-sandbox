<?php

use App\Models\User;
use Database\Seeders\DevBoardSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(DevBoardSeeder::class);
});

it('creates a persistent local-agent chat thread backed by queued agent work', function () {
    $developer = agentChatDashboardApiUserWithRole('Developer');
    $projectId = (string) DB::table('projects')->where('slug', 'demo-project')->value('id');
    $repositoryId = (string) DB::table('repositories')->where('project_id', $projectId)->value('id');
    $taskId = agentChatDashboardApiTask($projectId);

    $thread = $this->actingAs($developer)
        ->postJson("/api/dashboard/projects/{$projectId}/agent-chats", [
            'agent_key' => 'local_agent',
            'title' => 'Local implementation chat',
            'repository_id' => $repositoryId,
            'task_id' => $taskId,
            'initial_message' => 'Prepara il piano prima di toccare codice.',
            'metadata' => ['surface' => 'mini_chat'],
        ])
        ->assertCreated()
        ->assertJsonPath('thread.agent_key', 'local_agent')
        ->assertJsonPath('thread.status', 'pending_local_agent')
        ->assertJsonPath('thread.title', 'Local implementation chat')
        ->assertJsonPath('thread.repository_id', $repositoryId)
        ->assertJsonPath('thread.task_id', $taskId)
        ->assertJsonPath('thread.messages.0.role', 'user')
        ->assertJsonPath('thread.messages.0.content', 'Prepara il piano prima di toccare codice.')
        ->json('thread');

    $workItem = DB::table('agent_work_items')->where('id', $thread['latest_agent_work_item_id'])->first();
    $payload = json_decode((string) $workItem->payload, true, flags: JSON_THROW_ON_ERROR);

    expect($workItem->status)->toBe('queued')
        ->and($workItem->assigned_agent_key)->toBe('local_agent')
        ->and($payload['schema'])->toBe('devboard.agent_chat_turn.v1')
        ->and($payload['agent_chat_thread_id'])->toBe($thread['id'])
        ->and($payload['agent_chat_message_id'])->toBe($thread['messages'][0]['id'])
        ->and(DB::table('agent_work_item_events')->where('agent_work_item_id', $workItem->id)->where('event_type', 'queued')->exists())->toBeTrue();

    $this->actingAs($developer)
        ->getJson("/api/dashboard/projects/{$projectId}/agent-chats")
        ->assertOk()
        ->assertJsonPath('threads.0.id', $thread['id'])
        ->assertJsonPath('threads.0.message_count', 1)
        ->assertJsonPath('threads.0.last_message.content', 'Prepara il piano prima di toccare codice.');
});

it('runs a server agent chat turn and appends the assistant answer to the same thread', function () {
    Http::fake([
        'https://opencode.ai/zen/go/v1/chat/completions' => Http::response([
            'choices' => [
                [
                    'message' => [
                        'content' => 'Socrates consiglia di separare wiki, logbook e agent notes.',
                    ],
                ],
            ],
        ]),
    ]);

    $developer = agentChatDashboardApiUserWithRole('Developer');
    $projectId = (string) DB::table('projects')->where('slug', 'demo-project')->value('id');
    agentChatDashboardApiConfigureProvider();

    $thread = $this->actingAs($developer)
        ->postJson("/api/dashboard/projects/{$projectId}/agent-chats", [
            'agent_key' => 'socrates',
            'initial_message' => 'Come organizzeresti le memorie del progetto?',
        ])
        ->assertCreated()
        ->assertJsonPath('thread.agent_key', 'socrates')
        ->assertJsonPath('thread.status', 'active')
        ->assertJsonPath('thread.messages.0.role', 'user')
        ->assertJsonPath('thread.messages.1.role', 'assistant')
        ->assertJsonPath('thread.messages.1.content', 'Socrates consiglia di separare wiki, logbook e agent notes.')
        ->json('thread');

    expect($thread['latest_agent_work_item_id'])->not->toBeNull()
        ->and($thread['latest_assistant_run_id'])->not->toBeNull()
        ->and(DB::table('agent_work_items')->where('id', $thread['latest_agent_work_item_id'])->value('status'))->toBe('completed')
        ->and(DB::table('assistant_runs')->where('id', $thread['latest_assistant_run_id'])->where('target_id', $thread['latest_agent_work_item_id'])->exists())->toBeTrue()
        ->and(DB::table('project_memory_entries')->where('project_id', $projectId)->where('source', 'server_agent')->where('agent_key', 'socrates')->exists())->toBeTrue();

    Http::assertSent(fn ($request): bool => $request->url() === 'https://opencode.ai/zen/go/v1/chat/completions'
        && $request['model'] === 'deepseek-v4-flash'
        && str_contains($request->body(), 'Come organizzeresti le memorie del progetto?')
        && $request->hasHeader('Authorization', 'Bearer sk-opencode-test'));
});

it('starts a project chat with a custom server agent profile', function () {
    Http::fake([
        'https://opencode.ai/zen/go/v1/chat/completions' => Http::response([
            'choices' => [['message' => ['content' => 'Custom chat response.']]],
        ]),
    ]);

    $developer = agentChatDashboardApiUserWithRole('Developer');
    $projectId = (string) DB::table('projects')->where('slug', 'demo-project')->value('id');
    agentChatDashboardApiConfigureProvider();

    DB::table('ai_agent_profiles')->insert([
        'id' => (string) Str::ulid(),
        'agent_key' => 'release_planner',
        'display_name' => 'Release Planner',
        'description' => 'Plans releases from DevBoard context.',
        'agent_type' => 'specialist',
        'delegation_mode' => 'controlled_registry',
        'parent_agent_key' => null,
        'default_model_profile_id' => DB::table('ai_model_profiles')->where('profile_key', 'openai_default_text')->value('id'),
        'requires_human_approval' => true,
        'enabled' => true,
        'allowed_tools' => json_encode(['search_project_memory'], JSON_THROW_ON_ERROR),
        'output_schema' => json_encode(['type' => 'object'], JSON_THROW_ON_ERROR),
        'trigger_events' => json_encode(['manual_chat'], JSON_THROW_ON_ERROR),
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $this->actingAs($developer)
        ->postJson("/api/dashboard/projects/{$projectId}/agent-chats", [
            'agent_key' => 'release_planner',
            'initial_message' => 'Prepara una nota di rilascio sintetica.',
        ])
        ->assertCreated()
        ->assertJsonPath('thread.agent_key', 'release_planner')
        ->assertJsonPath('thread.messages.1.content', 'Custom chat response.');
});

it('rejects project-scoped agent chat for projects without visibility', function () {
    $developer = agentChatDashboardApiUserWithRole('Developer');
    $projectA = (string) DB::table('projects')->where('slug', 'demo-project')->value('id');
    $projectB = agentChatDashboardApiProject('Project Scoped Agent Chat Project', 'project-scoped-agent-chat-project');

    agentChatDashboardApiProjectScopedAgent('project_limited_chatter', 'Project Limited Chatter', $projectA);

    $this->actingAs($developer)
        ->postJson("/api/dashboard/projects/{$projectB['project_id']}/agent-chats", [
            'agent_key' => 'project_limited_chatter',
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['agent_key']);
});

it('continues a persistent thread with new user turns', function () {
    $developer = agentChatDashboardApiUserWithRole('Developer');
    $projectId = (string) DB::table('projects')->where('slug', 'demo-project')->value('id');

    $thread = $this->actingAs($developer)
        ->postJson("/api/dashboard/projects/{$projectId}/agent-chats", [
            'agent_key' => 'local_agent',
            'initial_message' => 'Prima domanda.',
        ])
        ->assertCreated()
        ->json('thread');

    $continued = $this->actingAs($developer)
        ->postJson("/api/dashboard/projects/{$projectId}/agent-chats/{$thread['id']}/messages", [
            'content' => 'Seconda domanda nello stesso thread.',
            'metadata' => ['client_message_id' => 'msg-2'],
        ])
        ->assertOk()
        ->assertJsonPath('thread.id', $thread['id'])
        ->assertJsonPath('thread.status', 'pending_local_agent')
        ->json('thread');

    expect($continued['messages'])->toHaveCount(2)
        ->and(collect($continued['messages'])->pluck('role')->all())->toBe(['user', 'user'])
        ->and($continued['messages'][1]['metadata']['client_message_id'])->toBe('msg-2')
        ->and(DB::table('agent_work_items')->where('project_id', $projectId)->where('assigned_agent_key', 'local_agent')->count())->toBe(2);
});

it('archives a local-agent chat thread and cancels its queued work item', function () {
    $developer = agentChatDashboardApiUserWithRole('Developer');
    $projectId = (string) DB::table('projects')->where('slug', 'demo-project')->value('id');

    $thread = $this->actingAs($developer)
        ->postJson("/api/dashboard/projects/{$projectId}/agent-chats", [
            'agent_key' => 'local_agent',
            'initial_message' => 'This thread can be archived before the local agent claims it.',
        ])
        ->assertCreated()
        ->json('thread');

    $workItemId = $thread['latest_agent_work_item_id'];

    $this->actingAs($developer)
        ->deleteJson("/api/dashboard/projects/{$projectId}/agent-chats/{$thread['id']}", [
            'message' => 'Thread is no longer useful.',
        ])
        ->assertOk()
        ->assertJsonPath('thread.id', $thread['id'])
        ->assertJsonPath('thread.status', 'archived')
        ->assertJsonPath('thread.archive_reason', 'Thread is no longer useful.');

    expect(DB::table('agent_chat_threads')->where('id', $thread['id'])->value('archived_at'))->not->toBeNull()
        ->and(DB::table('agent_work_items')->where('id', $workItemId)->value('status'))->toBe('canceled')
        ->and(DB::table('agent_work_items')->where('id', $workItemId)->value('archived_at'))->not->toBeNull()
        ->and(DB::table('agent_work_item_events')->where('agent_work_item_id', $workItemId)->where('event_type', 'canceled')->exists())->toBeTrue();

    $threads = $this->actingAs($developer)
        ->getJson("/api/dashboard/projects/{$projectId}/agent-chats")
        ->assertOk()
        ->json('threads');

    expect(collect($threads)->pluck('id')->all())->not->toContain($thread['id']);

    $this->actingAs($developer)
        ->postJson("/api/dashboard/projects/{$projectId}/agent-chats/{$thread['id']}/messages", [
            'content' => 'Cannot continue an archived thread.',
        ])
        ->assertConflict();
});

it('does not archive a chat thread while the latest work item is running', function () {
    $developer = agentChatDashboardApiUserWithRole('Developer');
    $projectId = (string) DB::table('projects')->where('slug', 'demo-project')->value('id');

    $thread = $this->actingAs($developer)
        ->postJson("/api/dashboard/projects/{$projectId}/agent-chats", [
            'agent_key' => 'local_agent',
            'initial_message' => 'Running work blocks archive.',
        ])
        ->assertCreated()
        ->json('thread');

    DB::table('agent_work_items')->where('id', $thread['latest_agent_work_item_id'])->update([
        'status' => 'running',
        'claimed_at' => now(),
        'heartbeat_at' => now(),
        'updated_at' => now(),
    ]);

    $this->actingAs($developer)
        ->deleteJson("/api/dashboard/projects/{$projectId}/agent-chats/{$thread['id']}")
        ->assertConflict();

    expect(DB::table('agent_chat_threads')->where('id', $thread['id'])->value('status'))->toBe('pending_local_agent');
});

it('allows sysadmin to read but not create or continue agent chats', function () {
    $developer = agentChatDashboardApiUserWithRole('Developer');
    $sysadmin = agentChatDashboardApiUserWithRole('Sysadmin');
    $projectId = (string) DB::table('projects')->where('slug', 'demo-project')->value('id');

    $thread = $this->actingAs($developer)
        ->postJson("/api/dashboard/projects/{$projectId}/agent-chats", [
            'agent_key' => 'local_agent',
            'initial_message' => 'Readable chat.',
        ])
        ->assertCreated()
        ->json('thread');

    $this->actingAs($sysadmin)
        ->getJson("/api/dashboard/projects/{$projectId}/agent-chats/{$thread['id']}")
        ->assertOk()
        ->assertJsonPath('thread.id', $thread['id']);

    $this->actingAs($sysadmin)
        ->postJson("/api/dashboard/projects/{$projectId}/agent-chats", [
            'agent_key' => 'local_agent',
            'initial_message' => 'Sysadmin cannot create.',
        ])
        ->assertForbidden();

    $this->actingAs($sysadmin)
        ->postJson("/api/dashboard/projects/{$projectId}/agent-chats/{$thread['id']}/messages", [
            'content' => 'Sysadmin cannot continue.',
        ])
        ->assertForbidden();

    $this->actingAs($sysadmin)
        ->deleteJson("/api/dashboard/projects/{$projectId}/agent-chats/{$thread['id']}")
        ->assertForbidden();
});

it('keeps agent chat threads scoped to their project', function () {
    $developer = agentChatDashboardApiUserWithRole('Developer');
    $primaryProjectId = (string) DB::table('projects')->where('slug', 'demo-project')->value('id');
    $secondary = agentChatDashboardApiProject('Agent Chat Secondary', 'agent-chat-secondary');

    $secondaryThread = $this->actingAs($developer)
        ->postJson("/api/dashboard/projects/{$secondary['project_id']}/agent-chats", [
            'agent_key' => 'local_agent',
            'initial_message' => 'Secondary project chat.',
        ])
        ->assertCreated()
        ->json('thread');

    $this->actingAs($developer)
        ->getJson("/api/dashboard/projects/{$primaryProjectId}/agent-chats/{$secondaryThread['id']}")
        ->assertNotFound();

    $this->actingAs($developer)
        ->postJson("/api/dashboard/projects/{$primaryProjectId}/agent-chats", [
            'agent_key' => 'local_agent',
            'repository_id' => $secondary['repository_id'],
            'initial_message' => 'Wrong repository scope.',
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['repository_id']);
});

it('blocks chat writes to archived and deleted projects', function (string $status) {
    $developer = agentChatDashboardApiUserWithRole('Developer');
    $project = agentChatDashboardApiProject(Str::headline($status).' Agent Chat Project', "{$status}-agent-chat-project", $status);

    $this->actingAs($developer)
        ->postJson("/api/dashboard/projects/{$project['project_id']}/agent-chats", [
            'agent_key' => 'local_agent',
            'initial_message' => 'This project is not active.',
        ])
        ->assertConflict()
        ->assertJsonPath('error.code', 'project_not_active');
})->with(['archived', 'deleted']);

function agentChatDashboardApiUserWithRole(string $role): User
{
    $user = User::factory()->create(['status' => 'active']);
    $roleId = DB::table('roles')->where('name', $role)->value('id');

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
function agentChatDashboardApiProject(string $name, string $slug, string $status = 'active'): array
{
    $projectId = (string) Str::ulid();
    $repositoryId = (string) Str::ulid();
    $boardId = (string) Str::ulid();
    $adminId = (int) DB::table('users')->where('email', 'admin@example.com')->value('id');
    $now = now();
    $projectColumns = Schema::getColumnListing('projects');
    $projectRow = [
        'id' => $projectId,
        'name' => $name,
        'slug' => $slug,
        'description' => 'Project used by agent chat dashboard tests.',
        'status' => $status,
        'default_code_exposure_policy' => 'full_code_artifacts',
        'created_by_user_id' => $adminId,
        'created_at' => $now,
        'updated_at' => $now,
    ];

    foreach (['archived_at', 'archived_by_user_id', 'deleted_at', 'deleted_by_user_id', 'restored_at', 'restored_by_user_id'] as $column) {
        if (! in_array($column, $projectColumns, true)) {
            continue;
        }

        $projectRow[$column] = match ($column) {
            'archived_at' => $status === 'archived' ? $now : null,
            'archived_by_user_id' => $status === 'archived' ? $adminId : null,
            'deleted_at' => $status === 'deleted' ? $now : null,
            'deleted_by_user_id' => $status === 'deleted' ? $adminId : null,
            default => null,
        };
    }

    DB::table('projects')->insert($projectRow);

    DB::table('repositories')->insert([
        'id' => $repositoryId,
        'project_id' => $projectId,
        'name' => "{$slug}-repository",
        'slug' => "{$slug}-repository",
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

    DB::table('kanban_boards')->insert([
        'id' => $boardId,
        'project_id' => $projectId,
        'name' => 'Default Board',
        'is_default' => true,
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    foreach (['backlog', 'ready', 'in_progress', 'blocked', 'review', 'done'] as $position => $statusKey) {
        DB::table('kanban_columns')->insert([
            'id' => (string) Str::ulid(),
            'board_id' => $boardId,
            'name' => Str::headline($statusKey),
            'position' => $position + 1,
            'status_key' => $statusKey,
            'wip_limit' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    return [
        'project_id' => $projectId,
        'repository_id' => $repositoryId,
    ];
}

function agentChatDashboardApiTask(string $projectId): string
{
    $taskId = (string) Str::ulid();
    $adminId = (int) DB::table('users')->where('email', 'admin@example.com')->value('id');
    $columnId = (string) DB::table('kanban_columns')
        ->join('kanban_boards', 'kanban_boards.id', '=', 'kanban_columns.board_id')
        ->where('kanban_boards.project_id', $projectId)
        ->where('kanban_boards.is_default', true)
        ->where('kanban_columns.status_key', 'ready')
        ->value('kanban_columns.id');

    DB::table('tasks')->insert([
        'id' => $taskId,
        'project_id' => $projectId,
        'title' => 'Agent chat linked task',
        'description' => 'Task referenced by an agent chat thread.',
        'acceptance_criteria' => json_encode(['Agent chat references this task.'], JSON_THROW_ON_ERROR),
        'status_column_id' => $columnId,
        'priority' => 'normal',
        'risk_level' => 'medium',
        'owner_user_id' => null,
        'created_by_user_id' => $adminId,
        'due_at' => null,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    return $taskId;
}

function agentChatDashboardApiConfigureProvider(): void
{
    $providerId = (string) DB::table('ai_model_providers')->where('provider_key', 'openai')->value('id');

    DB::table('ai_model_providers')->where('id', $providerId)->update([
        'base_url' => 'https://opencode.ai/zen/go/v1/chat/completions',
        'encrypted_api_key' => Crypt::encryptString('sk-opencode-test'),
        'api_key_last_four' => 'test',
        'enabled' => true,
        'updated_at' => now(),
    ]);

    DB::table('ai_model_profiles')->where('profile_key', 'openai_default_text')->update([
        'provider_id' => $providerId,
        'model_name' => 'deepseek-v4-flash',
        'max_output_tokens' => 1024,
        'timeout_seconds' => 30,
        'enabled' => true,
        'updated_at' => now(),
    ]);
}

function agentChatDashboardApiProjectScopedAgent(string $agentKey, string $displayName, string $projectId): string
{
    $agentProfileId = (string) Str::ulid();
    $modelProfileId = (string) DB::table('ai_model_profiles')->where('profile_key', 'openai_default_text')->value('id');
    $now = now();

    DB::table('ai_agent_profiles')->insert([
        'id' => $agentProfileId,
        'agent_key' => $agentKey,
        'display_name' => $displayName,
        'description' => "{$displayName} is limited to one project.",
        'agent_type' => 'specialist',
        'delegation_mode' => 'controlled_registry',
        'parent_agent_key' => null,
        'default_model_profile_id' => $modelProfileId,
        'requires_human_approval' => true,
        'enabled' => true,
        'allowed_tools' => json_encode(['search_project_memory'], JSON_THROW_ON_ERROR),
        'output_schema' => json_encode(['type' => 'object'], JSON_THROW_ON_ERROR),
        'trigger_events' => json_encode(['manual_chat'], JSON_THROW_ON_ERROR),
        'visibility_scope' => 'project',
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    DB::table('ai_agent_project_visibility')->insert([
        'id' => (string) Str::ulid(),
        'ai_agent_profile_id' => $agentProfileId,
        'project_id' => $projectId,
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    return $agentProfileId;
}
