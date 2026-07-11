<?php

use App\Assistants\ProviderHostResolver;
use App\Models\User;
use App\Services\ServerAgentWorkService;
use Database\Seeders\DevBoardSeeder;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(DevBoardSeeder::class);
    $this->app->singleton(ProviderHostResolver::class, function () {
        return new AgentWorkFakeHostResolver(['8.8.8.8'], [
            'ssrf-agent-work.example.test' => ['10.0.0.5'],
        ]);
    });
});

it('lets a developer create and list local agent work with repository and task scope', function () {
    $developer = agentWorkDashboardApiUserWithRole('Developer');
    $projectId = (string) DB::table('projects')->where('slug', 'demo-project')->value('id');
    $repositoryId = (string) DB::table('repositories')->where('project_id', $projectId)->value('id');
    $taskId = agentWorkDashboardApiTask($projectId);

    $workItem = $this->actingAs($developer)
        ->postJson("/api/dashboard/projects/{$projectId}/agent-work", [
            'assigned_agent_key' => 'local_agent',
            'priority' => 'high',
            'title' => 'Inspect task before implementation',
            'prompt' => 'Read shared memory and report conflicts before changing code.',
            'repository_id' => $repositoryId,
            'task_id' => $taskId,
            'payload' => [
                'request' => 'Preflight sync for this task.',
                'expected_output' => 'Conflicts or a clear ready signal.',
            ],
            'requires_memory_entry' => false,
        ])
        ->assertCreated()
        ->assertJsonStructure([
            'id',
            'project_id',
            'repository_id',
            'task_id',
            'requested_by_user_id',
            'assigned_agent_key',
            'status',
            'priority',
            'title',
            'prompt',
            'payload',
            'requires_memory_entry',
            'result_memory_entry_id',
            'claimed_by_device_id',
            'claimed_at',
            'heartbeat_at',
            'completed_at',
            'failed_at',
            'canceled_at',
            'failure_reason',
            'created_at',
            'updated_at',
        ])
        ->assertJsonPath('project_id', $projectId)
        ->assertJsonPath('repository_id', $repositoryId)
        ->assertJsonPath('task_id', $taskId)
        ->assertJsonPath('requested_by_user_id', $developer->id)
        ->assertJsonPath('assigned_agent_key', 'local_agent')
        ->assertJsonPath('status', 'queued')
        ->assertJsonPath('priority', 'high')
        ->assertJsonPath('payload.request', 'Preflight sync for this task.')
        ->assertJsonPath('requires_memory_entry', false)
        ->json();

    expect(DB::table('agent_work_items')->where('id', $workItem['id'])->value('status'))->toBe('queued')
        ->and(DB::table('agent_work_items')->where('id', $workItem['id'])->value('requested_by_user_id'))->toBe($developer->id)
        ->and(DB::table('agent_work_item_events')->where('agent_work_item_id', $workItem['id'])->where('event_type', 'queued')->exists())->toBeTrue();

    $this->actingAs($developer)
        ->getJson("/api/dashboard/projects/{$projectId}/agent-work")
        ->assertOk()
        ->assertJsonPath('items.0.id', $workItem['id'])
        ->assertJsonPath('items.0.title', 'Inspect task before implementation')
        ->assertJsonPath('items.0.repository_id', $repositoryId)
        ->assertJsonPath('items.0.task_id', $taskId);
});

it('runs socrates work through the configured OpenAI compatible provider and stores the answer as memory', function () {
    Http::fake([
        'https://opencode.ai/zen/go/v1/chat/completions' => Http::response([
            'choices' => [
                [
                    'message' => [
                        'content' => 'Il progetto contiene una repository locale e manca una wiki aggiornata con runbook e architettura.',
                    ],
                ],
            ],
        ]),
    ]);

    $developer = agentWorkDashboardApiUserWithRole('Developer');
    $projectId = (string) DB::table('projects')->where('slug', 'demo-project')->value('id');
    $memoryId = agentWorkDashboardApiProjectMemory($projectId, 'Existing checkout memory for Socrates.');
    agentWorkDashboardApiConfigureSocratesProvider();

    $workItem = $this->actingAs($developer)
        ->postJson("/api/dashboard/projects/{$projectId}/agent-work", [
            'assigned_agent_key' => 'socrates',
            'priority' => 'normal',
            'title' => 'Ask Socrates for project context',
            'prompt' => 'Dimmi cosa sai sul progetto e cosa ti serve sapere.',
            'payload' => [
                'source' => 'ask_page',
                'question' => 'Dimmi cosa sai sul progetto e cosa ti serve sapere.',
            ],
        ])
        ->assertCreated()
        ->assertJsonPath('assigned_agent_key', 'socrates')
        ->assertJsonPath('status', 'completed')
        ->json();

    $memoryEntry = DB::table('project_memory_entries')->where('id', $workItem['result_memory_entry_id'])->first();
    expect($memoryEntry)->not->toBeNull();
    $memoryPayload = json_decode((string) $memoryEntry->payload, true, flags: JSON_THROW_ON_ERROR);

    expect($memoryEntry->project_id)->toBe($projectId)
        ->and($memoryEntry->agent_key)->toBe('socrates')
        ->and($memoryEntry->source)->toBe('server_agent')
        ->and($memoryEntry->kind)->toBe('agent_note')
        ->and($memoryPayload['answer'])->toContain('wiki aggiornata')
        ->and($memoryPayload['memory_search_status']['status'])->toBe('available')
        ->and($memoryPayload['memory_search_status']['refs'][0]['id'])->toBe($memoryId)
        ->and($memoryPayload['context_counts']['memory_refs'])->toBe(1)
        ->and(json_decode((string) DB::table('assistant_runs')->where('target_id', $workItem['id'])->value('metadata'), true, flags: JSON_THROW_ON_ERROR)['memory_search_status']['refs'][0]['id'])->toBe($memoryId)
        ->and(DB::table('agent_work_item_events')->where('agent_work_item_id', $workItem['id'])->where('event_type', 'running')->exists())->toBeTrue()
        ->and(DB::table('agent_work_item_events')->where('agent_work_item_id', $workItem['id'])->where('event_type', 'completed')->exists())->toBeTrue();

    Http::assertSent(fn ($request): bool => $request->url() === 'https://opencode.ai/zen/go/v1/chat/completions'
        && $request['model'] === 'deepseek-v4-flash'
        && str_contains($request->body(), 'Dimmi cosa sai sul progetto')
        && str_contains($request->body(), 'memory_search_status')
        && str_contains($request->body(), $memoryId)
        && $request->hasHeader('Authorization', 'Bearer sk-opencode-test'));
});

it('runs a custom enabled backend agent profile through agent work', function () {
    Http::fake([
        'https://opencode.ai/zen/go/v1/chat/completions' => Http::response([
            'choices' => [['message' => ['content' => 'Custom reviewer answer.']]],
        ]),
    ]);

    $developer = agentWorkDashboardApiUserWithRole('Developer');
    $projectId = (string) DB::table('projects')->where('slug', 'demo-project')->value('id');
    agentWorkDashboardApiConfigureSocratesProvider();

    DB::table('ai_agent_profiles')->insert([
        'id' => (string) Str::ulid(),
        'agent_key' => 'design_reviewer',
        'display_name' => 'Design Reviewer',
        'description' => 'Reviews product and architecture decisions from DevBoard context.',
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

    $workItem = $this->actingAs($developer)
        ->postJson("/api/dashboard/projects/{$projectId}/agent-work", [
            'assigned_agent_key' => 'design_reviewer',
            'title' => 'Ask custom reviewer',
            'prompt' => 'Valuta il contesto disponibile e rispondi in modo sintetico.',
        ])
        ->assertCreated()
        ->assertJsonPath('assigned_agent_key', 'design_reviewer')
        ->assertJsonPath('status', 'completed')
        ->json();

    expect(DB::table('project_memory_entries')->where('id', $workItem['result_memory_entry_id'])->value('agent_key'))
        ->toBe('design_reviewer');

    Http::assertSent(fn ($request): bool => $request->url() === 'https://opencode.ai/zen/go/v1/chat/completions'
        && str_contains($request->body(), 'Design Reviewer (design_reviewer)'));
});

it('rejects project-scoped agent work for projects without visibility', function () {
    $developer = agentWorkDashboardApiUserWithRole('Developer');
    $projectA = (string) DB::table('projects')->where('slug', 'demo-project')->value('id');
    $projectB = agentWorkDashboardApiProject('Project Scoped Agent Work Project', 'project-scoped-agent-work-project');

    agentWorkDashboardApiProjectScopedAgent('project_limited_worker', 'Project Limited Worker', $projectA);

    $this->actingAs($developer)
        ->postJson("/api/dashboard/projects/{$projectB['project_id']}/agent-work", [
            'assigned_agent_key' => 'project_limited_worker',
            'title' => 'Scoped project work',
            'prompt' => 'This work should be blocked when the agent is not visible.',
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['assigned_agent_key']);
});

it('rejects unknown dashboard agent work keys', function () {
    $developer = agentWorkDashboardApiUserWithRole('Developer');
    $projectId = (string) DB::table('projects')->where('slug', 'demo-project')->value('id');

    $this->actingAs($developer)
        ->postJson("/api/dashboard/projects/{$projectId}/agent-work", [
            'assigned_agent_key' => 'missing_agent',
            'title' => 'Unknown agent',
            'prompt' => 'This should not queue.',
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['assigned_agent_key']);
});

it('fails socrates work closed without dispatching when the provider base URL is unsafe', function () {
    Http::preventStrayRequests();
    Http::fake(fn () => Http::response('should not be called', 200));

    $providerId = (string) DB::table('ai_model_providers')->where('provider_key', 'openai')->value('id');

    DB::table('ai_model_providers')->where('id', $providerId)->update([
        'base_url' => 'https://ssrf-agent-work.example.test/v1',
        'encrypted_api_key' => Crypt::encryptString('sk-opencode-test'),
        'api_key_last_four' => 'test',
        'enabled' => true,
    ]);
    DB::table('ai_model_profiles')->where('profile_key', 'openai_default_text')->update([
        'provider_id' => $providerId,
        'model_name' => 'deepseek-v4-flash',
        'max_output_tokens' => 1024,
        'timeout_seconds' => 30,
        'enabled' => true,
    ]);

    $developer = agentWorkDashboardApiUserWithRole('Developer');
    $projectId = (string) DB::table('projects')->where('slug', 'demo-project')->value('id');

    $workItem = $this->actingAs($developer)
        ->postJson("/api/dashboard/projects/{$projectId}/agent-work", [
            'assigned_agent_key' => 'socrates',
            'title' => 'Unsafe endpoint work',
            'prompt' => 'This must fail without calling the unsafe provider.',
        ])
        ->assertCreated()
        ->assertJsonPath('status', 'failed')
        ->json();

    expect($workItem['failure_reason'])->toBe('Provider endpoint URL is not allowed.')
        ->and($workItem['result_memory_entry_id'])->toBeNull();

    Http::assertNothingSent();
});

it('fails socrates work visibly when the configured provider rejects the request', function () {
    Http::fake([
        'https://opencode.ai/zen/go/v1/chat/completions' => Http::response(['error' => ['message' => 'unauthorized']], 401),
    ]);

    $developer = agentWorkDashboardApiUserWithRole('Developer');
    $projectId = (string) DB::table('projects')->where('slug', 'demo-project')->value('id');
    agentWorkDashboardApiConfigureSocratesProvider();

    $workItem = $this->actingAs($developer)
        ->postJson("/api/dashboard/projects/{$projectId}/agent-work", [
            'assigned_agent_key' => 'socrates',
            'title' => 'Ask Socrates with bad key',
            'prompt' => 'Dimmi cosa sai sul progetto e cosa ti serve sapere.',
        ])
        ->assertCreated()
        ->assertJsonPath('status', 'failed')
        ->json();

    expect($workItem['failure_reason'])->toBe('AI provider returned HTTP 401.')
        ->and($workItem['result_memory_entry_id'])->toBeNull()
        ->and(DB::table('project_memory_entries')->where('project_id', $projectId)->where('agent_key', 'socrates')->exists())->toBeFalse()
        ->and(DB::table('agent_work_item_events')->where('agent_work_item_id', $workItem['id'])->where('event_type', 'failed')->exists())->toBeTrue();
});

it('fails project-scoped agent work execution when visibility is missing', function () {
    Http::fake([
        'https://opencode.ai/zen/go/v1/chat/completions' => Http::response([
            'choices' => [['message' => ['content' => 'This should not be returned.']]],
        ]),
    ]);

    $projectA = (string) DB::table('projects')->where('slug', 'demo-project')->value('id');
    $projectB = agentWorkDashboardApiProject('Project Scoped Agent Work Execution Project', 'project-scoped-agent-work-execution-project');

    agentWorkDashboardApiConfigureSocratesProvider();
    agentWorkDashboardApiProjectScopedAgent('project_limited_worker', 'Project Limited Worker', $projectA);

    $workItemId = agentWorkDashboardApiWorkItem($projectB['project_id'], [
        'assigned_agent_key' => 'project_limited_worker',
        'title' => 'Scoped execution work',
        'prompt' => 'This work should fail when executed outside the visibility project.',
    ]);

    app(ServerAgentWorkService::class)->process($workItemId);

    expect(DB::table('agent_work_items')->where('id', $workItemId)->value('status'))->toBe('failed')
        ->and((string) DB::table('agent_work_items')->where('id', $workItemId)->value('failure_reason'))->toContain('not visible');
});

it('runs platon and aristoteles work through controlled backend agent profiles', function (string $agentKey, string $answer) {
    Http::fake([
        'https://opencode.ai/zen/go/v1/chat/completions' => Http::response([
            'choices' => [
                [
                    'message' => ['content' => $answer],
                ],
            ],
        ]),
    ]);

    $developer = agentWorkDashboardApiUserWithRole('Developer');
    $projectId = (string) DB::table('projects')->where('slug', 'demo-project')->value('id');
    agentWorkDashboardApiConfigureSocratesProvider();

    $workItem = $this->actingAs($developer)
        ->postJson("/api/dashboard/projects/{$projectId}/agent-work", [
            'assigned_agent_key' => $agentKey,
            'title' => "Ask {$agentKey} for help",
            'prompt' => 'Rispondi usando solo il contesto DevBoard disponibile.',
        ])
        ->assertCreated()
        ->assertJsonPath('assigned_agent_key', $agentKey)
        ->assertJsonPath('status', 'completed')
        ->json();

    $memoryPayload = json_decode(
        (string) DB::table('project_memory_entries')->where('id', $workItem['result_memory_entry_id'])->value('payload'),
        true,
        flags: JSON_THROW_ON_ERROR,
    );

    expect($memoryPayload['answer'])->toBe($answer)
        ->and(DB::table('project_memory_entries')->where('id', $workItem['result_memory_entry_id'])->value('agent_key'))->toBe($agentKey)
        ->and(DB::table('assistant_runs')->where('target_type', 'agent_work_item')->where('target_id', $workItem['id'])->exists())->toBeTrue()
        ->and(DB::table('assistant_messages')->where('role', 'user')->where('content', 'Rispondi usando solo il contesto DevBoard disponibile.')->exists())->toBeTrue()
        ->and(DB::table('assistant_messages')->where('role', 'assistant')->where('content', $answer)->exists())->toBeTrue();
})->with([
    ['platon', 'Platon vede una task poco chiara e propone criteri di accettazione.'],
    ['aristoteles', 'Aristoteles vede un collo di bottiglia nella coda agent-work.'],
]);

it('returns agent work detail with events result memory and persisted chat messages', function () {
    $developer = agentWorkDashboardApiUserWithRole('Developer');
    $projectId = (string) DB::table('projects')->where('slug', 'demo-project')->value('id');
    $workItemId = agentWorkDashboardApiWorkItem($projectId, [
        'assigned_agent_key' => 'socrates',
        'status' => 'completed',
        'completed_at' => now(),
    ]);
    $memoryId = (string) Str::ulid();
    $runId = (string) Str::ulid();
    $now = now();

    DB::table('project_memory_entries')->insert([
        'id' => $memoryId,
        'project_id' => $projectId,
        'repository_id' => null,
        'task_id' => null,
        'run_id' => null,
        'author_user_id' => null,
        'agent_key' => 'socrates',
        'source' => 'server_agent',
        'kind' => 'agent_note',
        'completeness' => 'complete',
        'summary' => 'Socrates answered from the persisted chat.',
        'payload' => json_encode(['answer' => 'Persistent answer'], JSON_THROW_ON_ERROR),
        'occurred_at' => $now,
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    DB::table('agent_work_items')->where('id', $workItemId)->update([
        'result_memory_entry_id' => $memoryId,
        'updated_at' => $now,
    ]);

    DB::table('agent_work_item_events')->insert([
        'id' => (string) Str::ulid(),
        'agent_work_item_id' => $workItemId,
        'actor_user_id' => $developer->id,
        'actor_device_id' => null,
        'event_type' => 'queued',
        'message' => 'Dashboard user queued work for an agent.',
        'payload' => json_encode([], JSON_THROW_ON_ERROR),
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    DB::table('assistant_runs')->insert([
        'id' => $runId,
        'project_id' => $projectId,
        'agent_profile_id' => DB::table('ai_agent_profiles')->where('agent_key', 'socrate_supervisor')->value('id'),
        'target_type' => 'agent_work_item',
        'target_id' => $workItemId,
        'triggered_by_user_id' => $developer->id,
        'status' => 'completed',
        'model_provider_id' => null,
        'model_profile_id' => null,
        'context_hash' => hash('sha256', 'agent-work-detail'),
        'context_snapshot' => json_encode(['project_id' => $projectId], JSON_THROW_ON_ERROR),
        'result_summary' => 'Persistent answer',
        'metadata' => json_encode(['agent_key' => 'socrates'], JSON_THROW_ON_ERROR),
        'started_at' => $now,
        'finished_at' => $now,
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    foreach ([['user', 'Question for Socrates'], ['assistant', 'Persistent answer']] as [$role, $content]) {
        DB::table('assistant_messages')->insert([
            'id' => (string) Str::ulid(),
            'assistant_run_id' => $runId,
            'role' => $role,
            'content' => $content,
            'metadata' => json_encode([], JSON_THROW_ON_ERROR),
            'created_at' => $now,
        ]);
    }

    $this->actingAs($developer)
        ->getJson("/api/dashboard/projects/{$projectId}/agent-work/{$workItemId}")
        ->assertOk()
        ->assertJsonPath('item.id', $workItemId)
        ->assertJsonPath('item.result_memory_entry.id', $memoryId)
        ->assertJsonPath('item.result_memory_entry.domain', 'agent_notes')
        ->assertJsonPath('item.chat.messages.0.role', 'user')
        ->assertJsonPath('item.chat.messages.1.content', 'Persistent answer')
        ->assertJsonPath('item.events.0.event_type', 'queued');
});

it('lets pm cancel queued work before the local agent claims it', function () {
    $pm = agentWorkDashboardApiUserWithRole('PM');
    $projectId = (string) DB::table('projects')->where('slug', 'demo-project')->value('id');
    $workItemId = agentWorkDashboardApiWorkItem($projectId);

    $this->actingAs($pm)
        ->postJson("/api/dashboard/agent-work/{$workItemId}/cancel", [
            'message' => 'The task was rewritten.',
        ])
        ->assertOk()
        ->assertJsonPath('id', $workItemId)
        ->assertJsonPath('status', 'canceled');

    expect(DB::table('agent_work_items')->where('id', $workItemId)->value('canceled_at'))->not->toBeNull()
        ->and(DB::table('agent_work_item_events')->where('agent_work_item_id', $workItemId)->where('event_type', 'canceled')->value('message'))
        ->toBe('The task was rewritten.');
});

it('archives completed work and hides it from the default project list', function () {
    $pm = agentWorkDashboardApiUserWithRole('PM');
    $projectId = (string) DB::table('projects')->where('slug', 'demo-project')->value('id');
    $workItemId = agentWorkDashboardApiWorkItem($projectId, [
        'status' => 'completed',
        'completed_at' => now()->subMinute(),
        'title' => 'Completed work to archive',
    ]);

    $this->actingAs($pm)
        ->deleteJson("/api/dashboard/agent-work/{$workItemId}", [
            'message' => 'No longer needed in the active queue.',
        ])
        ->assertOk()
        ->assertJsonPath('id', $workItemId)
        ->assertJsonPath('status', 'completed')
        ->assertJsonPath('archive_reason', 'No longer needed in the active queue.');

    expect(DB::table('agent_work_items')->where('id', $workItemId)->value('archived_at'))->not->toBeNull()
        ->and(DB::table('agent_work_item_events')->where('agent_work_item_id', $workItemId)->where('event_type', 'archived')->value('message'))
        ->toBe('No longer needed in the active queue.');

    $items = $this->actingAs($pm)
        ->getJson("/api/dashboard/projects/{$projectId}/agent-work")
        ->assertOk()
        ->json('items');

    expect(collect($items)->pluck('id')->all())->not->toContain($workItemId);

    $this->actingAs($pm)
        ->getJson("/api/dashboard/projects/{$projectId}/agent-work/{$workItemId}")
        ->assertOk()
        ->assertJsonPath('item.id', $workItemId)
        ->assertJsonPath('item.archived_by_user_id', $pm->id);
});

it('cancels and archives queued unclaimed work in one delete action', function () {
    $pm = agentWorkDashboardApiUserWithRole('PM');
    $projectId = (string) DB::table('projects')->where('slug', 'demo-project')->value('id');
    $workItemId = agentWorkDashboardApiWorkItem($projectId, ['title' => 'Queued work to remove']);

    $this->actingAs($pm)
        ->deleteJson("/api/dashboard/agent-work/{$workItemId}", [
            'message' => 'Superseded by a newer request.',
        ])
        ->assertOk()
        ->assertJsonPath('id', $workItemId)
        ->assertJsonPath('status', 'canceled')
        ->assertJsonPath('archive_reason', 'Superseded by a newer request.');

    expect(DB::table('agent_work_items')->where('id', $workItemId)->value('canceled_at'))->not->toBeNull()
        ->and(DB::table('agent_work_items')->where('id', $workItemId)->value('archived_at'))->not->toBeNull()
        ->and(DB::table('agent_work_item_events')->where('agent_work_item_id', $workItemId)->where('event_type', 'canceled')->exists())->toBeTrue()
        ->and(DB::table('agent_work_item_events')->where('agent_work_item_id', $workItemId)->where('event_type', 'archived')->exists())->toBeTrue();
});

it('does not archive claimed or running work from the dashboard', function () {
    $developer = agentWorkDashboardApiUserWithRole('Developer');
    $projectId = (string) DB::table('projects')->where('slug', 'demo-project')->value('id');
    $deviceId = agentWorkDashboardApiDevice();
    $workItemId = agentWorkDashboardApiWorkItem($projectId, [
        'status' => 'running',
        'claimed_by_device_id' => $deviceId,
        'claimed_at' => now(),
        'heartbeat_at' => now(),
    ]);

    $this->actingAs($developer)
        ->deleteJson("/api/dashboard/agent-work/{$workItemId}")
        ->assertConflict();

    expect(DB::table('agent_work_items')->where('id', $workItemId)->value('archived_at'))->toBeNull();
});

it('allows sysadmin to read but not create or cancel agent work', function () {
    $sysadmin = agentWorkDashboardApiUserWithRole('Sysadmin');
    $projectId = (string) DB::table('projects')->where('slug', 'demo-project')->value('id');
    $workItemId = agentWorkDashboardApiWorkItem($projectId, ['title' => 'Sysadmin readable work']);

    $this->actingAs($sysadmin)
        ->getJson("/api/dashboard/projects/{$projectId}/agent-work")
        ->assertOk()
        ->assertJsonPath('items.0.id', $workItemId);

    $this->actingAs($sysadmin)
        ->postJson("/api/dashboard/projects/{$projectId}/agent-work", [
            'assigned_agent_key' => 'local_agent',
            'title' => 'Sysadmin cannot create',
            'prompt' => 'Sysadmin has read access only.',
        ])
        ->assertForbidden();

    $this->actingAs($sysadmin)
        ->postJson("/api/dashboard/agent-work/{$workItemId}/cancel")
        ->assertForbidden();

    $this->actingAs($sysadmin)
        ->deleteJson("/api/dashboard/agent-work/{$workItemId}")
        ->assertForbidden();

    expect(DB::table('agent_work_items')->where('id', $workItemId)->value('status'))->toBe('queued');
});

it('rejects repository and task references from another project', function () {
    $developer = agentWorkDashboardApiUserWithRole('Developer');
    $projectId = (string) DB::table('projects')->where('slug', 'demo-project')->value('id');
    $other = agentWorkDashboardApiProject('Other Agent Work Project', 'other-agent-work-project');
    $otherTaskId = agentWorkDashboardApiTask($other['project_id']);

    $this->actingAs($developer)
        ->postJson("/api/dashboard/projects/{$projectId}/agent-work", [
            'assigned_agent_key' => 'local_agent',
            'priority' => 'normal',
            'title' => 'Cross project work references',
            'prompt' => 'This payload references another project and must be rejected.',
            'repository_id' => $other['repository_id'],
            'task_id' => $otherTaskId,
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['repository_id', 'task_id']);
});

it('blocks writes to archived and deleted projects', function (string $status) {
    $developer = agentWorkDashboardApiUserWithRole('Developer');
    $project = agentWorkDashboardApiProject(Str::headline($status).' Agent Work Project', "{$status}-agent-work-project", $status);
    $workItemId = agentWorkDashboardApiWorkItem($project['project_id']);

    $this->actingAs($developer)
        ->postJson("/api/dashboard/projects/{$project['project_id']}/agent-work", [
            'assigned_agent_key' => 'local_agent',
            'title' => 'Lifecycle blocked work',
            'prompt' => 'This write should be blocked by lifecycle policy.',
        ])
        ->assertConflict()
        ->assertJsonPath('error.code', 'project_not_active');

    $this->actingAs($developer)
        ->postJson("/api/dashboard/agent-work/{$workItemId}/cancel")
        ->assertConflict()
        ->assertJsonPath('error.code', 'project_not_active');
})->with(['archived', 'deleted']);

it('does not cancel completed work', function () {
    $developer = agentWorkDashboardApiUserWithRole('Developer');
    $projectId = (string) DB::table('projects')->where('slug', 'demo-project')->value('id');
    $workItemId = agentWorkDashboardApiWorkItem($projectId, [
        'status' => 'completed',
        'completed_at' => now(),
    ]);

    $this->actingAs($developer)
        ->postJson("/api/dashboard/agent-work/{$workItemId}/cancel")
        ->assertConflict();

    expect(DB::table('agent_work_items')->where('id', $workItemId)->value('status'))->toBe('completed')
        ->and(DB::table('agent_work_items')->where('id', $workItemId)->value('canceled_at'))->toBeNull()
        ->and(DB::table('agent_work_item_events')->where('agent_work_item_id', $workItemId)->where('event_type', 'canceled')->exists())->toBeFalse();
});

it('does not cancel failed work', function () {
    $developer = agentWorkDashboardApiUserWithRole('Developer');
    $projectId = (string) DB::table('projects')->where('slug', 'demo-project')->value('id');
    $failedAt = now()->subMinute();
    $workItemId = agentWorkDashboardApiWorkItem($projectId, [
        'status' => 'failed',
        'failed_at' => $failedAt,
        'failure_reason' => 'The agent run failed.',
    ]);

    $this->actingAs($developer)
        ->postJson("/api/dashboard/agent-work/{$workItemId}/cancel")
        ->assertConflict();

    expect(DB::table('agent_work_items')->where('id', $workItemId)->value('status'))->toBe('failed')
        ->and(DB::table('agent_work_items')->where('id', $workItemId)->value('failed_at'))->not->toBeNull()
        ->and(DB::table('agent_work_items')->where('id', $workItemId)->value('canceled_at'))->toBeNull()
        ->and(DB::table('agent_work_item_events')->where('agent_work_item_id', $workItemId)->where('event_type', 'canceled')->exists())->toBeFalse();
});

it('does not cancel already canceled work again', function () {
    $developer = agentWorkDashboardApiUserWithRole('Developer');
    $projectId = (string) DB::table('projects')->where('slug', 'demo-project')->value('id');
    $canceledAt = now()->subMinute();
    $workItemId = agentWorkDashboardApiWorkItem($projectId, [
        'status' => 'canceled',
        'canceled_at' => $canceledAt,
    ]);
    $storedCanceledAt = DB::table('agent_work_items')->where('id', $workItemId)->value('canceled_at');

    DB::table('agent_work_item_events')->insert([
        'id' => (string) Str::ulid(),
        'agent_work_item_id' => $workItemId,
        'actor_user_id' => $developer->id,
        'actor_device_id' => null,
        'event_type' => 'canceled',
        'message' => 'Already canceled.',
        'payload' => json_encode([], JSON_THROW_ON_ERROR),
        'created_at' => $canceledAt,
        'updated_at' => $canceledAt,
    ]);

    $this->actingAs($developer)
        ->postJson("/api/dashboard/agent-work/{$workItemId}/cancel", [
            'message' => 'Cancel again.',
        ])
        ->assertConflict();

    expect(DB::table('agent_work_items')->where('id', $workItemId)->value('status'))->toBe('canceled')
        ->and(DB::table('agent_work_items')->where('id', $workItemId)->value('canceled_at'))->toBe($storedCanceledAt)
        ->and(DB::table('agent_work_item_events')->where('agent_work_item_id', $workItemId)->where('event_type', 'canceled')->count())->toBe(1)
        ->and(DB::table('agent_work_item_events')->where('agent_work_item_id', $workItemId)->where('event_type', 'canceled')->value('message'))->toBe('Already canceled.');
});

it('blocks cancellation after work has been claimed', function () {
    $developer = agentWorkDashboardApiUserWithRole('Developer');
    $projectId = (string) DB::table('projects')->where('slug', 'demo-project')->value('id');
    $deviceId = agentWorkDashboardApiDevice();
    $workItemId = agentWorkDashboardApiWorkItem($projectId, [
        'status' => 'claimed',
        'claimed_by_device_id' => $deviceId,
        'claimed_at' => now(),
    ]);

    $this->actingAs($developer)
        ->postJson("/api/dashboard/agent-work/{$workItemId}/cancel")
        ->assertConflict();

    expect(DB::table('agent_work_items')->where('id', $workItemId)->value('status'))->toBe('claimed')
        ->and(DB::table('agent_work_item_events')->where('agent_work_item_id', $workItemId)->where('event_type', 'canceled')->exists())->toBeFalse();
});

it('does not cancel work that becomes claimed after the initial cancel read', function () {
    $developer = agentWorkDashboardApiUserWithRole('Developer');
    $projectId = (string) DB::table('projects')->where('slug', 'demo-project')->value('id');
    $deviceId = agentWorkDashboardApiDevice();
    $workItemId = agentWorkDashboardApiWorkItem($projectId);
    $claimedDuringCancel = false;

    DB::listen(function (QueryExecuted $query) use (&$claimedDuringCancel, $workItemId, $deviceId): void {
        if ($claimedDuringCancel || ! str_contains($query->sql, 'from "agent_work_items"')) {
            return;
        }

        if (($query->bindings[0] ?? null) !== $workItemId) {
            return;
        }

        $claimedDuringCancel = true;

        DB::table('agent_work_items')->where('id', $workItemId)->update([
            'status' => 'claimed',
            'claimed_by_device_id' => $deviceId,
            'claimed_at' => now(),
            'updated_at' => now(),
        ]);
    });

    $this->actingAs($developer)
        ->postJson("/api/dashboard/agent-work/{$workItemId}/cancel")
        ->assertConflict();

    expect($claimedDuringCancel)->toBeTrue()
        ->and(DB::table('agent_work_items')->where('id', $workItemId)->value('status'))->toBe('claimed')
        ->and(DB::table('agent_work_item_events')->where('agent_work_item_id', $workItemId)->where('event_type', 'canceled')->exists())->toBeFalse();
});

it('keeps agent work project scoped', function () {
    $developer = agentWorkDashboardApiUserWithRole('Developer');
    $primaryProjectId = (string) DB::table('projects')->where('slug', 'demo-project')->value('id');
    $secondary = agentWorkDashboardApiProject('Scoped Agent Work Project', 'scoped-agent-work-project');
    $primaryWorkItemId = agentWorkDashboardApiWorkItem($primaryProjectId, ['title' => 'Primary work item']);
    $secondaryWorkItemId = agentWorkDashboardApiWorkItem($secondary['project_id'], ['title' => 'Secondary work item']);

    $primaryItems = $this->actingAs($developer)
        ->getJson("/api/dashboard/projects/{$primaryProjectId}/agent-work")
        ->assertOk()
        ->json('items');

    $secondaryItems = $this->actingAs($developer)
        ->getJson("/api/dashboard/projects/{$secondary['project_id']}/agent-work")
        ->assertOk()
        ->json('items');

    expect(collect($primaryItems)->pluck('id')->all())->toContain($primaryWorkItemId)
        ->and(collect($primaryItems)->pluck('id')->all())->not->toContain($secondaryWorkItemId)
        ->and(collect($secondaryItems)->pluck('id')->all())->toContain($secondaryWorkItemId)
        ->and(collect($secondaryItems)->pluck('id')->all())->not->toContain($primaryWorkItemId);
});

it('orders project work by priority and limits the list', function () {
    $pm = agentWorkDashboardApiUserWithRole('PM');
    $projectId = (string) DB::table('projects')->where('slug', 'demo-project')->value('id');
    $oldLow = agentWorkDashboardApiWorkItem($projectId, [
        'priority' => 'low',
        'title' => 'Old low priority work',
        'created_at' => now()->subHours(3),
        'updated_at' => now()->subHours(3),
    ]);
    $high = agentWorkDashboardApiWorkItem($projectId, [
        'priority' => 'high',
        'title' => 'High priority work',
        'created_at' => now()->subHours(2),
        'updated_at' => now()->subHours(2),
    ]);
    $urgent = agentWorkDashboardApiWorkItem($projectId, [
        'priority' => 'urgent',
        'title' => 'Urgent priority work',
        'created_at' => now()->subHour(),
        'updated_at' => now()->subHour(),
    ]);

    foreach (range(1, 98) as $index) {
        agentWorkDashboardApiWorkItem($projectId, [
            'priority' => 'low',
            'title' => "Low priority work {$index}",
            'created_at' => now()->addMinutes($index),
            'updated_at' => now()->addMinutes($index),
        ]);
    }

    $items = $this->actingAs($pm)
        ->getJson("/api/dashboard/projects/{$projectId}/agent-work")
        ->assertOk()
        ->json('items');

    expect($items)->toHaveCount(100)
        ->and($items[0]['id'])->toBe($urgent)
        ->and($items[1]['id'])->toBe($high)
        ->and(collect($items)->pluck('id')->all())->not->toContain($oldLow);
});

function agentWorkDashboardApiUserWithRole(string $roleName, string $status = 'active'): User
{
    $user = User::factory()->create(['status' => $status]);
    $roleId = DB::table('roles')->where('name', $roleName)->value('id');

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
function agentWorkDashboardApiProject(string $name, string $slug, string $status = 'active'): array
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
        'description' => "{$name} description.",
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

function agentWorkDashboardApiTask(string $projectId): string
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
        'title' => 'Agent work linked task',
        'description' => 'Task referenced by an agent work item.',
        'acceptance_criteria' => json_encode(['Agent work references this task.'], JSON_THROW_ON_ERROR),
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

/**
 * @param  array<string, mixed>  $overrides
 */
function agentWorkDashboardApiWorkItem(string $projectId, array $overrides = []): string
{
    $workItemId = (string) Str::ulid();
    $adminId = (int) DB::table('users')->where('email', 'admin@example.com')->value('id');
    $now = now();

    DB::table('agent_work_items')->insert([
        ...[
            'id' => $workItemId,
            'project_id' => $projectId,
            'repository_id' => null,
            'task_id' => null,
            'requested_by_user_id' => $adminId,
            'assigned_agent_key' => 'local_agent',
            'status' => 'queued',
            'priority' => 'normal',
            'title' => 'Queued local agent work',
            'prompt' => 'Inspect this project workspace before implementation starts.',
            'payload' => json_encode(['request' => 'Inspect the project workspace.'], JSON_THROW_ON_ERROR),
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
        ],
        ...$overrides,
    ]);

    return $workItemId;
}

function agentWorkDashboardApiDevice(): string
{
    $deviceId = (string) Str::ulid();
    $adminId = (int) DB::table('users')->where('email', 'admin@example.com')->value('id');

    DB::table('devices')->insert([
        'id' => $deviceId,
        'user_id' => $adminId,
        'name' => 'Agent Work Dashboard Device',
        'fingerprint_hash' => 'sha256:'.$deviceId,
        'platform_os' => 'linux',
        'platform_arch' => 'amd64',
        'plugin_version' => '0.9.5',
        'last_seen_at' => now(),
        'status' => 'active',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    return $deviceId;
}

function agentWorkDashboardApiConfigureSocratesProvider(): void
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

function agentWorkDashboardApiProjectScopedAgent(string $agentKey, string $displayName, string $projectId): string
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

function agentWorkDashboardApiProjectMemory(string $projectId, string $summary): string
{
    $memoryId = (string) Str::ulid();
    $now = now();

    DB::table('project_memory_entries')->insert([
        'id' => $memoryId,
        'project_id' => $projectId,
        'repository_id' => null,
        'task_id' => null,
        'run_id' => null,
        'author_user_id' => null,
        'agent_key' => null,
        'source' => 'manual',
        'kind' => 'decision',
        'completeness' => 'complete',
        'summary' => $summary,
        'payload' => json_encode(['summary' => $summary], JSON_THROW_ON_ERROR),
        'occurred_at' => $now,
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    return $memoryId;
}

final class AgentWorkFakeHostResolver implements ProviderHostResolver
{
    /** @param list<string> $default @param array<string, list<string>> $overrides */
    public function __construct(private array $default, private array $overrides = []) {}

    public function resolve(string $host): array
    {
        return $this->overrides[strtolower($host)] ?? $this->default;
    }
}
