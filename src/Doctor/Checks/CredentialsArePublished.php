<?php

declare(strict_types=1);

namespace LauroGuedes\DemoMode\Doctor\Checks;

use Carbon\CarbonImmutable;
use LauroGuedes\DemoMode\Contracts\RunsOnDemosOnly;
use LauroGuedes\DemoMode\Credentials\Manager as Credentials;
use LauroGuedes\DemoMode\DemoMode;
use LauroGuedes\DemoMode\Doctor\Finding;

/**
 * A demo nobody can sign in to still looks like a working demo.
 *
 * Everything else about it is right: the flag is on, the banner counts down, the
 * environment and the host pass their guards, and demo:doctor had nothing to say.
 * The login page renders with two empty boxes, because a reset is what publishes
 * a password and no reset has happened.
 *
 * That is not hypothetical. It is how a staging demo spent its first hours: the
 * only trace was "Last reset: not yet" and "Accounts published: 0" in demo:status,
 * two lines you have to already suspect something to go and read. Nothing here
 * looked at either.
 *
 * Warnings rather than errors, for two reasons. This command is documented as
 * belonging in a deploy pipeline *ahead of* the first reset, so a fresh
 * deployment legitimately has nothing published and erroring would fail the very
 * usage the README recommends. And doctor's error contract is about destroying
 * data or publishing a secret; an empty login form is neither. It is just
 * invisible, which is what this fixes.
 */
final readonly class CredentialsArePublished implements RunsOnDemosOnly
{
    public function __construct(
        private Credentials $credentials,
        private DemoMode $demo,
    ) {}

    /**
     * @return list<Finding>
     */
    public function run(): array
    {
        if (! $this->credentials->publishes() || $this->credentials->all() !== []) {
            return [];
        }

        $lastReset = $this->demo->lastResetAt();

        if (! $lastReset instanceof CarbonImmutable) {
            return [Finding::warning(
                'credentials-published',
                'This demo publishes credentials but none are published yet, and no reset has ever run. Until one '
                    .'does, the login page offers a visitor two empty boxes.',
                'php artisan demo:reset --force — and check something actually runs schedule:run, or it will not happen again.',
            )];
        }

        /*
         * Read from wherever this command is running, which is the whole
         * difficulty: the 'file' store writes to one machine's disk, so a reset
         * performed in a deploy container or on another node publishes nothing
         * the web process can see, and each of them is telling the truth about
         * itself. Hence the reminder rather than a confident diagnosis.
         */
        return [Finding::warning(
            'credentials-published',
            sprintf(
                'A reset ran at %s but no credentials are published where this command can see them, so the login '
                    .'page offers a visitor two empty boxes.',
                $lastReset->toIso8601String(),
            ),
            'Run this on the host that serves the demo. If it is clean there, the reset wrote to a different disk '
                .'than the web process reads — a shared store (cache) is the fix for more than one machine.',
        )];
    }
}
