<?php

use App\Models\User;
use App\Services\AuditChainVerifier;
use App\Services\AuditLogger;
use Database\Seeders\DevBoardSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(DevBoardSeeder::class);
});

it('detects tampering with each hashed field at the exact sequence', function (string $column, mixed $value) {
    $logger = app(AuditLogger::class);
    $logger->record('audit.first', 'target', 'one', ['nested' => ['b' => 2, 'a' => 1]], ['type' => 'system']);
    $logger->record('audit.second', 'target', 'two', ['nested' => ['b' => 4, 'a' => 3]], ['type' => 'system']);

    DB::table('audit_logs')->where('sequence', 2)->update([$column => $value]);

    $result = app(AuditChainVerifier::class)->verify();

    expect($result->valid)->toBeFalse();
    expect($result->failures[0]->sequence)->toBe(2);
})->with([
    'chain_version' => ['chain_version', 99],
    'sequence' => ['sequence', 200],
    'id' => ['id', (string) Str::ulid()],
    'actor_user_ref' => ['actor_user_ref', 'user:999'],
    'actor_device_ref' => ['actor_device_ref', 'device:'.Str::ulid()],
    'actor_type' => ['actor_type', 'tampered'],
    'action' => ['action', 'audit.tampered'],
    'target_type' => ['target_type', 'tampered_target'],
    'target_id' => ['target_id', 'tampered-id'],
    'ip_address' => ['ip_address', '203.0.113.10'],
    'user_agent' => ['user_agent', 'tampered-agent'],
    'payload' => ['payload', json_encode(['tampered' => true], JSON_THROW_ON_ERROR)],
    'created_at' => ['created_at', '2026-07-10 12:34:56'],
    'prev_hash' => ['prev_hash', str_repeat('a', 64)],
]);

it('detects a deleted middle row', function () {
    $logger = app(AuditLogger::class);
    $logger->record('audit.first', 'target', 'one');
    $logger->record('audit.second', 'target', 'two');
    $logger->record('audit.third', 'target', 'three');

    DB::table('audit_logs')->where('sequence', 2)->delete();

    $result = app(AuditChainVerifier::class)->verify();

    expect($result->valid)->toBeFalse();
    expect($result->failures[0]->sequence)->toBe(2);
});

it('keeps immutable actor refs valid after actor deletion', function () {
    $user = User::factory()->create();

    app(AuditLogger::class)->record('audit.user_action', 'target', 'one', [], [
        'type' => 'user',
        'user_id' => $user->id,
    ]);

    $before = DB::table('audit_logs')->first();
    $user->delete();
    $after = DB::table('audit_logs')->first();
    $result = app(AuditChainVerifier::class)->verify();

    expect($after->actor_user_id)->toBeNull();
    expect($after->actor_user_ref)->toBe($before->actor_user_ref);
    expect($result->valid)->toBeTrue();
});

it('chains two sequential rows with contiguous sequence and previous hash', function () {
    $logger = app(AuditLogger::class);
    $logger->record('audit.first', 'target', 'one', ['b' => 2, 'a' => 1]);
    $logger->record('audit.second', 'target', 'two', ['d' => 4, 'c' => 3]);

    $rows = DB::table('audit_logs')->orderBy('sequence')->get();
    $head = DB::table('audit_chain_heads')->where('chain_key', 'global')->first();
    $result = app(AuditChainVerifier::class)->verify();

    expect($rows)->toHaveCount(2);
    expect($rows[0]->sequence)->toBe(1);
    expect($rows[0]->prev_hash)->toBeNull();
    expect($rows[1]->sequence)->toBe(2);
    expect($rows[1]->prev_hash)->toBe($rows[0]->row_hash);
    expect($head->last_sequence)->toBe(2);
    expect($head->last_hash)->toBe($rows[1]->row_hash);
    expect($result->valid)->toBeTrue();
});

it('requires maintenance mode or force before backfilling legacy rows', function () {
    insertLegacyAuditRow('2026-07-10 10:00:00', 'legacy.force_required');

    $exitCode = Artisan::call('audit:chain-backfill');

    expect($exitCode)->toBe(1);
    expect(Artisan::output())->toContain('maintenance mode');
    expect(DB::table('audit_logs')->whereNull('sequence')->count())->toBe(1);
});

it('dry-runs legacy audit chain backfill without mutating rows or head', function () {
    insertLegacyAuditRow('2026-07-10 10:00:00', 'legacy.one');
    insertLegacyAuditRow('2026-07-10 10:01:00', 'legacy.two');

    $exitCode = Artisan::call('audit:chain-backfill', ['--dry-run' => true]);
    $head = DB::table('audit_chain_heads')->where('chain_key', 'global')->first();

    expect($exitCode)->toBe(0);
    expect(Artisan::output())->toContain('Would backfill 2 audit row(s).');
    expect(DB::table('audit_logs')->whereNull('sequence')->count())->toBe(2);
    expect($head->last_sequence)->toBe(0);
    expect($head->last_hash)->toBeNull();
});

it('backfills legacy rows in created-at then id order and verifies before success', function () {
    $later = insertLegacyAuditRow('2026-07-10 10:01:00', 'legacy.later');
    $first = insertLegacyAuditRow('2026-07-10 10:00:00', 'legacy.first');
    $second = insertLegacyAuditRow('2026-07-10 10:00:00', 'legacy.second');

    $exitCode = Artisan::call('audit:chain-backfill', ['--force' => true]);
    $rows = DB::table('audit_logs')->orderBy('sequence')->get();
    $head = DB::table('audit_chain_heads')->where('chain_key', 'global')->first();

    expect($exitCode)->toBe(0);
    expect(Artisan::output())->toContain('Audit chain verified.');
    expect($rows->pluck('id')->all())->toBe([$first, $second, $later]);
    expect($rows->pluck('sequence')->all())->toBe([1, 2, 3]);
    expect($rows->pluck('chain_version')->all())->toBe([1, 1, 1]);
    expect($rows[0]->prev_hash)->toBeNull();
    expect($rows[1]->prev_hash)->toBe($rows[0]->row_hash);
    expect($rows[2]->prev_hash)->toBe($rows[1]->row_hash);
    expect($head->last_sequence)->toBe(3);
    expect($head->last_hash)->toBe($rows[2]->row_hash);
    expect(app(AuditChainVerifier::class)->verify()->valid)->toBeTrue();
});

function insertLegacyAuditRow(string $createdAt, string $action): string
{
    $id = (string) Str::ulid();

    DB::table('audit_logs')->insert([
        'id' => $id,
        'actor_user_id' => null,
        'actor_device_id' => null,
        'actor_type' => 'system',
        'action' => $action,
        'target_type' => 'legacy',
        'target_id' => $id,
        'ip_address' => null,
        'user_agent' => null,
        'payload' => json_encode(['legacy' => true], JSON_THROW_ON_ERROR),
        'prev_hash' => null,
        'row_hash' => null,
        'sequence' => null,
        'chain_version' => null,
        'actor_user_ref' => null,
        'actor_device_ref' => null,
        'created_at' => $createdAt,
    ]);

    return $id;
}
