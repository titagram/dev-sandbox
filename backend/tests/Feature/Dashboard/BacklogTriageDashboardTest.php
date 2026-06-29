<?php

use App\Assistants\Agents\BacklogTriageAgent;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->withoutVite();
    $this->seed(\Database\Seeders\DevBoardSeeder::class);
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
