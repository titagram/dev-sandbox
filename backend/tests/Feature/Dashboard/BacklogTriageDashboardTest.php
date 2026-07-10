<?php

use App\Assistants\Agents\BacklogTriageAgent;
use App\Assistants\ProviderHostResolver;
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
    $this->app->singleton(ProviderHostResolver::class, function () {
        return new BacklogTriageFakeHostResolver(['8.8.8.8'], [
            'ssrf-triage.example.test' => ['10.0.0.5'],
        ]);
    });
});

it('creates a project-level backlog triage suggestion without mutating the board', function () {
    $pm = backlogTriageUserWithRole('PM');
    $projectId = DB::table('projects')->where('slug', 'demo-project')->value('id');
    $taskId = backlogTriageCreateTask($projectId, $pm, [
        'title' => 'Clarify retry flow',
        'description' => 'Retry flow needs clearer boundaries.',
        'priority' => 'high',
        'risk_level' => 'high',
    ]);
    $columnBefore = DB::table('tasks')->where('id', $taskId)->value('status_column_id');

    $response = $this->actingAs($pm)
        ->postJson("/api/dashboard/projects/{$projectId}/assistant/backlog-triage")
        ->assertCreated()
        ->assertJsonPath('run.status', 'completed')
        ->assertJsonPath('run.agent_key', 'backlog_triage')
        ->assertJsonPath('run.target_type', 'project')
        ->assertJsonPath('suggestion.suggestion_type', 'backlog_triage')
        ->assertJsonPath('suggestion.target_type', 'project')
        ->assertJsonPath('suggestion.target_id', $projectId)
        ->assertJsonPath('suggestion.status', 'pending')
        ->assertJsonPath('suggestion.approval_required', true)
        ->assertJsonPath('suggestion.structured_payload.groups.0.label', 'Needs clarification')
        ->assertJsonPath('suggestion.structured_payload.recommendations.0.priority', 'high')
        ->assertJsonPath('suggestion.evidence_refs.0.type', 'project');

    $runId = $response->json('run.id');
    $suggestionId = $response->json('suggestion.id');

    expect(DB::table('assistant_runs')->where('id', $runId)->where('target_id', $projectId)->exists())->toBeTrue()
        ->and(DB::table('assistant_messages')->where('assistant_run_id', $runId)->where('role', 'assistant')->exists())->toBeTrue()
        ->and(DB::table('assistant_suggestions')->where('id', $suggestionId)->where('assistant_run_id', $runId)->exists())->toBeTrue()
        ->and(DB::table('tasks')->where('id', $taskId)->value('status_column_id'))->toBe($columnBefore)
        ->and(DB::table('tasks')->where('id', $taskId)->value('title'))->toBe('Clarify retry flow');
});

it('uses the Laravel AI SDK backlog triage agent when it is faked', function () {
    BacklogTriageAgent::fake([[
        'summary' => 'The backlog has one high-risk vague payment task.',
        'groups' => [[
            'label' => 'Payment scope',
            'task_ids' => [],
            'reason' => 'Payment retry behavior is not yet testable.',
        ]],
        'recommendations' => [[
            'title' => 'Clarify payment retry behavior',
            'body' => 'Ask for exact retry states, user-visible copy, and done checks.',
            'task_ids' => [],
            'priority' => 'high',
        ]],
        'risks' => ['Developers may implement different retry semantics.'],
        'confidence' => 0.9,
    ]])->preventStrayPrompts();

    $pm = backlogTriageUserWithRole('PM');
    $projectId = DB::table('projects')->where('slug', 'demo-project')->value('id');
    $taskId = backlogTriageCreateTask($projectId, $pm, [
        'title' => 'Clarify payment retries',
        'description' => 'Payment retry behavior needs a product decision.',
        'priority' => 'high',
        'risk_level' => 'medium',
    ]);

    $response = $this->actingAs($pm)
        ->postJson("/api/dashboard/projects/{$projectId}/assistant/backlog-triage")
        ->assertCreated()
        ->assertJsonPath('suggestion.structured_payload.summary', 'The backlog has one high-risk vague payment task.')
        ->assertJsonPath('suggestion.structured_payload.groups.0.label', 'Payment scope')
        ->assertJsonPath('suggestion.structured_payload.recommendations.0.title', 'Clarify payment retry behavior')
        ->assertJsonPath('suggestion.confidence', 0.9);

    BacklogTriageAgent::assertPrompted(fn ($prompt): bool => $prompt->contains($projectId)
        && $prompt->contains($taskId)
        && $prompt->contains('Clarify payment retries')
        && $prompt->model === 'gpt-5.4');

    $metadata = json_decode(
        (string) DB::table('assistant_runs')->where('id', $response->json('run.id'))->value('metadata'),
        true,
        flags: JSON_THROW_ON_ERROR
    );

    expect($metadata['execution_mode'])->toBe('laravel_ai_sdk_fake')
        ->and($metadata['external_provider_call'])->toBeFalse()
        ->and($metadata['sdk_agent'])->toBe(BacklogTriageAgent::class);
});

it('falls back to deterministic backlog triage when the configured provider fails', function () {
    Http::fake(['*' => Http::response(['error' => ['message' => 'not found']], 404)]);

    $pm = backlogTriageUserWithRole('PM');
    $projectId = DB::table('projects')->where('slug', 'demo-project')->value('id');
    backlogTriageCreateTask($projectId, $pm, [
        'title' => 'Clarify import queue',
        'description' => 'Import queue needs clearer status wording.',
    ]);
    backlogTriageConfigureProvider();

    $response = $this->actingAs($pm)
        ->postJson("/api/dashboard/projects/{$projectId}/assistant/backlog-triage")
        ->assertCreated()
        ->assertJsonPath('run.status', 'completed')
        ->assertJsonPath('suggestion.structured_payload.groups.0.label', 'Needs clarification');

    $metadata = json_decode(
        (string) DB::table('assistant_runs')->where('id', $response->json('run.id'))->value('metadata'),
        true,
        flags: JSON_THROW_ON_ERROR
    );

    expect($metadata['execution_mode'])->toBe('provider_failed_fallback')
        ->and($metadata['external_provider_call'])->toBeTrue()
        ->and($metadata['provider_failure']['message'])->toContain('HTTP request returned status code 404');
});

it('shows the latest backlog triage suggestion on the project page', function () {
    $pm = backlogTriageUserWithRole('PM');
    $projectId = DB::table('projects')->where('slug', 'demo-project')->value('id');
    backlogTriageCreateTask($projectId, $pm, [
        'title' => 'Clarify onboarding checklist',
        'description' => 'Checklist is too broad.',
    ]);

    $this->actingAs($pm)
        ->postJson("/api/dashboard/projects/{$projectId}/assistant/backlog-triage")
        ->assertCreated();

    $this->actingAs($pm)->get("/projects/{$projectId}")
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Projects/Show')
            ->where('project.id', $projectId)
            ->where('assistant.latest_backlog_triage_suggestion.suggestion_type', 'backlog_triage')
            ->where('assistant.latest_backlog_triage_suggestion.status', 'pending')
            ->where('assistant.triage_href', "/api/dashboard/projects/{$projectId}/assistant/backlog-triage")
            ->where('assistant.can_triage', true)
        );
});

it('keeps backlog triage behind PM and Admin roles and active projects', function () {
    $pm = backlogTriageUserWithRole('PM');
    $developer = backlogTriageUserWithRole('Developer');
    $projectId = DB::table('projects')->where('slug', 'demo-project')->value('id');
    backlogTriageCreateTask($projectId, $pm);

    $this->actingAs($developer)
        ->postJson("/api/dashboard/projects/{$projectId}/assistant/backlog-triage")
        ->assertForbidden();

    DB::table('projects')->where('id', $projectId)->update([
        'status' => 'archived',
        'archived_at' => now(),
        'archived_by_user_id' => $pm->id,
    ]);

    $this->actingAs($pm)
        ->postJson("/api/dashboard/projects/{$projectId}/assistant/backlog-triage")
        ->assertConflict()
        ->assertJsonPath('error.code', 'project_not_active');

    expect(DB::table('assistant_suggestions')->where('target_id', $projectId)->where('suggestion_type', 'backlog_triage')->count())->toBe(0);
});

function backlogTriageUserWithRole(string $roleName): User
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

final class BacklogTriageFakeHostResolver implements ProviderHostResolver
{
    /** @param list<string> $default @param array<string, list<string>> $overrides */
    public function __construct(private array $default, private array $overrides = []) {}

    public function resolve(string $host): array
    {
        return $this->overrides[strtolower($host)] ?? $this->default;
    }
}

it('revalidates the backlog triage provider endpoint at use time and returns the deterministic fallback without dispatching for an unsafe stored URL', function () {
    Http::preventStrayRequests();
    Http::fake(fn () => Http::response('should not be called', 200));

    $providerId = (string) DB::table('ai_model_providers')->where('provider_key', 'openai')->value('id');
    $agentId = DB::table('ai_agent_profiles')->where('agent_key', 'backlog_triage')->value('id');
    $profileId = DB::table('ai_model_profiles')->where('profile_key', 'openai_default_text')->value('id');

    DB::table('ai_model_providers')->where('id', $providerId)->update([
        'base_url' => 'https://ssrf-triage.example.test/v1',
        'encrypted_api_key' => Crypt::encryptString('sk-opencode-test'),
        'api_key_last_four' => 'test',
        'enabled' => true,
    ]);
    DB::table('ai_agent_profiles')->where('id', $agentId)->update(['default_model_profile_id' => $profileId, 'enabled' => true]);
    DB::table('ai_model_profiles')->where('id', $profileId)->update(['enabled' => true, 'provider_id' => $providerId]);

    $pm = backlogTriageUserWithRole('PM');
    $projectId = DB::table('projects')->where('slug', 'demo-project')->value('id');
    backlogTriageCreateTask($projectId, $pm, [
        'title' => 'Unsafe endpoint triage',
        'description' => 'The backlog should fall back without calling the provider.',
    ]);

    $response = $this->actingAs($pm)
        ->postJson("/api/dashboard/projects/{$projectId}/assistant/backlog-triage")
        ->assertCreated();

    $metadata = json_decode(
        (string) DB::table('assistant_runs')->where('id', $response->json('run.id'))->value('metadata'),
        true,
        flags: JSON_THROW_ON_ERROR
    );

    expect($metadata['execution_mode'])->toBe('deterministic_fallback')
        ->and($metadata['external_provider_call'])->toBeFalse();

    Http::assertNothingSent();
});

function backlogTriageConfigureProvider(): void
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
function backlogTriageCreateTask(string $projectId, User $user, array $overrides = []): string
{
    $columnId = DB::table('kanban_boards')
        ->join('kanban_columns', 'kanban_columns.board_id', '=', 'kanban_boards.id')
        ->where('kanban_boards.project_id', $projectId)
        ->where('kanban_columns.status_key', $overrides['status_key'] ?? 'backlog')
        ->value('kanban_columns.id');

    $taskId = (string) Str::ulid();
    $now = now();

    DB::table('tasks')->insert(array_merge([
        'id' => $taskId,
        'project_id' => $projectId,
        'title' => 'Backlog triage task',
        'description' => null,
        'status_column_id' => $columnId,
        'priority' => 'normal',
        'risk_level' => 'low',
        'owner_user_id' => null,
        'created_by_user_id' => $user->id,
        'due_at' => null,
        'created_at' => $now,
        'updated_at' => $now,
    ], collect($overrides)->except('status_key')->all()));

    return $taskId;
}
