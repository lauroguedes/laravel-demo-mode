<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Config;
use LauroGuedes\DemoMode\Tests\TestCase;

pest()->extend(TestCase::class)->in('Unit', 'Feature', 'Integration', 'Arch');

/**
 * Put the installation into demo mode, the way a deployment would.
 *
 * Almost every test needs this, and spelling it out each time invites the
 * mistake of setting the flag without the environment that makes the flag
 * legal — a combination that exists only in tests and hides guard failures.
 *
 * @param  array<string, mixed>  $config
 */
function demo(array $config = []): void
{
    Config::set('demo.enabled', true);
    Config::set('app.env', 'demo');
    app()->detectEnvironment(fn (): string => 'demo');

    foreach ($config as $key => $value) {
        Config::set($key, $value);
    }
}
