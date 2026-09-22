<?php

declare(strict_types=1);

namespace LauroGuedes\DemoMode\Reset\Guards;

use LauroGuedes\DemoMode\Configuration;
use LauroGuedes\DemoMode\Contracts\ResetGuard;

/**
 * The installation has to say it is a demo.
 *
 * First guard and the one that matters most, because it is the only one whose
 * answer is a deliberate statement by the deployment rather than something
 * inferred. Nothing bypasses it — notably not --force, which exists to skip the
 * interactive confirmation and nothing else.
 *
 * Worth being explicit about why: an earlier hand-rolled version of this in one
 * of the author's projects read
 *
 *     if (! config('app.demo.enabled') && ! $this->option('force'))
 *
 * which means 'demo:reset --force' dropped every table on any installation that
 * had the command available. The flag is not a convenience to be overridden; it
 * is the statement that makes the reset legal.
 */
final readonly class DemoModeIsEnabled implements ResetGuard
{
    public function __construct(private Configuration $config) {}

    public function name(): string
    {
        return 'demo-mode-enabled';
    }

    public function check(): ?string
    {
        return $this->config->enabled()
            ? null
            : 'This installation is not a demo. Set DEMO_MODE=true to allow a reset. (--force does not bypass this.)';
    }
}
