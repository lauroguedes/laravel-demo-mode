<?php

declare(strict_types=1);

namespace LauroGuedes\DemoMode\Reset\Guards;

use Illuminate\Contracts\Foundation\Application;
use LauroGuedes\DemoMode\Configuration;
use LauroGuedes\DemoMode\Contracts\ResetGuard;

/**
 * The environment has to be on the list.
 *
 * Independent of the flag on purpose. A demo .env is a file, and files get
 * copied: onto a colleague's laptop, into a staging box, into a container image
 * that ends up somewhere nobody meant. The flag travels with the file. The
 * environment usually does not, so this is the guard that catches the copy.
 *
 * An empty list refuses everywhere, which is the safe reading of "nothing was
 * configured" for a command that drops tables.
 */
final readonly class EnvironmentIsAllowed implements ResetGuard
{
    public function __construct(
        private Configuration $config,
        private Application $app,
    ) {}

    public function name(): string
    {
        return 'environment-allowed';
    }

    public function check(): ?string
    {
        $allowed = $this->config->strings('environments');

        if ($allowed === []) {
            return 'No environments are allowed to reset. Set demo.environments to the environments this demo runs in.';
        }

        $current = $this->app->environment();

        if (in_array($current, $allowed, true)) {
            return null;
        }

        return sprintf(
            'APP_ENV is [%s], which is not in demo.environments [%s].',
            $current,
            implode(', ', $allowed),
        );
    }
}
