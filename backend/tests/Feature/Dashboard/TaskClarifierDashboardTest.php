<?php

use App\Assistants\Agents\TaskClarifierAgent;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->withoutVite();
    $this->seed(\Database\Seeders\DevBoardSeeder::class);
});

it('creates a structured task clarification suggestion without mutating the task', function () {
    $pm = taskClarifierUserWithRole('PM');
    $projectId = DB::table('projects')->where('slug', 'demo-project')->value('id');
    $taskId = taskClarifierCreateTask($projectId, $pm, [
        'title' => 'Fix onboarding',
        'description' => 'Make it better.',
        'priority' => 'normal',
        'risk_level' => 'low',
    ]);

    $response = $this->actingAs($pm)
        ->postJson("/api/dashboard/tasks/{$taskId}/assistant/clarify")
        ->assertCreated()
        ->assertJsonPath('run.status', 'completed')
        ->assertJsonPath('run.agent_key', 'task_clarifier')
        ->assertJsonPath('suggestion.suggestion_type', 'task_clarification')
        ->assertJsonPath('suggestion.status', 'pending')
        ->assertJsonPath('suggestion.approval_required', true)
        ->assertJsonPath('suggestion.structured_payload.questions.0', 'Which user or role needs this outcome?')
        ->assertJsonPath('suggestion.structured_payload.missing_context.0', 'acceptance_criteria')
        ->assertJsonPath('suggestion.evidence_refs.0.type', 'task');

    $runId = $response->json('run.id');
    $suggestionId = $response->json('suggestion.id');

    expect(DB::table('assistant_runs')->where('id', $runId)->where('target_id', $taskId)->exists())->toBeTrue()
        ->and(DB::table('assistant_messages')->where('assistant_run_id', $runId)->where('role', 'assistant')->exists())->toBeTrue()
        ->and(DB::table('assistant_suggestions')->where('id', $suggestionId)->where('assistant_run_id', $runId)->exists())->toBeTrue()
        ->and(DB::table('tasks')->where('id', $taskId)->value('title'))->toBe('Fix onboarding')
        ->and(DB::table('tasks')->where('id', $taskId)->value('description'))->toBe('Make it better.');
});

it('uses the Laravel AI SDK task clarifier agent when it is faked', function () {
    TaskClarifierAgent::fake([[
        'questions' => ['Which customer segment should see the onboarding copy?'],
        'acceptance_criteria' => ['The task names the customer segment and the exact copy surface.'],
        'risks' => ['The PM wording can still be interpreted as a general onboarding rewrite.'],
        'missing_context' => ['customer_segment', 'copy_surface'],
        'confidence' => 0.91,
    ]])->preventStrayPrompts();

    $pm = taskClarifierUserWithRole('PM');
    $projectId = DB::table('projects')->where('slug', 'demo-project')->value('id');
    $taskId = taskClarifierCreateTask($projectId, $pm, [
        'title' => 'Fix onboarding copy',
        'description' => 'Adjust the onboarding copy when a new workspace is created.',
        'priority' => 'high',
        'risk_level' => 'medium',
    ]);

    $response = $this->actingAs($pm)
        ->postJson("/api/dashboard/tasks/{$taskId}/assistant/clarify")
        ->assertCreated()
        ->assertJsonPath('suggestion.structured_payload.questions.0', 'Which customer segment should see the onboarding copy?')
        ->assertJsonPath('suggestion.structured_payload.acceptance_criteria.0', 'The task names the customer segment and the exact copy surface.')
        ->assertJsonPath('suggestion.structured_payload.missing_context.1', 'copy_surface')
        ->assertJsonPath('suggestion.confidence', 0.91);

    TaskClarifierAgent::assertPrompted(fn ($prompt): bool => $prompt->contains($taskId)
        && $prompt->contains('Fix onboarding copy')
        && $prompt->model === 'gpt-5.4');

    $metadata = json_decode(
        (string) DB::table('assistant_runs')->where('id', $response->json('run.id'))->value('metadata'),
        true,
        flags: JSON_THROW_ON_ERROR
    );

    expect($metadata['execution_mode'])->toBe('laravel_ai_sdk_fake')
        ->and($metadata['external_provider_call'])->toBeFalse()
        ->and($metadata['sdk_agent'])->toBe(TaskClarifierAgent::class);
});

it('falls back to deterministic clarification when the configured provider fails', function () {
    Http::fake(['*' => Http::response(['error' => ['message' => 'not found']], 404)]);

    $pm = taskClarifierUserWithRole('PM');
    $projectId = DB::table('projects')->where('slug', 'demo-project')->value('id');
    $taskId = taskClarifierCreateTask($projectId, $pm, [
        'title' => 'Fix dashboard status',
        'description' => 'Make it better.',
    ]);
    taskClarifierConfigureProvider();

    $response = $this->actingAs($pm)
        ->postJson("/api/dashboard/tasks/{$taskId}/assistant/clarify")
        ->assertCreated()
        ->assertJsonPath('run.status', 'completed')
        ->assertJsonPath('suggestion.structured_payload.questions.0', 'Which user or role needs this outcome?');

    $metadata = json_decode(
        (string) DB::table('assistant_runs')->where('id', $response->json('run.id'))->value('metadata'),
        true,
        flags: JSON_THROW_ON_ERROR
    );

    expect($metadata['execution_mode'])->toBe('provider_failed_fallback')
        ->and($metadata['external_provider_call'])->toBeTrue()
        ->and($metadata['provider_failure']['message'])->toContain('HTTP request returned status code 404');
});

it('shows the latest task clarification suggestion on the task page', function () {
    $pm = taskClarifierUserWithRole('PM');
    $projectId = DB::table('projects')->where('slug', 'demo-project')->value('id');
    $taskId = taskClarifierCreateTask($projectId, $pm, [
        'title' => 'Improve billing',
        'description' => '',
        'priority' => 'high',
        'risk_level' => 'medium',
    ]);

    $this->actingAs($pm)
        ->postJson("/api/dashboard/tasks/{$taskId}/assistant/clarify")
        ->assertCreated();

    $this->actingAs($pm)->get("/tasks/{$taskId}")
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Tasks/Show')
            ->where('task.id', $taskId)
            ->where('assistant.latest_suggestion.suggestion_type', 'task_clarification')
            ->where('assistant.latest_suggestion.status', 'pending')
            ->where('assistant.latest_suggestion.structured_payload.questions.0', 'Which user or role needs this outcome?')
            ->where('assistant.clarify_href', "/api/dashboard/tasks/{$taskId}/assistant/clarify")
            ->where('assistant.resolve_suggestion_href', '/api/dashboard/assistant-suggestions')
            ->where('assistant.apply_suggestion_href', '/api/dashboard/assistant-suggestions')
        );
});

it('lets PM and Admin resolve pending task clarification suggestions without mutating tasks', function () {
    $pm = taskClarifierUserWithRole('PM');
    $projectId = DB::table('projects')->where('slug', 'demo-project')->value('id');
    $taskId = taskClarifierCreateTask($projectId, $pm, [
        'title' => 'Clarify upload policy',
        'description' => 'Uploads need safer wording.',
    ]);

    $suggestionId = $this->actingAs($pm)
        ->postJson("/api/dashboard/tasks/{$taskId}/assistant/clarify")
        ->assertCreated()
        ->json('suggestion.id');

    $this->actingAs($pm)
        ->patchJson("/api/dashboard/assistant-suggestions/{$suggestionId}", [
            'status' => 'accepted',
        ])
        ->assertOk()
        ->assertJsonPath('suggestion.id', $suggestionId)
        ->assertJsonPath('suggestion.status', 'accepted')
        ->assertJsonPath('suggestion.resolved_by_user_id', (string) $pm->id);

    $stored = DB::table('assistant_suggestions')->where('id', $suggestionId)->first();

    expect((string) $stored->status)->toBe('accepted')
        ->and((int) $stored->resolved_by_user_id)->toBe($pm->id)
        ->and($stored->resolved_at)->not->toBeNull()
        ->and(DB::table('tasks')->where('id', $taskId)->value('title'))->toBe('Clarify upload policy')
        ->and(DB::table('audit_logs')
            ->where('action', 'assistant.suggestion.accepted')
            ->where('target_type', 'assistant_suggestion')
            ->where('target_id', $suggestionId)
            ->exists())->toBeTrue();

    $this->actingAs($pm)
        ->patchJson("/api/dashboard/assistant-suggestions/{$suggestionId}", [
            'status' => 'rejected',
        ])
        ->assertStatus(409);
});

it('supersedes older pending task clarification suggestions when a new one is generated', function () {
    $pm = taskClarifierUserWithRole('PM');
    $projectId = DB::table('projects')->where('slug', 'demo-project')->value('id');
    $taskId = taskClarifierCreateTask($projectId, $pm, [
        'title' => 'Clarify checkout copy',
        'description' => 'Checkout wording needs product review.',
    ]);

    $firstSuggestionId = $this->actingAs($pm)
        ->postJson("/api/dashboard/tasks/{$taskId}/assistant/clarify")
        ->assertCreated()
        ->json('suggestion.id');

    $secondSuggestionId = $this->actingAs($pm)
        ->postJson("/api/dashboard/tasks/{$taskId}/assistant/clarify")
        ->assertCreated()
        ->assertJsonPath('suggestion.status', 'pending')
        ->json('suggestion.id');

    expect($secondSuggestionId)->not->toBe($firstSuggestionId)
        ->and(DB::table('assistant_suggestions')->where('id', $firstSuggestionId)->value('status'))->toBe('superseded')
        ->and(DB::table('assistant_suggestions')->where('id', $firstSuggestionId)->value('resolved_by_user_id'))->toBe($pm->id)
        ->and(DB::table('assistant_suggestions')->where('id', $firstSuggestionId)->value('resolved_at'))->not->toBeNull()
        ->and(DB::table('assistant_suggestions')->where('id', $secondSuggestionId)->value('status'))->toBe('pending')
        ->and(DB::table('audit_logs')
            ->where('action', 'assistant.suggestion.superseded')
            ->where('target_type', 'assistant_suggestion')
            ->where('target_id', $firstSuggestionId)
            ->exists())->toBeTrue();
});

it('does not supersede already resolved task clarification suggestions', function () {
    $pm = taskClarifierUserWithRole('PM');
    $projectId = DB::table('projects')->where('slug', 'demo-project')->value('id');
    $taskId = taskClarifierCreateTask($projectId, $pm, [
        'title' => 'Clarify notification settings',
        'description' => 'Notification settings need a clearer scope.',
    ]);

    $acceptedSuggestionId = $this->actingAs($pm)
        ->postJson("/api/dashboard/tasks/{$taskId}/assistant/clarify")
        ->assertCreated()
        ->json('suggestion.id');

    $this->actingAs($pm)
        ->patchJson("/api/dashboard/assistant-suggestions/{$acceptedSuggestionId}", [
            'status' => 'accepted',
        ])
        ->assertOk();

    $this->actingAs($pm)
        ->postJson("/api/dashboard/tasks/{$taskId}/assistant/clarify")
        ->assertCreated();

    expect(DB::table('assistant_suggestions')->where('id', $acceptedSuggestionId)->value('status'))->toBe('accepted')
        ->and(DB::table('assistant_suggestions')
            ->where('target_id', $taskId)
            ->where('suggestion_type', 'task_clarification')
            ->where('status', 'pending')
            ->count())->toBe(1);
});

it('applies accepted task clarification suggestions to the task description with audit', function () {
    $pm = taskClarifierUserWithRole('PM');
    $projectId = DB::table('projects')->where('slug', 'demo-project')->value('id');
    $taskId = taskClarifierCreateTask($projectId, $pm, [
        'title' => 'Clarify upload policy',
        'description' => 'Uploads need safer wording.',
    ]);

    $suggestionId = $this->actingAs($pm)
        ->postJson("/api/dashboard/tasks/{$taskId}/assistant/clarify")
        ->assertCreated()
        ->json('suggestion.id');

    $this->actingAs($pm)
        ->patchJson("/api/dashboard/assistant-suggestions/{$suggestionId}", [
            'status' => 'accepted',
        ])
        ->assertOk();

    $this->actingAs($pm)
        ->postJson("/api/dashboard/assistant-suggestions/{$suggestionId}/apply")
        ->assertOk()
        ->assertJsonPath('suggestion.id', $suggestionId)
        ->assertJsonPath('suggestion.status', 'applied')
        ->assertJsonPath('task.id', $taskId)
        ->assertJsonPath('task.assistant.latest_suggestion.status', 'applied')
        ->assertJsonPath('task.description', fn (string $description): bool => str_contains($description, 'Uploads need safer wording.')
            && str_contains($description, '## Assistant clarification')
            && str_contains($description, '### Questions')
            && str_contains($description, 'Which user or role needs this outcome?')
            && str_contains($description, '### Acceptance criteria')
            && str_contains($description, 'The task states the user-visible outcome in one sentence.'));

    $auditPayload = json_decode(
        (string) DB::table('audit_logs')
            ->where('action', 'assistant.suggestion.applied')
            ->where('target_type', 'assistant_suggestion')
            ->where('target_id', $suggestionId)
            ->value('payload'),
        true,
        flags: JSON_THROW_ON_ERROR
    );

    expect(DB::table('assistant_suggestions')->where('id', $suggestionId)->value('status'))->toBe('applied')
        ->and(DB::table('tasks')->where('id', $taskId)->value('description'))->toContain('## Assistant clarification')
        ->and($auditPayload['mutated_target'])->toBeTrue()
        ->and($auditPayload['applied_fields'])->toBe(['description'])
        ->and($auditPayload['target_type'])->toBe('task')
        ->and($auditPayload['target_id'])->toBe($taskId);
});

it('requires task clarification suggestions to be accepted before applying and applies each suggestion once', function () {
    $pm = taskClarifierUserWithRole('PM');
    $projectId = DB::table('projects')->where('slug', 'demo-project')->value('id');
    $taskId = taskClarifierCreateTask($projectId, $pm, [
        'title' => 'Clarify rollout',
        'description' => 'Rollout needs clearer acceptance checks.',
    ]);

    $suggestionId = $this->actingAs($pm)
        ->postJson("/api/dashboard/tasks/{$taskId}/assistant/clarify")
        ->assertCreated()
        ->json('suggestion.id');

    $this->actingAs($pm)
        ->postJson("/api/dashboard/assistant-suggestions/{$suggestionId}/apply")
        ->assertStatus(409);

    $this->actingAs($pm)
        ->patchJson("/api/dashboard/assistant-suggestions/{$suggestionId}", [
            'status' => 'accepted',
        ])
        ->assertOk();

    $this->actingAs($pm)
        ->postJson("/api/dashboard/assistant-suggestions/{$suggestionId}/apply")
        ->assertOk();

    $this->actingAs($pm)
        ->postJson("/api/dashboard/assistant-suggestions/{$suggestionId}/apply")
        ->assertStatus(409);

    expect(substr_count((string) DB::table('tasks')->where('id', $taskId)->value('description'), '## Assistant clarification'))->toBe(1);
});

it('keeps applying task clarification suggestions behind PM and Admin roles and active projects', function () {
    $pm = taskClarifierUserWithRole('PM');
    $developer = taskClarifierUserWithRole('Developer');
    $projectId = DB::table('projects')->where('slug', 'demo-project')->value('id');
    $taskId = taskClarifierCreateTask($projectId, $pm, [
        'title' => 'Clarify export flow',
        'description' => 'Export behavior needs narrower scope.',
    ]);

    $suggestionId = $this->actingAs($pm)
        ->postJson("/api/dashboard/tasks/{$taskId}/assistant/clarify")
        ->assertCreated()
        ->json('suggestion.id');

    $this->actingAs($pm)
        ->patchJson("/api/dashboard/assistant-suggestions/{$suggestionId}", [
            'status' => 'accepted',
        ])
        ->assertOk();

    $this->actingAs($developer)
        ->postJson("/api/dashboard/assistant-suggestions/{$suggestionId}/apply")
        ->assertForbidden();

    DB::table('projects')->where('id', $projectId)->update([
        'status' => 'archived',
        'archived_at' => now(),
        'archived_by_user_id' => $pm->id,
    ]);

    $this->actingAs($pm)
        ->postJson("/api/dashboard/assistant-suggestions/{$suggestionId}/apply")
        ->assertConflict()
        ->assertJsonPath('error.code', 'project_not_active');

    expect(DB::table('assistant_suggestions')->where('id', $suggestionId)->value('status'))->toBe('accepted')
        ->and(DB::table('tasks')->where('id', $taskId)->value('description'))->toBe('Export behavior needs narrower scope.');
});

it('keeps task clarification suggestion resolution behind PM and Admin dashboard roles', function () {
    $pm = taskClarifierUserWithRole('PM');
    $developer = taskClarifierUserWithRole('Developer');
    $projectId = DB::table('projects')->where('slug', 'demo-project')->value('id');
    $taskId = taskClarifierCreateTask($projectId, $pm, [
        'title' => 'Clarify exports',
        'description' => 'Exports need a decision.',
    ]);

    $suggestionId = $this->actingAs($pm)
        ->postJson("/api/dashboard/tasks/{$taskId}/assistant/clarify")
        ->assertCreated()
        ->json('suggestion.id');

    $this->actingAs($developer)
        ->patchJson("/api/dashboard/assistant-suggestions/{$suggestionId}", [
            'status' => 'rejected',
        ])
        ->assertForbidden();

    expect(DB::table('assistant_suggestions')->where('id', $suggestionId)->value('status'))->toBe('pending');
});

it('keeps task clarification behind PM and Admin dashboard roles', function () {
    $developer = taskClarifierUserWithRole('Developer');
    $admin = taskClarifierUserWithRole('Admin');
    $projectId = DB::table('projects')->where('slug', 'demo-project')->value('id');
    $taskId = taskClarifierCreateTask($projectId, $admin, [
        'title' => 'Clarify deployment',
        'description' => 'Deployment needs work.',
    ]);

    $this->actingAs($developer)
        ->postJson("/api/dashboard/tasks/{$taskId}/assistant/clarify")
        ->assertForbidden();

    $this->actingAs($admin)
        ->postJson("/api/dashboard/tasks/{$taskId}/assistant/clarify")
        ->assertCreated();
});

function taskClarifierUserWithRole(string $roleName): User
{
    $user = User::factory()->create();
    $roleId = DB::table('roles')->where('name', $roleName)->value('id');

    DB::table('role_user')->insert([
        'user_id' => $user->id,
        'role_id' => $roleId,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    return $user;
}

function taskClarifierConfigureProvider(): void
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

/**
 * @param array<string, mixed> $overrides
 */
function taskClarifierCreateTask(string $projectId, User $user, array $overrides = []): string
{
    $columnId = DB::table('kanban_boards')
        ->join('kanban_columns', 'kanban_columns.board_id', '=', 'kanban_boards.id')
        ->where('kanban_boards.project_id', $projectId)
        ->where('kanban_columns.status_key', 'backlog')
        ->value('kanban_columns.id');

    $taskId = (string) Str::ulid();
    $now = now();

    DB::table('tasks')->insert(array_merge([
        'id' => $taskId,
        'project_id' => $projectId,
        'title' => 'Clarify task',
        'description' => null,
        'status_column_id' => $columnId,
        'priority' => 'normal',
        'risk_level' => 'low',
        'owner_user_id' => null,
        'created_by_user_id' => $user->id,
        'due_at' => null,
        'created_at' => $now,
        'updated_at' => $now,
    ], $overrides));

    return $taskId;
}
