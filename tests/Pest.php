<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Config;
use LauroGuedes\DemoMode\DemoModeServiceProvider;
use LauroGuedes\DemoMode\Tests\TestCase;

pest()->extend(TestCase::class)->in('Unit', 'Feature', 'Integration', 'Arch');

/**
 * Put the installation into demo mode, the way a deployment would.
 *
 * Two things are deliberate here. It sets the environment as well as the flag,
 * because a test that set only the flag would be describing a state no real
 * deployment reaches — a demo in an environment its own guards refuse — and would
 * quietly pass on code that never ran.
 *
 * It also re-registers the provider afterwards, because the provider decides what
 * to register by asking whether this is a demo, and it asked before the test set
 * the flag. Without this, the reset command and the restrictions would be absent
 * from every test that thought it had turned the demo on.
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

    app()->register(DemoModeServiceProvider::class, force: true);
}
