<?php

use App\Services\AuditExportService;
use App\Services\AuditLogger;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

beforeEach(function () {
    Storage::fake('local');
});

it('exports filtered audit logs as jsonl', function () {
    createAuditLog('artifact.purged', [
        'artifact_id' => 'artifact_1',
        'token' => 'devb_live_abc|secret',
    ]);
    createAuditLog('wiki.updated', ['wiki_page_id' => 'wiki_1']);

    $result = app(AuditExportService::class)->export([
        'action' => 'artifact.purged',
    ], 'jsonl', 'devboard/audit-exports/artifacts.jsonl');

    expect($result)->toMatchArray([
        'path' => 'devboard/audit-exports/artifacts.jsonl',
        'row_count' => 1,
    ]);

    $content = trim(Storage::disk('local')->get('devboard/audit-exports/artifacts.jsonl'));
    $lines = explode("\n", $content);
    $row = json_decode($lines[0], true, 512, JSON_THROW_ON_ERROR);

    expect($lines)->toHaveCount(1);
    expect($row['action'])->toBe('artifact.purged');
    expect($row)->toHaveKeys(['sequence', 'chain_version', 'prev_hash', 'row_hash']);
    expect($row['sequence'])->toBe(1);
    expect($row['chain_version'])->toBe(1);
    expect($row['prev_hash'])->toBeNull();
    expect($row['row_hash'])->toBeString()->toHaveLength(64);
    expect($row['payload']['artifact_id'])->toBe('artifact_1');
    expect($row['payload']['token'])->toBe('[REDACTED]');
    expect($content)->not->toContain('wiki.updated');
    expect($content)->not->toContain('secret');
});

it('exposes an artisan command to export audit logs as csv', function () {
    createAuditLog('artifact.purged', ['artifact_id' => 'artifact_1']);
    createAuditLog('token.created', ['token_id' => 'token_1']);

    $exitCode = Artisan::call('devboard:audit-export', [
        '--format' => 'csv',
        '--action' => 'artifact.purged',
        '--path' => 'devboard/audit-exports/artifacts.csv',
    ]);

    expect($exitCode)->toBe(0);
    expect(Artisan::output())->toContain('Exported 1 audit record');

    $content = Storage::disk('local')->get('devboard/audit-exports/artifacts.csv');

    expect($content)->toContain('id,sequence,chain_version,prev_hash,row_hash,actor_type,action,target_type,target_id,created_at,payload');
    expect($content)->toContain('artifact.purged');
    expect($content)->not->toContain('token.created');
});

/**
 * @param  array<string, mixed>  $payload
 */
function createAuditLog(string $action, array $payload): void
{
    app(AuditLogger::class)->record($action, str($action)->before('.')->toString(), null, $payload, ['type' => 'system']);
}
