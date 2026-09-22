<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Config;
use LauroGuedes\DemoMode\Exceptions\InvalidConfiguration;
use LauroGuedes\DemoMode\Facades\Demo;
use LauroGuedes\DemoMode\Reset\Schedule;

it('reads the shortcuts', function (string $shortcut, string $cron): void {
    expect(Schedule::parse($shortcut)->expression)->toBe($cron);
})->with([
    ['hourly', '0 * * * *'],
    ['daily', '0 0 * * *'],
    ['weekly', '0 0 * * 0'],
    ['monthly', '0 0 1 * *'],
    ['every-6-hours', '0 */6 * * *'],
    ['every-1-hour', '0 */1 * * *'],
    ['HOURLY', '0 * * * *'],
    ['  daily  ', '0 0 * * *'],
]);

it('reads a raw cron expression', function (): void {
    expect(Schedule::parse('*/15 2 * * 1')->expression)->toBe('*/15 2 * * 1');
});

it('refuses something that is neither', function (string $nonsense): void {
    expect(fn (): Schedule => Schedule::parse($nonsense))->toThrow(InvalidConfiguration::class);
})->with(['every 6 hours', 'sometimes', 'every-0-hours', 'every-99-hours', '']);

/**
 * The bug this package was partly written to fix: a banner promising a reset
 * every 24 hours on a demo whose scheduler ran hourly. Both numbers came from
 * the same key and each side read it separately.
 */
it('gives the banner and the scheduler the same answer', function (): void {
    CarbonImmutable::setTestNow('2026-09-22 09:30:00');

    demo(['demo.reset.schedule' => 'hourly']);

    expect(Demo::schedule()->expression)->toBe('0 * * * *')
        ->and(Demo::nextResetAt()->toIso8601String())->toBe(
            Schedule::parse('hourly')->nextRunAt()->toIso8601String(),
        )
        ->and(Demo::nextResetAt()->format('H:i'))->toBe('10:00');

    CarbonImmutable::setTestNow();
});

it('answers nothing about a schedule when the installation is not a demo', function (): void {
    Config::set('demo.enabled', false);

    expect(Demo::schedule())->toBeNull()
        ->and(Demo::nextResetAt())->toBeNull()
        ->and(Demo::timeUntilReset())->toBeNull();
});

it('renders without a countdown rather than failing on an unreadable schedule', function (): void {
    demo(['demo.reset.schedule' => 'whenever']);

    expect(Demo::schedule())->toBeNull()
        ->and(Demo::toArray()['enabled'])->toBeTrue()
        ->and(Demo::toArray()['next_reset_at'])->toBeNull();
});
