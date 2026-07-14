<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

final class TestDatabaseIsolationContractTest extends TestCase
{
    public function test_default_test_case_accepts_only_effective_sqlite_memory(): void
    {
        $safe = $this->runBootProbe([
            'DB_CONNECTION' => 'sqlite',
            'DB_DATABASE' => ':memory:',
            'DB_URL' => '',
        ]);

        self::assertSame(0, $safe->getExitCode(), $safe->getErrorOutput());

        $urlOverride = $this->runBootProbe([
            'DB_CONNECTION' => 'sqlite',
            'DB_DATABASE' => ':memory:',
            'DB_URL' => 'postgresql://guard:unused@127.0.0.1:1/devboard_acceptance',
        ]);

        self::assertNotSame(0, $urlOverride->getExitCode());
        self::assertStringContainsString('Refusing to run tests against effective database [pgsql:devboard_acceptance]', $urlOverride->getErrorOutput());
    }

    public function test_global_marker_cannot_authorize_a_non_sqlite_default_test_case(): void
    {
        $process = $this->runBootProbe([
            'DB_CONNECTION' => 'pgsql',
            'DB_DATABASE' => 'devboard',
            'DB_URL' => '',
            'DEVBOARD_ALLOW_POSTGRES_ACCEPTANCE' => '1',
        ]);

        self::assertNotSame(0, $process->getExitCode());
        self::assertStringContainsString('Refusing to run tests against effective database [pgsql:devboard]', $process->getErrorOutput());
    }

    public function test_postgres_composer_path_has_a_preflight_before_migrate_fresh(): void
    {
        $backendRoot = dirname(__DIR__, 2);
        $composer = json_decode((string) file_get_contents($backendRoot.'/composer.json'), true, flags: JSON_THROW_ON_ERROR);
        $commands = (array) ($composer['scripts']['test:postgres'] ?? []);
        $commandText = implode("\n", $commands);

        self::assertStringContainsString('scripts/run_postgres_acceptance.sh', $commandText);

        $runnerPath = $backendRoot.'/scripts/run_postgres_acceptance.sh';
        self::assertFileExists($runnerPath);

        $runner = (string) file_get_contents($runnerPath);
        $preflight = strpos($runner, 'assert_postgres_acceptance_database.php');
        $migration = strpos($runner, 'migrate:fresh');

        self::assertNotFalse($preflight);
        self::assertNotFalse($migration);
        self::assertLessThan($migration, $preflight, 'The database preflight must run before migrate:fresh.');
    }

    public function test_postgres_preflight_exits_nonzero_for_an_unsafe_database(): void
    {
        $backendRoot = dirname(__DIR__, 2);
        $process = new Process(
            [PHP_BINARY, 'tests/Support/assert_postgres_acceptance_database.php'],
            $backendRoot,
            [
                'APP_ENV' => 'testing',
                'APP_KEY' => 'base64:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA=',
                'CACHE_STORE' => 'array',
                'DB_CONNECTION' => 'pgsql',
                'DB_DATABASE' => 'devboard',
                'DB_URL' => '',
            ],
        );
        $process->setTimeout(30);
        $process->run();

        self::assertNotSame(0, $process->getExitCode());
        self::assertStringContainsString(
            'Required database is [pgsql:devboard_acceptance]',
            $process->getErrorOutput().$process->getOutput(),
        );
    }

    /** @param array<string, string> $environment */
    private function runBootProbe(array $environment): Process
    {
        $backendRoot = dirname(__DIR__, 2);
        $process = new Process([PHP_BINARY, 'tests/Fixtures/boot_test_case.php'], $backendRoot, [
            'APP_ENV' => 'testing',
            'APP_KEY' => 'base64:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA=',
            'CACHE_STORE' => 'array',
            ...$environment,
        ]);
        $process->setTimeout(30);
        $process->run();

        return $process;
    }
}
