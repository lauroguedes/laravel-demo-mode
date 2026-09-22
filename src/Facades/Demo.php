<?php

declare(strict_types=1);

namespace LauroGuedes\DemoMode\Facades;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterval;
use Closure;
use Illuminate\Support\Facades\Facade;
use LauroGuedes\DemoMode\DemoMode;
use LauroGuedes\DemoMode\Reset\ResetReport;
use LauroGuedes\DemoMode\Reset\Schedule;

/**
 * @method static bool enabled()
 * @method static bool disabled()
 * @method static mixed when(Closure $callback)
 * @method static mixed unless(Closure $callback)
 * @method static CarbonImmutable|null nextResetAt()
 * @method static CarbonImmutable|null lastResetAt()
 * @method static CarbonInterval|null timeUntilReset()
 * @method static ResetReport reset(array{strategy?: string, seeder?: string, maintenance?: bool, dry-run?: bool} $options = [], Closure|null $output = null)
 * @method static array{email: string, password: string, label: string|null}|null credentials()
 * @method static list<array{email: string, password: string, label: string|null, primary: bool}> allCredentials()
 * @method static list<array{email: string, password: string, label: string|null, primary: bool}> rotate()
 * @method static array<string, mixed> toArray()
 * @method static array<string, mixed>|null toBanner()
 * @method static Schedule|null schedule()
 *
 * @see DemoMode
 */
final class Demo extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return DemoMode::class;
    }
}
