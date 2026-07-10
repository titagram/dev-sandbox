<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

it('enforces final audit chain constraints in PostgreSQL', function () {
    expect(DB::connection()->getDriverName())->toBe('pgsql');

    $nullable = DB::table('information_schema.columns')
        ->where('table_schema', 'public')
        ->where('table_name', 'audit_logs')
        ->whereIn('column_name', ['sequence', 'chain_version', 'row_hash'])
        ->pluck('is_nullable', 'column_name')
        ->all();

    expect($nullable['sequence'])->toBe('NO');
    expect($nullable['chain_version'])->toBe('NO');
    expect($nullable['row_hash'])->toBe('NO');

    expect(DB::table('pg_indexes')
        ->where('schemaname', 'public')
        ->where('tablename', 'audit_logs')
        ->where('indexdef', 'like', '%UNIQUE%')
        ->where('indexdef', 'like', '%sequence%')
        ->exists())->toBeTrue();

    expect(auditConstraintRejects(['row_hash' => str_repeat('a', 63)]))->toBeTrue();
    expect(auditConstraintRejects(['sequence' => 1, 'prev_hash' => str_repeat('b', 64)]))->toBeTrue();
    expect(auditConstraintRejects(['sequence' => 2, 'prev_hash' => null]))->toBeTrue();
});

/**
 * @param  array<string, mixed>  $overrides
 */
function auditConstraintRejects(array $overrides): bool
{
    DB::statement('SAVEPOINT audit_constraint_test');

    try {
        insertConstrainedAuditRow($overrides);
        DB::statement('RELEASE SAVEPOINT audit_constraint_test');
    } catch (Throwable) {
        DB::statement('ROLLBACK TO SAVEPOINT audit_constraint_test');

        return true;
    }

    return false;
}

/**
 * @param  array<string, mixed>  $overrides
 */
function insertConstrainedAuditRow(array $overrides): void
{
    DB::table('audit_logs')->insert(array_merge([
        'id' => (string) Str::ulid(),
        'sequence' => 100,
        'chain_version' => 1,
        'actor_user_id' => null,
        'actor_user_ref' => null,
        'actor_device_id' => null,
        'actor_device_ref' => null,
        'actor_type' => 'system',
        'action' => 'audit.constraint_fixture',
        'target_type' => 'audit',
        'target_id' => null,
        'ip_address' => null,
        'user_agent' => null,
        'payload' => json_encode([], JSON_THROW_ON_ERROR),
        'prev_hash' => str_repeat('a', 64),
        'row_hash' => str_repeat('b', 64),
        'created_at' => now(),
    ], $overrides));
}
