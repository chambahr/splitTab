<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        if (config('app.env') !== 'testing' || config('database.default') !== 'pgsql' || config('database.connections.pgsql.database') !== 'splittrip_test') {
            throw new \RuntimeException('Safety stop: tests must use APP_ENV=testing and the dedicated splittrip_test PostgreSQL database.');
        }
    }
}
