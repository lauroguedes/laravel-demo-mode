<?php

declare(strict_types=1);

namespace LauroGuedes\DemoMode\Events;

use LauroGuedes\DemoMode\Reset\ResetReport;

/**
 * A reset finished and the application is back up.
 *
 * Where a project hangs whatever its demo needs on top: warming a cache,
 * notifying a status page, posting the new credentials to a private channel.
 */
final readonly class ResetCompleted
{
    public function __construct(public ResetReport $report) {}
}
