<?php

namespace Tests;

use Illuminate\Foundation\Application;
use Tests\Support\TestDatabaseGuard;

abstract class PostgresTestCase extends TestCase
{
    protected function assertSafeTestDatabase(Application $app): void
    {
        TestDatabaseGuard::assertPostgresAcceptance((array) $app['config']->get('database'));
    }
}
