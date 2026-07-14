<?php

use Tests\TestCase;

require dirname(__DIR__, 2).'/vendor/autoload.php';

$testCase = new class('database_guard_probe') extends TestCase {};

try {
    $testCase->createApplication();
} catch (Throwable $exception) {
    fwrite(STDERR, $exception->getMessage().PHP_EOL);
    exit(1);
}
