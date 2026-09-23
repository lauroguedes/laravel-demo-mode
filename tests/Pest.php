<?php

declare(strict_types=1);

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Storage;
use LauroGuedes\DemoMode\Credentials\Credential;
use LauroGuedes\DemoMode\Credentials\Manager;
use LauroGuedes\DemoMode\DemoModeServiceProvider;
use LauroGuedes\DemoMode\Reset\ResetContext;
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

/**
 * A demo with credentials already published, on a faked private disk.
 *
 * The preamble for anything that reads what a visitor would sign in with.
 *
 * @param  array<string, mixed>  $config
 * @return list<Credential>
 */
function published(array $config = []): array
{
    Storage::fake('local');

    demo($config);

    return app(Manager::class)->rotate();
}

/**
 * A ResetContext for exercising one strategy on its own.
 *
 * @param  array<string, mixed>  $options
 */
function resetContext(?Kernel $artisan = null, array $options = [], ?string $connection = null): ResetContext
{
    return new ResetContext(
        app: app(),
        artisan: $artisan ?? app(Kernel::class),
        connection: $connection,
        options: $options,
        output: static fn (string $message): null => null,
    );
}

/**
 * The config key holding the current connection's database name.
 *
 * The suite runs on sqlite by default and on MySQL or PostgreSQL in the database
 * job, so a test that named one connection passed only on that one.
 */
function databaseNameKey(): string
{
    return 'database.connections.'.config('database.default').'.database';
}

/**
 * A known-good schema for the tests that touch one.
 *
 * migrate:fresh rather than migrate, because several tests drop a table on
 * purpose and a persistent database would not bring it back — the migration is
 * already recorded as run. On the in-memory sqlite the suite uses by default the
 * difference is invisible, which is exactly why it only ever showed up on MySQL.
 */
function freshSchema(): void
{
    app('migrator')->path(__DIR__.'/../workbench/database/migrations');

    test()->artisan('migrate:fresh', ['--force' => true]);
}
