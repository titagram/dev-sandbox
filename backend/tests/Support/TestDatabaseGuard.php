<?php

namespace Tests\Support;

use Illuminate\Support\ConfigurationUrlParser;
use RuntimeException;
use Throwable;

final class TestDatabaseGuard
{
    /** @param array<string, mixed> $databaseConfig */
    public static function assertSqliteMemory(array $databaseConfig): void
    {
        [$driver, $database] = self::effectiveConnection($databaseConfig);

        if ($driver !== 'sqlite' || $database !== ':memory:') {
            throw new RuntimeException(sprintf(
                'Refusing to run tests against effective database [%s:%s]. Default tests require [sqlite::memory:].',
                $driver,
                $database,
            ));
        }
    }

    /** @param array<string, mixed> $databaseConfig */
    public static function assertPostgresAcceptance(array $databaseConfig): void
    {
        [$driver, $database] = self::effectiveConnection($databaseConfig);

        if ($driver !== 'pgsql' || $database !== 'devboard_acceptance') {
            throw new RuntimeException(sprintf(
                'Refusing to run PostgreSQL acceptance tests against effective database [%s:%s]. Required database is [pgsql:devboard_acceptance].',
                $driver,
                $database,
            ));
        }
    }

    /**
     * Resolve the connection exactly as Laravel's DatabaseManager does, including
     * the URL override. This method intentionally never opens a database connection.
     *
     * @param  array<string, mixed>  $databaseConfig
     * @return array{string, string}
     */
    private static function effectiveConnection(array $databaseConfig): array
    {
        $connectionName = $databaseConfig['default'] ?? null;
        $connections = $databaseConfig['connections'] ?? null;

        if (! is_string($connectionName) || $connectionName === '' || ! is_array($connections)) {
            throw new RuntimeException('Refusing to run tests because the effective database connection cannot be determined.');
        }

        $connection = $connections[$connectionName] ?? null;

        if (! is_array($connection)) {
            throw new RuntimeException('Refusing to run tests because the effective database connection cannot be determined.');
        }

        try {
            $effective = (new ConfigurationUrlParser)->parseConfiguration($connection);
        } catch (Throwable $exception) {
            throw new RuntimeException(
                'Refusing to run tests because the effective database URL is invalid.',
                previous: $exception,
            );
        }

        $driver = $effective['driver'] ?? null;
        $database = $effective['database'] ?? null;

        if (! is_string($driver) || $driver === '' || ! is_string($database) || $database === '') {
            throw new RuntimeException('Refusing to run tests because the effective database connection cannot be determined.');
        }

        return [$driver, $database];
    }
}
