<?php

declare(strict_types=1);

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Config;
use LauroGuedes\DemoMode\DemoModeServiceProvider;
use LauroGuedes\DemoMode\Exceptions\ResetRefused;
use LauroGuedes\DemoMode\Reset\Runner;
use LauroGuedes\DemoMode\Tests\Fixtures\SpyStrategy;

/**
 * The test that makes this package safe to install.
 *
 * Every combination of the things a deployment can get wrong, asserted against
 * whether a reset is allowed to proceed — and proved by whether a strategy was
 * called at all, not by whether an exception looked right. A guard that reports a
 * refusal and runs anyway would pass a test written the other way round.
 */
beforeEach(function (): void {
    SpyStrategy::reset();

    Config::set('demo.reset.strategies.spy', ['driver' => SpyStrategy::class]);
    Config::set('demo.reset.strategy', 'spy');
    Config::set('demo.reset.maintenance', false);
    Config::set('demo.cleaners', []);
    Config::set('demo.credentials.enabled', false);
});

dataset('guard matrix', [
    'a demo in a listed environment' => [
        ['enabled' => true, 'env' => 'demo', 'environments' => ['local', 'staging', 'demo'], 'hosts' => null],
        true,
    ],
    'the flag off' => [
        ['enabled' => false, 'env' => 'demo', 'environments' => ['demo'], 'hosts' => null],
        false,
    ],
    'the flag off in local' => [
        ['enabled' => false, 'env' => 'local', 'environments' => ['local'], 'hosts' => null],
        false,
    ],
    'an environment not on the list' => [
        ['enabled' => true, 'env' => 'staging', 'environments' => ['demo'], 'hosts' => null],
        false,
    ],
    'an empty environment list' => [
        ['enabled' => true, 'env' => 'demo', 'environments' => [], 'hosts' => null],
        false,
    ],
    'production, unlisted' => [
        ['enabled' => true, 'env' => 'production', 'environments' => ['local', 'demo'], 'hosts' => null],
        false,
    ],
    'production, listed but still production' => [
        ['enabled' => true, 'env' => 'production', 'environments' => ['production'], 'hosts' => null],
        true,
    ],
    'the wrong host' => [
        ['enabled' => true, 'env' => 'demo', 'environments' => ['demo'], 'hosts' => ['demo.example.com'], 'url' => 'https://app.example.com'],
        false,
    ],
    'the right host' => [
        ['enabled' => true, 'env' => 'demo', 'environments' => ['demo'], 'hosts' => ['demo.example.com'], 'url' => 'https://demo.example.com'],
        true,
    ],
    'the right host in a different case' => [
        ['enabled' => true, 'env' => 'demo', 'environments' => ['demo'], 'hosts' => ['Demo.Example.com'], 'url' => 'https://demo.example.com'],
        true,
    ],
    'an empty host allowlist' => [
        ['enabled' => true, 'env' => 'demo', 'environments' => ['demo'], 'hosts' => [], 'url' => 'https://demo.example.com'],
        false,
    ],
    'an unparseable APP_URL against an allowlist' => [
        ['enabled' => true, 'env' => 'demo', 'environments' => ['demo'], 'hosts' => ['demo.example.com'], 'url' => 'not a url'],
        false,
    ],
    'everything wrong at once' => [
        ['enabled' => false, 'env' => 'production', 'environments' => [], 'hosts' => [], 'url' => 'https://app.example.com'],
        false,
    ],
]);

it('permits a reset only when every guard is satisfied', function (array $scenario, bool $permitted): void {
    applyScenario($scenario);

    $run = fn (): mixed => app(Runner::class)->run();

    if ($permitted) {
        $run();

        expect(SpyStrategy::$runs)->toBe(1);

        return;
    }

    expect($run)->toThrow(ResetRefused::class)
        ->and(SpyStrategy::$runs)->toBe(0);
})->with('guard matrix');

/**
 * --force is the one thing people reach for when a guard refuses, so what it
 * does has to be exactly one thing. It skips the confirmation prompt. It is not
 * an override, and this asserts that for every configuration the matrix refuses.
 *
 * Two shapes of refusal count, and both are correct. On an installation that
 * never said it was a demo the command is not registered at all, so there is
 * nothing to pass --force to. On a demo that fails a later guard the command
 * exists, runs, and returns a failure. What must never happen either way is the
 * strategy being called.
 */
it('does not let --force past any guard', function (array $scenario, bool $permitted): void {
    applyScenario($scenario);

    $registered = array_key_exists('demo:reset', app(Kernel::class)->all());

    if (! $permitted && ! $registered) {
        expect($registered)->toBeFalse()
            ->and(SpyStrategy::$runs)->toBe(0);

        return;
    }

    expect($registered)->toBeTrue();

    $this->artisan('demo:reset', ['--force' => true])
        ->{$permitted ? 'assertSuccessful' : 'assertFailed'}();

    expect(SpyStrategy::$runs)->toBe($permitted ? 1 : 0);
})->with('guard matrix');

/**
 * @param  array<string, mixed>  $scenario
 */
function applyScenario(array $scenario): void
{
    $env = is_string($scenario['env']) ? $scenario['env'] : 'testing';

    Config::set('demo.enabled', $scenario['enabled']);
    Config::set('demo.environments', $scenario['environments']);
    Config::set('demo.allowed_hosts', $scenario['hosts']);
    Config::set('app.env', $env);
    Config::set('app.url', $scenario['url'] ?? 'http://localhost');

    app()->detectEnvironment(fn (): string => $env);
    app()->register(DemoModeServiceProvider::class, force: true);
}

/**
 * A strategy that says it cannot run must stop the reset, not merely report it
 * in an advisory command somebody may never have run. --force does not skip it,
 * for the same reason it does not skip the guards.
 */
it('refuses when the strategy says it cannot run', function (): void {
    applyScenario([
        'enabled' => true, 'env' => 'demo', 'environments' => ['demo'], 'hosts' => null,
    ]);

    SpyStrategy::$problems = ['The baseline has not been taken.'];

    expect(fn (): mixed => app(Runner::class)->run())
        ->toThrow(ResetRefused::class, 'The baseline has not been taken.')
        ->and(SpyStrategy::$runs)->toBe(0);
});

it('does not let --force past a strategy that says it cannot run', function (): void {
    applyScenario([
        'enabled' => true, 'env' => 'demo', 'environments' => ['demo'], 'hosts' => null,
    ]);

    SpyStrategy::$problems = ['The database client is not on the PATH.'];

    $this->artisan('demo:reset', ['--force' => true])->assertFailed();

    expect(SpyStrategy::$runs)->toBe(0);
});
