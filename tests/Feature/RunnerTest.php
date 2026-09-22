<?php

declare(strict_types=1);

use Illuminate\Contracts\Foundation\MaintenanceMode;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Event;
use LauroGuedes\DemoMode\Events\ResetCompleted;
use LauroGuedes\DemoMode\Events\ResetFailed;
use LauroGuedes\DemoMode\Events\ResetStarting;
use LauroGuedes\DemoMode\Facades\Demo;
use LauroGuedes\DemoMode\Reset\Runner;
use LauroGuedes\DemoMode\Support\DestructiveCommands;
use LauroGuedes\DemoMode\Tests\Fixtures\SpyStrategy;

beforeEach(function (): void {
    SpyStrategy::reset();
    DestructiveCommands::prohibit(true);

    demo([
        'demo.reset.strategies.spy' => ['driver' => SpyStrategy::class],
        'demo.reset.strategy' => 'spy',
        'demo.cleaners' => [],
        'demo.credentials.enabled' => false,
    ]);
});

afterEach(function (): void {
    DestructiveCommands::prohibit(false);

    /* Maintenance mode is a file on disk, so it outlives the application. */
    if (app(MaintenanceMode::class)->active()) {
        app(MaintenanceMode::class)->deactivate();
    }
});

it('permits destructive commands while the strategy runs, and not after', function (): void {
    app(Runner::class)->run();

    expect(SpyStrategy::$prohibitedWhenRun)->toBeFalse()
        ->and(SpyStrategy::prohibited())->toBeTrue();
});

/**
 * The window is the reason an application never has to disable the prohibition
 * globally to have a demo. If a throw could leave it down, a failed reset would
 * hand every later process in this run a working migrate:fresh.
 */
it('restores the prohibition even when the strategy throws', function (): void {
    SpyStrategy::$throws = new RuntimeException('the database went away');

    expect(fn (): mixed => app(Runner::class)->run())->toThrow(RuntimeException::class)
        ->and(SpyStrategy::prohibited())->toBeTrue();
});

it('leaves maintenance mode even when the strategy throws', function (): void {
    Config::set('demo.reset.maintenance', true);
    SpyStrategy::$throws = new RuntimeException('the seeder blew up');

    expect(fn (): mixed => app(Runner::class)->run())->toThrow(RuntimeException::class)
        ->and(app(MaintenanceMode::class)->active())->toBeFalse();
});

it('leaves an application that was already down exactly as it found it', function (): void {
    Config::set('demo.reset.maintenance', true);
    app(MaintenanceMode::class)->activate(['retry' => 60]);

    app(Runner::class)->run();

    expect(app(MaintenanceMode::class)->active())->toBeTrue();
});

it('fires the failure event after bringing the application back up', function (): void {
    Event::fake([ResetStarting::class, ResetCompleted::class, ResetFailed::class]);
    Config::set('demo.reset.maintenance', true);
    SpyStrategy::$throws = new RuntimeException('nope');

    expect(fn (): mixed => app(Runner::class)->run())->toThrow(RuntimeException::class);

    Event::assertDispatched(ResetStarting::class);
    Event::assertDispatched(ResetFailed::class);
    Event::assertNotDispatched(ResetCompleted::class);

    expect(app(MaintenanceMode::class)->active())->toBeFalse();
});

it('reports what it did without ever carrying a password', function (): void {
    Config::set('demo.credentials.enabled', true);
    Config::set('demo.credentials.store', 'cache');

    $report = app(Runner::class)->run();

    expect($report->credentialsRotated)->toBe(1)
        ->and(json_encode($report->toArray()))->not->toContain(Demo::credentials()['password']);
});

it('plans a dry run without touching anything', function (): void {
    $report = app(Runner::class)->run(['dry-run' => true]);

    expect($report->dryRun)->toBeTrue()
        ->and(SpyStrategy::$runs)->toBe(0)
        ->and($report->steps)->not->toBeEmpty();
});

it('records when it last reset', function (): void {
    expect(Demo::lastResetAt())->toBeNull();

    app(Runner::class)->run();

    expect(Demo::lastResetAt())->not->toBeNull();
});
