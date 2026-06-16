<?php

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
