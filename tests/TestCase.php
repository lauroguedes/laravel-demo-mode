<?php

declare(strict_types=1);

namespace LauroGuedes\DemoMode\Tests;

use LauroGuedes\DemoMode\DemoModeServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;

abstract class TestCase extends Orchestra
{
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
