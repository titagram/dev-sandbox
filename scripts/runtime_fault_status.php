<?php

require __DIR__.'/../backend/vendor/autoload.php';
$app = require __DIR__.'/../backend/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\DB;

$importId = $argv[1];
$runId = $argv[2];

echo json_encode([
    'import_status' => DB::table('genesis_imports')->where('id', $importId)->value('status'),
    'graph_imported_event' => DB::table('run_events')->where('run_id', $runId)->where('event_type', 'graph.imported')->exists(),
    'graph_import_failed_event' => DB::table('run_events')->where('run_id', $runId)->where('event_type', 'graph.import_failed')->exists(),
    'job_rows' => DB::table('jobs')->count(),
    'failed_jobs' => DB::table('failed_jobs')->count(),
], JSON_THROW_ON_ERROR);
