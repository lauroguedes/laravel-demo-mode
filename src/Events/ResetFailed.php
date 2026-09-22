<?php

declare(strict_types=1);

namespace LauroGuedes\DemoMode\Events;

use Throwable;

/**
 * A reset threw.
 *
 * Fired after the application has been brought back up, not before — a listener
 * that pages someone should be telling them about a demo that is serving, with
 * whatever data survived, rather than about one that is still down.
 */
final readonly class ResetFailed
{
    public function __construct(
        public Throwable $exception,
        public string $strategy,
    ) {}
}
