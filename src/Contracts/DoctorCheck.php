<?php

declare(strict_types=1);

namespace LauroGuedes\DemoMode\Contracts;

use LauroGuedes\DemoMode\Doctor\Finding;

/**
 * One audit of a demo's configuration.
 *
 * Checks are read-only and run on any installation, demo or not — a check that
 * only ran on a correctly configured demo could not tell you why yours is not
 * one. Each returns everything it found rather than the first thing, for the
 * same reason the guard chain does: setting up a demo should take one run of the
 * command, not one run per problem.
 */
interface DoctorCheck
{
    /**
     * @return list<Finding>
     */
    public function run(): array;
}
