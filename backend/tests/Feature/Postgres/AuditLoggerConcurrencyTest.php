<?php

use App\Services\AuditChainVerifier;
use App\Services\AuditLogger;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Process;

uses(RefreshDatabase::class);

it('serializes concurrent PostgreSQL audit writers into one contiguous chain', function () {
    expect(DB::connection()->getDriverName())->toBe('pgsql');

    $workers = 8;
    $eventsPerWorker = 5;

    $script = <<<'PHP'
app(\App\Services\AuditLogger::class)->recordMany(array_map(
    fn (int $i): array => [
        'action' => 'audit.concurrent',
        'target_type' => 'worker',
        'target_id' => getenv('AUDIT_WORKER').'-'.$i,
        'payload' => ['worker' => getenv('AUDIT_WORKER'), 'index' => $i],
        'actor' => ['type' => 'system'],
    ],
    range(1, (int) getenv('AUDIT_EVENTS')),
));
PHP;

    $processes = collect(range(1, $workers))->map(fn (int $worker) => Process::env([
        'APP_ENV' => 'testing',
        'AUDIT_WORKER' => (string) $worker,
        'AUDIT_EVENTS' => (string) $eventsPerWorker,
    ])->start(['php', 'artisan', 'tinker', '--execute', $script]));

    $processes->each(function ($process) {
        $result = $process->wait();
        expect($result->successful())->toBeTrue($result->errorOutput() ?: $result->output());
    });

    $sequences = DB::table('audit_logs')->orderBy('sequence')->pluck('sequence')->all();
    $rows = DB::table('audit_logs')->orderBy('sequence')->get();
    $head = DB::table('audit_chain_heads')->where('chain_key', 'global')->first();
    $expectedCount = $workers * $eventsPerWorker;

    expect($rows)->toHaveCount($expectedCount);
    expect($sequences)->toBe(range(1, $expectedCount));
    expect(array_unique($sequences))->toHaveCount($expectedCount);
    expect($head->last_sequence)->toBe($expectedCount);
    expect($head->last_hash)->toBe($rows->last()->row_hash);
    expect(app(AuditChainVerifier::class)->verify()->valid)->toBeTrue();
});
