<?php

declare(strict_types=1);

namespace LauroGuedes\DemoMode\Tests;

use Carbon\CarbonImmutable;
use LauroGuedes\DemoMode\DemoModeServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;

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
     * @return array<int, class-string>
     */
    protected function getPackageProviders($app): array
    {
        return [DemoModeServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('database.default', 'testing');
        $app['config']->set('demo.enabled', false);
    }
}
