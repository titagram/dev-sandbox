<?php

use App\Exceptions\ProjectLogbookException;
use App\Models\User;
use App\Services\ProjectLogbookService;
use App\Support\ProjectLogbookActor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

it('appends a canonical project entry and emits one audit event', function () {
    [$projectId, $repositoryId] = logbookProject('alpha');
    $service = app(ProjectLogbookService::class);

    $result = $service->append(
        logbookCommand($projectId, 'stable-key-alpha', [
            ['kind' => 'repository', 'id' => $repositoryId],
            ['kind' => 'commit', 'id' => str_repeat('a', 40)],
        ]),
        logbookAgentActor(),
    );

    expect($result['replayed'])->toBeFalse()
        ->and($result['entry']->project_id)->toBe($projectId)
        ->and($result['entry']->actor_kind)->toBe('agent')
        ->and($result['entry']->actor_agent_id)->toBe('agent-test')
        ->and($result['entry']->references)->toBe([
            ['kind' => 'commit', 'id' => str_repeat('a', 40)],
            ['kind' => 'repository', 'id' => $repositoryId],
        ]);

    expect(DB::table('audit_logs')
        ->where('action', 'project_logbook.appended')
        ->where('target_id', $result['entry']->id)
        ->count())->toBe(1);
});

it('replays a semantically identical project idempotency key and rejects changed content', function () {
    [$projectId, $repositoryId] = logbookProject('replay');
    $service = app(ProjectLogbookService::class);
    $first = $service->append(
        logbookCommand($projectId, 'stable-key-replay', [
            ['kind' => 'repository', 'id' => $repositoryId],
            ['kind' => 'commit', 'id' => str_repeat('b', 40)],
        ]),
        logbookAgentActor(),
    );
    $reordered = logbookCommand($projectId, 'stable-key-replay', [
        ['id' => str_repeat('b', 40), 'kind' => 'commit'],
        ['id' => $repositoryId, 'kind' => 'repository'],
    ]);

    $again = $service->append($reordered, logbookAgentActor());

    expect($again['replayed'])->toBeTrue()
        ->and($again['entry']->id)->toBe($first['entry']->id)
        ->and(DB::table('project_logbook_entries')->count())->toBe(1)
        ->and(DB::table('audit_logs')->where('action', 'project_logbook.appended')->count())->toBe(1);

    expect(fn () => $service->append(
        [...$reordered, 'summary' => 'Changed after the original append'],
        logbookAgentActor(),
    ))->toThrow(ProjectLogbookException::class, 'logbook_idempotency_conflict');
});

it('rejects references owned by another project and unsafe file references', function () {
    [$projectId] = logbookProject('owner');
    [, $foreignRepositoryId] = logbookProject('foreign');
    $service = app(ProjectLogbookService::class);

    expect(fn () => $service->append(
        logbookCommand($projectId, 'stable-key-foreign', [['kind' => 'repository', 'id' => $foreignRepositoryId]]),
        logbookAgentActor(),
    ))->toThrow(ProjectLogbookException::class, 'logbook_reference_not_found');

    expect(fn () => $service->append(
        logbookCommand($projectId, 'stable-key-path', [['kind' => 'file', 'id' => '../secrets.env']]),
        logbookAgentActor(),
    ))->toThrow(ProjectLogbookException::class, 'logbook_reference_invalid');
});

it('rejects unredacted secrets in human and structured content', function () {
    [$projectId] = logbookProject('secrets');
    $service = app(ProjectLogbookService::class);

    expect(fn () => $service->append(
        [...logbookCommand($projectId, 'stable-key-secret-narrative'),
            'narrative_markdown' => 'Observed Authorization: Bearer abcdefghijklmnopqrstuvwxyz123456.',
        ],
        logbookAgentActor(),
    ))->toThrow(ProjectLogbookException::class, 'logbook_secret_detected');

    expect(fn () => $service->append(
        [...logbookCommand($projectId, 'stable-key-secret-payload'),
            'payload' => ['api_key' => 'unredacted-secret-value'],
        ],
        logbookAgentActor(),
    ))->toThrow(ProjectLogbookException::class, 'logbook_secret_detected');

    expect(fn () => $service->append(
        logbookCommand($projectId, 'stable-key-secret-reference', [
            ['kind' => 'file', 'id' => 'config/token=unredacted-secret-value'],
        ]),
        logbookAgentActor(),
    ))->toThrow(ProjectLogbookException::class, 'logbook_secret_detected');
});

it('rejects actor metadata that exceeds the storage contract', function () {
    expect(fn () => new ProjectLogbookActor(
        kind: 'agent',
        label: 'Hades Agent',
        role: str_repeat('r', 65),
    ))->toThrow(InvalidArgumentException::class, 'Invalid project logbook actor role.');
});

it('lists and shows entries only inside the requested project', function () {
    [$projectA] = logbookProject('list-a');
    [$projectB] = logbookProject('list-b');
    $service = app(ProjectLogbookService::class);
    $entryA = $service->append(logbookCommand($projectA, 'stable-key-list-a'), logbookAgentActor())['entry'];
    $service->append(logbookCommand($projectB, 'stable-key-list-b'), logbookAgentActor());

    $page = $service->listForProject($projectA, [], null, 20);

    expect($page['items'])->toHaveCount(1)
        ->and($page['items'][0]->id)->toBe($entryA->id)
        ->and($page['next_cursor'])->toBeNull()
        ->and($service->showForProject($projectA, $entryA->id)?->id)->toBe($entryA->id)
        ->and($service->showForProject($projectB, $entryA->id))->toBeNull();
});

/** @return array{string,string} */
function logbookProject(string $suffix): array
{
    $user = User::factory()->create();
    $projectId = (string) Str::ulid();
    $repositoryId = (string) Str::ulid();
    $now = now();
    DB::table('projects')->insert([
        'id' => $projectId,
        'name' => 'Logbook '.$suffix,
        'slug' => 'logbook-'.$suffix.'-'.Str::lower(Str::random(6)),
        'description' => null,
        'status' => 'active',
        'default_code_exposure_policy' => 'full_code_artifacts',
        'created_by_user_id' => $user->id,
        'created_at' => $now,
        'updated_at' => $now,
    ]);
    DB::table('repositories')->insert([
        'id' => $repositoryId,
        'project_id' => $projectId,
        'name' => 'Repository '.$suffix,
        'slug' => 'repository-'.$suffix,
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

    return [$projectId, $repositoryId];
}

/** @param list<array{kind:string,id:string}> $references */
function logbookCommand(string $projectId, string $key, array $references = []): array
{
    return [
        'project_id' => $projectId,
        'event_type' => 'change',
        'severity' => 'info',
        'summary' => 'Implemented a tested project change',
        'narrative_markdown' => 'The change passed its focused tests.',
        'references' => $references,
        'correlation_id' => 'run-'.$key,
        'idempotency_key' => $key,
        'payload' => ['tests' => ['focused' => 4]],
        'supersedes_entry_id' => null,
    ];
}

function logbookAgentActor(): ProjectLogbookActor
{
    return new ProjectLogbookActor(
        kind: 'agent',
        label: 'Hades Agent',
        agentId: 'agent-test',
        deviceId: 'device-test',
        role: 'implementer',
        model: 'gpt-5.6-sol',
    );
}
