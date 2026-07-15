<?php

function composeWorkerBlock(string $contents): string
{
    $start = strpos($contents, "  worker:\n");
    expect($start)->not->toBeFalse();

    $end = strpos($contents, "\n  scheduler:\n", $start);
    expect($end)->not->toBeFalse();

    return substr($contents, $start, $end - $start);
}

it('keeps the database retry window above the worker timeout and restarts the worker', function (): void {
    $root = dirname(__DIR__, 3);
    $development = file_get_contents($root.'/docker-compose.devboard.yaml');
    $production = file_get_contents($root.'/docker-compose.devboard.prod.yaml');

    expect($development)->not->toBeFalse()
        ->and($production)->not->toBeFalse();

    $developmentWorker = composeWorkerBlock($development);
    $productionWorker = composeWorkerBlock($production);

    expect($developmentWorker)
        ->toContain('restart: unless-stopped')
        ->toContain('--timeout=1800')
        ->toContain('DB_QUEUE_RETRY_AFTER: "1900"');

    expect($productionWorker)
        ->toContain('restart: unless-stopped')
        ->toContain('"--timeout=1800"');

    expect($production)
        ->toContain('DB_QUEUE_RETRY_AFTER: ${DEVBOARD_DB_QUEUE_RETRY_AFTER:-1900}');
});
