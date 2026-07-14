<?php

namespace Tests;

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Tests\Support\TestDatabaseGuard;

abstract class TestCase extends BaseTestCase
{
    public function createApplication(): Application
    {
        $app = parent::createApplication();
        $this->assertSafeTestDatabase($app);

        return $app;
    }

    protected function assertSafeTestDatabase(Application $app): void
    {
        TestDatabaseGuard::assertSqliteMemory((array) $app['config']->get('database'));
    }
}
