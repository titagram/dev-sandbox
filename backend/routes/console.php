<?php

use App\Services\ArtifactRetentionService;
use App\Services\AuditExportService;
use App\Services\Neo4jRebuildService;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Artisan::command('devboard:neo4j-rebuild {--project=} {--repository=} {--snapshot=} {--mode=}', function () {
    $mode = $this->option('mode') ?: null;

    if ($mode !== null && ! in_array($mode, ['fake', 'neo4j'], true)) {
        $this->error('Invalid --mode value. Use fake or neo4j.');

        return 1;
    }

    $result = app(Neo4jRebuildService::class)->rebuild([
        'project_id' => $this->option('project'),
        'repository_id' => $this->option('repository'),
        'snapshot_id' => $this->option('snapshot'),
    ], mode: $mode);

    $this->info("Scanned {$result['scanned']} graph snapshot(s).");
    $this->info("Rebuilt {$result['rebuilt']} graph projection(s).");

    if ($result['skipped'] > 0) {
        $this->warn("Skipped {$result['skipped']} graph snapshot(s) without a validated/imported artifact.");
    }

    foreach ($result['failures'] as $failure) {
        $this->error("Failed {$failure['snapshot_id']}: {$failure['message']}");
    }

    return $result['failed'] === 0 ? 0 : 1;
})->purpose('Rebuild the Neo4j projection from stored DevBoard graph artifacts');

Artisan::command('devboard:artifacts-retain {--days=} {--dry-run} {--limit=}', function () {
    $days = (int) ($this->option('days') ?: config('services.devboard.artifact_retention_days', 90));
    $limitOption = $this->option('limit');
    $limit = $limitOption === null ? null : (int) $limitOption;

    if ($days < 1) {
        $this->error('Invalid --days value. Use an integer greater than zero.');

        return 1;
    }

    if ($limit !== null && $limit < 1) {
        $this->error('Invalid --limit value. Use an integer greater than zero.');

        return 1;
    }

    $dryRun = (bool) $this->option('dry-run');
    $result = app(ArtifactRetentionService::class)->purgeOlderThan($days, $dryRun, $limit);

    $this->info("Scanned {$result['scanned']} artifact(s).");

    if ($dryRun) {
        $this->info("Would purge {$result['would_purge']} artifact(s).");
    } else {
        $this->info("Purged {$result['purged']} artifact(s).");
    }

    if ($result['skipped'] > 0) {
        $this->warn("Skipped {$result['skipped']} artifact(s) pinned by current workspace snapshots.");
    }

    foreach ($result['failures'] as $failure) {
        $this->error("Failed {$failure['artifact_id']}: {$failure['message']}");
    }

    return $result['failed'] === 0 ? 0 : 1;
})->purpose('Purge retained artifact contents after the configured retention window');

Artisan::command('devboard:audit-export {--format=jsonl} {--path=} {--action=} {--actor-type=} {--from=} {--to=}', function () {
    $format = $this->option('format') ?: 'jsonl';

    if (! in_array($format, ['jsonl', 'csv'], true)) {
        $this->error('Invalid --format value. Use jsonl or csv.');

        return 1;
    }

    $result = app(AuditExportService::class)->export([
        'action' => $this->option('action'),
        'actor_type' => $this->option('actor-type'),
        'from' => $this->option('from'),
        'to' => $this->option('to'),
    ], $format, $this->option('path') ?: null);

    $this->info("Exported {$result['exported']} audit record(s) to {$result['path']}.");
    $this->info("SHA-256: {$result['sha256']}");

    return 0;
})->purpose('Export sanitized audit logs to local JSONL or CSV storage');
