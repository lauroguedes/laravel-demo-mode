<?php

declare(strict_types=1);

namespace LauroGuedes\DemoMode\Events;

/**
 * A reset passed its guards, holds the lock, and is about to act.
 *
 * The last moment at which the old data still exists. A listener that wants to
 * keep something from the outgoing demo has to do it here.
 */
final readonly class ResetStarting
{
    public function __construct(public string $strategy) {}
}
