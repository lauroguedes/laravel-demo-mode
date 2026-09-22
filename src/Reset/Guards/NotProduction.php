<?php

declare(strict_types=1);

namespace LauroGuedes\DemoMode\Reset\Guards;

use Illuminate\Contracts\Foundation\Application;
use LauroGuedes\DemoMode\Configuration;
use LauroGuedes\DemoMode\Contracts\ResetGuard;

/**
 * Production refuses, unless somebody wrote 'production' down on purpose.
 *
 * Overlaps EnvironmentIsAllowed by design. That one checks membership of a list
 * a developer edits; this one names the single environment where being wrong is
 * unrecoverable, and makes allowing it an act somebody has to type rather than a
 * list they extended without reading.
 *
 * The seeder this command runs creates an account whose password is published on
 * a web page. There is no configuration of a production application where that
 * is correct by accident.
 */
final readonly class NotProduction implements ResetGuard
{
    public function __construct(
        private Configuration $config,
        private Application $app,
    ) {}

    public function name(): string
    {
        return 'not-production';
    }

    public function check(): ?string
    {
        if (! $this->app->isProduction()) {
            return null;
        }

        if (in_array('production', $this->config->strings('environments'), true)) {
            return null;
        }

        return 'APP_ENV is production. This reset drops every table and seeds an account whose password is published. '
            .'If that is genuinely what you want, add "production" to demo.environments.';
    }
}
