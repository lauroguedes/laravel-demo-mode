<?php

declare(strict_types=1);

namespace LauroGuedes\DemoMode\Events;

/**
 * Something a visitor tried to change was kept back.
 *
 * Telemetry for how much the demo is being pushed at, and the seam for an
 * application that wants to show its own message rather than an error page.
 *
 * Fires on every block, including the connection guard, which on a misconfigured
 * demo can be every request. Logging is behind demo.log.blocked_writes for
 * exactly that reason; the event is always dispatched so an application can
 * sample it rather than being handed a log file it did not ask for.
 */
final readonly class WriteBlocked
{
    public function __construct(
        public string $layer,
        public string $subject,
        public string $reason,
    ) {}
}
