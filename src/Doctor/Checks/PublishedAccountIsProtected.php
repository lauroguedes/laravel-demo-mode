<?php

declare(strict_types=1);

namespace LauroGuedes\DemoMode\Doctor\Checks;

use LauroGuedes\DemoMode\Configuration;
use LauroGuedes\DemoMode\Contracts\RunsOnDemosOnly;
use LauroGuedes\DemoMode\Doctor\Finding;
use LauroGuedes\DemoMode\Exceptions\InvalidConfiguration;
use LauroGuedes\DemoMode\Guards\ProtectedRecords;

/**
 * Somebody has to stop the first visitor locking everybody else out.
 *
 * The concrete failure: a visitor signs in with the published credentials, opens
 * the profile page, and changes the email or the password. Both are ordinary
 * features working correctly. From that moment until the next scheduled reset,
 * the demo's login page shows credentials that do not work, and nobody else can
 * get in — which on a six-hour cycle means the demo is down for up to six hours
 * because one visitor did something entirely reasonable.
 *
 * Neither of the two implementations this package was extracted from covered
 * this, so it is worth the command saying so out loud.
 */
final readonly class PublishedAccountIsProtected implements RunsOnDemosOnly
{
    public function __construct(
        private Configuration $config,
        private ProtectedRecords $protected,
    ) {}

    public function run(): array
    {
        if (! $this->config->boolean('credentials.enabled', true)) {
            return [];
        }

        try {
            $models = $this->protected->models();
        } catch (InvalidConfiguration $e) {
            /*
             * Reported rather than thrown, because this command is where a
             * demo's configuration is meant to be examined. Letting it crash
             * would mean the one tool for finding the problem could not run.
             */
            return [Finding::error('protected-accounts', $e->getMessage())];
        }

        if ($models !== []) {
            return [];
        }

        return [Finding::warning(
            'protected-accounts',
            'Nothing is protected from writes, so a visitor can change the published account\'s email or password '
                .'and lock every later visitor out until the next reset.',
            'Add the published account to demo.guards.protected, for example '
                .'[App\\Models\\User::class => [\'email\' => \'admin@demo.test\']].',
        )];
    }
}
