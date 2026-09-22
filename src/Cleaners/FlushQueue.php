<?php

declare(strict_types=1);

namespace LauroGuedes\DemoMode\Cleaners;

use Illuminate\Contracts\Queue\Factory as QueueFactory;
use LauroGuedes\DemoMode\Contracts\Cleaner;
use Throwable;

/**
 * Drop the work the old data queued.
 *
 * Jobs outlive their subjects. A queued export of a report, a welcome email for a
 * user, a thumbnail for an upload — after the reset every one of them holds an id
 * that now belongs to a different row or to nothing. The best case is a worker
 * throwing ModelNotFound in a loop; the worse case is a job that finds the id and
 * acts on the wrong record.
 *
 * Empty by default because clearing a queue an application shares with something
 * that is not the demo would be the package overstepping. Name the queues.
 */
final readonly class FlushQueue implements Cleaner
{
    public function __construct(private QueueFactory $queue) {}

    public function clean(array $options): void
    {
        $queues = $options['queues'] ?? [];
        $connection = $options['connection'] ?? null;

        if (! is_array($queues)) {
            return;
        }

        foreach ($queues as $name) {
            if (! is_string($name)) {
                continue;
            }

            $driver = $this->queue->connection(is_string($connection) ? $connection : null);

            /*
             * clear() is not on the Queue contract — the sync and null drivers
             * have no queue to clear, and a custom driver need not implement it.
             */
            if (! method_exists($driver, 'clear')) {
                continue;
            }

            try {
                $driver->clear($name);
            } catch (Throwable) {
                // A driver that cannot reach its backend is not a reason to
                // leave the demo down.
            }
        }
    }

    public function describe(): string
    {
        return 'Drop the jobs queued against the old data';
    }
}
