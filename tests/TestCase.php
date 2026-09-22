<?php

declare(strict_types=1);

namespace LauroGuedes\DemoMode\Tests;

use Carbon\CarbonImmutable;
use LauroGuedes\DemoMode\DemoModeServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;
use Spatie\DbSnapshots\DbSnapshotsServiceProvider;

abstract class TestCase extends Orchestra
{
    /**
     * A frozen clock set in one test leaks into every later one in the process,
     * and half this suite is about what time the next reset is.
     */
    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();

        parent::tearDown();
    }

    /**
     * The snapshot package is a suggested dependency, so it is present here as a
     * dev requirement and absent in an application that never wanted it. Loading
     * it conditionally is what lets the snapshot strategy's tests be real while
     * the package itself keeps not requiring it.
     *
     * @return array<int, class-string>
     */
    protected function getPackageProviders($app): array
    {
        return array_values(array_filter([
            DemoModeServiceProvider::class,
            class_exists(DbSnapshotsServiceProvider::class) ? DbSnapshotsServiceProvider::class : null,
        ]));
    }

    /**
     * Everything runs on the in-memory sqlite connection by default. The
     * database CI job sets DB_CONNECTION to mysql or pgsql and runs the tests
     * tagged 'database' against a real server, because the reset strategies
     * shell out to a client and a dump that works on sqlite proves nothing about
     * the client's argument handling or the driver's DDL.
     *
     * Only the connection name is set here: testbench's own database config
     * already builds each connection from DB_HOST, DB_PORT, DB_DATABASE,
     * DB_USERNAME and DB_PASSWORD, which are the same variables the workflow
     * sets.
     */
    protected function defineEnvironment($app): void
    {
        $app['config']->set('demo.enabled', false);
        $app['config']->set('database.default', (string) (env('DB_CONNECTION') ?: 'testing'));
    }
}
