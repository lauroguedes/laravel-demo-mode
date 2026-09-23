<?php

declare(strict_types=1);

namespace LauroGuedes\DemoMode\Guards;

use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Log\LogManager;
use LauroGuedes\DemoMode\Configuration;
use LauroGuedes\DemoMode\Events\WriteBlocked;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * What happens when any of the three guards says no.
 *
 * One owner, because all three need to do the same two things and a demo with
 * three slightly different answers to "was that logged" is a demo nobody can
 * reason about.
 *
 * The event always fires; the log line is behind demo.log.blocked_writes. That
 * split is deliberate: the connection guard on a misconfigured demo blocks every
 * request, and a package that filled somebody's log aggregator by default would
 * be teaching them to turn the whole thing off.
 */
final readonly class Blocker
{
    public function __construct(
        private Configuration $config,
        private Dispatcher $events,
        private LogManager $logs,
    ) {}

    public function blocked(string $layer, string $subject, string $reason): void
    {
        $this->events->dispatch(new WriteBlocked($layer, $subject, $reason));

        if (! $this->config->boolean('log.blocked_writes', true)) {
            return;
        }

        $this->logger()?->info('A demo write was blocked.', [
            'layer' => $layer,
            'subject' => $subject,
            'reason' => $reason,
        ]);
    }

    private function logger(): ?LoggerInterface
    {
        try {
            return $this->logs->channel($this->config->nullableString('log.channel'));
        } catch (Throwable) {
            /* A channel that is not configured is not worth failing a request over. */
            return null;
        }
    }
}
