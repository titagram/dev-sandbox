<?php

use App\Assistants\Agents\TaskClarifierAgent;
use App\Assistants\AiAgentToolRegistry;
use App\Assistants\Tools\ReadProjectSummaryTool;
use App\Assistants\Tools\ReadTaskDetailTool;
use App\Assistants\Tools\SearchWikiRevisionsTool;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Ai\Contracts\HasTools;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(\Database\Seeders\DevBoardSeeder::class);
});

it('registers read-only tools matching the controlled task clarifier profile', function () {
    expect(class_exists(AiAgentToolRegistry::class))->toBeTrue();

    $registry = app(AiAgentToolRegistry::class);
    $tools = $registry->forAgentKey('task_clarifier');
    $toolNames = array_map(fn (Tool $tool): string => $tool->name(), $tools);
    $allowedTools = json_decode((string) DB::table('ai_agent_profiles')->where('agent_key', 'task_clarifier')->value('allowed_tools'), true, flags: JSON_THROW_ON_ERROR);

    expect($tools)->toHaveCount(3)
        ->and($toolNames)->toEqualCanonicalizing(['read_project_summary', 'read_task_detail', 'search_wiki_revisions'])
        ->and(array_values(array_intersect($allowedTools, $toolNames)))->toEqualCanonicalizing($toolNames)
        ->and(TaskClarifierAgent::make())->toBeInstanceOf(HasTools::class)
        ->and(array_map(fn (Tool $tool): string => $tool->name(), [...TaskClarifierAgent::make()->tools()]))
        ->toEqualCanonicalizing($toolNames);
});

it('reads a bounded project summary from DevBoard evidence only', function () {
    $pm = aiAgentToolsUserWithRole('PM');
    $projectId = DB::table('projects')->where('slug', 'demo-project')->value('id');
    aiAgentToolsCreateTask($projectId, $pm, [
        'title' => 'Clarify refund workflow',
        'priority' => 'high',
        'risk_level' => 'medium',
    ]);
    aiAgentToolsCreateWikiRevision($projectId, [
        'slug' => 'refunds',
        'title' => 'Refund Workflow',
        'source_status' => 'stale',
        'content_markdown' => 'Refund workflow notes.',
    ]);

    $payload = (new ReadProjectSummaryTool)->payload(['project_id' => $projectId]);

    expect($payload['source_status'])->toBe('verified_from_code')
        ->and($payload['tool'])->toBe('read_project_summary')
        ->and($payload['found'])->toBeTrue()
        ->and($payload['project']['id'])->toBe($projectId)
        ->and($payload['repositories']['total'])->toBe(1)
        ->and($payload['tasks']['total'])->toBe(1)
        ->and($payload['tasks']['by_status'][0]['status_key'])->toBe('backlog')
        ->and($payload['wiki']['pages_total'])->toBeGreaterThanOrEqual(1)
        ->and($payload['wiki']['stale_pages'])->toBeGreaterThanOrEqual(1)
        ->and(DB::table('tasks')->where('project_id', $projectId)->count())->toBe(1);

    DB::table('projects')->where('id', $projectId)->update(['status' => 'deleted']);

    expect((new ReadProjectSummaryTool)->payload(['project_id' => $projectId])['found'])->toBeFalse();
});

it('reads task detail without mutating the task', function () {
    $pm = aiAgentToolsUserWithRole('PM');
    $developer = aiAgentToolsUserWithRole('Developer');
    $projectId = DB::table('projects')->where('slug', 'demo-project')->value('id');
    $taskId = aiAgentToolsCreateTask($projectId, $pm, [
        'title' => 'Clarify checkout retries',
        'description' => 'Retry handling needs acceptance criteria.',
        'owner_user_id' => $developer->id,
        'priority' => 'high',
        'risk_level' => 'high',
    ]);
    $updatedAt = DB::table('tasks')->where('id', $taskId)->value('updated_at');

    $payload = json_decode((new ReadTaskDetailTool)->handle(new Request(['task_id' => $taskId])), true, flags: JSON_THROW_ON_ERROR);

    expect($payload['source_status'])->toBe('verified_from_code')
        ->and($payload['tool'])->toBe('read_task_detail')
        ->and($payload['found'])->toBeTrue()
        ->and($payload['task']['id'])->toBe($taskId)
        ->and($payload['task']['title'])->toBe('Clarify checkout retries')
        ->and($payload['task']['status_key'])->toBe('backlog')
        ->and($payload['task']['owner']['name'])->toBe($developer->name)
        ->and($payload['project']['id'])->toBe($projectId)
        ->and(DB::table('tasks')->where('id', $taskId)->value('updated_at'))->toBe($updatedAt);
});

it('searches current wiki revisions with bounded excerpts and evidence refs', function () {
    $projectId = DB::table('projects')->where('slug', 'demo-project')->value('id');
    $longTail = str_repeat('internal tail ', 80);
    $firstRevisionId = aiAgentToolsCreateWikiRevision($projectId, [
        'slug' => 'billing/refunds',
        'title' => 'Billing Refunds',
        'source_status' => 'verified_from_code',
        'content_markdown' => 'Refund handling must ask finance before release. '.$longTail,
        'evidence_refs' => [['type' => 'artifact', 'id' => 'artifact-1']],
    ]);
    aiAgentToolsCreateWikiRevision($projectId, [
        'slug' => 'billing/invoices',
        'title' => 'Billing Invoices',
        'content_markdown' => 'Invoice handling has no matching term.',
    ]);

    $payload = (new SearchWikiRevisionsTool)->payload([
        'project_id' => $projectId,
        'query' => 'refund',
        'limit' => 1,
    ]);

    expect($payload['source_status'])->toBe('verified_from_code')
        ->and($payload['tool'])->toBe('search_wiki_revisions')
        ->and($payload['results'])->toHaveCount(1)
        ->and($payload['results'][0]['revision_id'])->toBe($firstRevisionId)
        ->and($payload['results'][0]['excerpt'])->toContain('Refund handling')
        ->and($payload['results'][0]['excerpt'])->not->toContain(str_repeat('internal tail ', 20))
        ->and($payload['results'][0])->not->toHaveKey('content_markdown')
        ->and($payload['results'][0]['evidence_refs'][0]['id'])->toBe('artifact-1');
});

function aiAgentToolsUserWithRole(string $roleName): User
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
function aiAgentToolsCreateTask(string $projectId, User $user, array $overrides = []): string
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
        'title' => 'Read-only agent tool task',
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

/**
 * @param array<string, mixed> $overrides
 */
function aiAgentToolsCreateWikiRevision(string $projectId, array $overrides = []): string
{
    $now = now();
    $pageId = (string) Str::ulid();
    $revisionId = (string) Str::ulid();
    $sourceStatus = $overrides['source_status'] ?? 'developer_provided';

    DB::table('wiki_pages')->insert([
        'id' => $pageId,
        'project_id' => $projectId,
        'repository_id' => $overrides['repository_id'] ?? null,
        'slug' => $overrides['slug'] ?? "page-{$pageId}",
        'title' => $overrides['title'] ?? 'Read-only Tool Page',
        'page_type' => $overrides['page_type'] ?? 'business',
        'current_revision_id' => $revisionId,
        'source_status' => $sourceStatus,
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    DB::table('wiki_revisions')->insert([
        'id' => $revisionId,
        'wiki_page_id' => $pageId,
        'author_user_id' => null,
        'author_device_id' => null,
        'producer' => $overrides['producer'] ?? 'test',
        'source_type' => $overrides['source_type'] ?? 'manual',
        'source_status' => $sourceStatus,
        'content_markdown' => $overrides['content_markdown'] ?? 'Read-only wiki content.',
        'evidence_refs' => json_encode($overrides['evidence_refs'] ?? [], JSON_THROW_ON_ERROR),
        'created_at' => $now,
    ]);

    return $revisionId;
}
