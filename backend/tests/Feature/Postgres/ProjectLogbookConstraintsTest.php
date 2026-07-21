<?php

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

it('enforces project idempotency and immutable rows in PostgreSQL', function () {
    expect(DB::connection()->getDriverName())->toBe('pgsql');
    $projectId = postgresLogbookProject();
    $entryId = insertPostgresLogbookEntry($projectId, 'postgres-key-0001');

    expect(DB::table('pg_indexes')
        ->where('schemaname', 'public')
        ->where('tablename', 'project_logbook_entries')
        ->where('indexname', 'project_logbook_entries_project_idempotency_unique')
        ->exists())->toBeTrue();
    expect(DB::table('pg_trigger')
        ->where('tgname', 'project_logbook_entries_immutable_trigger')
        ->where('tgisinternal', false)
        ->exists())->toBeTrue();
    $jsonTypes = DB::table('information_schema.columns')
        ->where('table_schema', 'public')
        ->where('table_name', 'project_logbook_entries')
        ->whereIn('column_name', ['references', 'payload'])
        ->pluck('data_type', 'column_name');
    expect($jsonTypes->all())->toBe(['payload' => 'jsonb', 'references' => 'jsonb']);

    expect(postgresLogbookMutationRejects(
        'UPDATE project_logbook_entries SET summary = ? WHERE id = ?',
        ['tampered', $entryId],
    ))->toBeTrue();
    expect(postgresLogbookMutationRejects(
        'DELETE FROM project_logbook_entries WHERE id = ?',
        [$entryId],
    ))->toBeTrue();
    expect(postgresLogbookDuplicateRejects($projectId))->toBeTrue();
});

it('replays concurrent identical appends as one entry and one audit event', function () {
    expect(DB::connection()->getDriverName())->toBe('pgsql');

    $projectId = (string) Str::ulid();
    $slug = 'postgres-logbook-race-'.Str::lower(Str::random(6));
    $setup = <<<'PHP'
$user = \App\Models\User::factory()->create();
\Illuminate\Support\Facades\DB::table('projects')->insert([
    'id' => getenv('LOGBOOK_PROJECT_ID'),
    'name' => 'Postgres Logbook Race',
    'slug' => getenv('LOGBOOK_PROJECT_SLUG'),
    'description' => null,
    'status' => 'active',
    'default_code_exposure_policy' => 'full_code_artifacts',
    'created_by_user_id' => $user->id,
    'created_at' => now(),
    'updated_at' => now(),
]);
PHP;
    $setupResult = Process::env([
        'APP_ENV' => 'testing',
        'HOME' => '/tmp',
        'XDG_CONFIG_HOME' => '/tmp',
        'LOGBOOK_PROJECT_ID' => $projectId,
        'LOGBOOK_PROJECT_SLUG' => $slug,
    ])->run(['php', 'artisan', 'tinker', '--execute', $setup]);
    expect($setupResult->successful())->toBeTrue($setupResult->errorOutput() ?: $setupResult->output());

    $command = base64_encode(json_encode([
        'project_id' => $projectId,
        'event_type' => 'change',
        'severity' => 'info',
        'summary' => 'Concurrent idempotent project change',
        'narrative_markdown' => null,
        'references' => [],
        'correlation_id' => 'race-'.$projectId,
        'idempotency_key' => 'postgres-race-key-0001',
        'payload' => ['source' => 'concurrency-test'],
        'supersedes_entry_id' => null,
    ], JSON_THROW_ON_ERROR));
    $append = <<<'PHP'
$command = json_decode(base64_decode((string) getenv('LOGBOOK_COMMAND'), true), true, 512, JSON_THROW_ON_ERROR);
app(\App\Services\ProjectLogbookService::class)->append(
    $command,
    new \App\Support\ProjectLogbookActor(kind: 'agent', label: 'Race Agent', agentId: 'race-agent'),
);
PHP;

    $processes = collect(range(1, 6))->map(fn () => Process::env([
        'APP_ENV' => 'testing',
        'HOME' => '/tmp',
        'XDG_CONFIG_HOME' => '/tmp',
        'LOGBOOK_COMMAND' => $command,
    ])->start(['php', 'artisan', 'tinker', '--execute', $append]));
    $processes->each(function ($process): void {
        $result = $process->wait();
        expect($result->successful())->toBeTrue($result->errorOutput() ?: $result->output());
    });

    $entries = DB::table('project_logbook_entries')->where('project_id', $projectId)->get();
    expect($entries)->toHaveCount(1)
        ->and(DB::table('audit_logs')
            ->where('action', 'project_logbook.appended')
            ->where('target_id', $entries->first()->id)
            ->count())->toBe(1);
});

function postgresLogbookProject(): string
{
    $user = User::factory()->create();
    $projectId = (string) Str::ulid();
    DB::table('projects')->insert([
        'id' => $projectId,
        'name' => 'Postgres Logbook',
        'slug' => 'postgres-logbook-'.Str::lower(Str::random(6)),
        'description' => null,
        'status' => 'active',
        'default_code_exposure_policy' => 'full_code_artifacts',
        'created_by_user_id' => $user->id,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    return $projectId;
}

function insertPostgresLogbookEntry(string $projectId, string $key): string
{
    $id = (string) Str::ulid();
    DB::table('project_logbook_entries')->insert([
        'id' => $id,
        'project_id' => $projectId,
        'occurred_at' => now(),
        'recorded_at' => now(),
        'actor_kind' => 'system',
        'actor_label' => 'System',
        'actor_user_id' => null,
        'actor_agent_id' => null,
        'actor_device_id' => null,
        'actor_role' => null,
        'actor_model' => null,
        'event_type' => 'change',
        'severity' => 'info',
        'summary' => 'PostgreSQL immutable entry',
        'narrative_markdown' => null,
        'references' => json_encode([], JSON_THROW_ON_ERROR),
        'correlation_id' => null,
        'idempotency_key' => $key,
        'request_sha256' => hash('sha256', $key),
        'payload' => json_encode([], JSON_THROW_ON_ERROR),
        'supersedes_entry_id' => null,
    ]);

    return $id;
}

function postgresLogbookMutationRejects(string $sql, array $bindings): bool
{
    DB::statement('SAVEPOINT project_logbook_mutation');
    try {
        DB::statement($sql, $bindings);
        DB::statement('RELEASE SAVEPOINT project_logbook_mutation');
    } catch (Throwable) {
        DB::statement('ROLLBACK TO SAVEPOINT project_logbook_mutation');

        return true;
    }

    return false;
}

function postgresLogbookDuplicateRejects(string $projectId): bool
{
    DB::statement('SAVEPOINT project_logbook_duplicate');
    try {
        insertPostgresLogbookEntry($projectId, 'postgres-key-0001');
        DB::statement('RELEASE SAVEPOINT project_logbook_duplicate');
    } catch (Throwable) {
        DB::statement('ROLLBACK TO SAVEPOINT project_logbook_duplicate');

        return true;
    }

    return false;
}
