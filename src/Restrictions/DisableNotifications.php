<?php

declare(strict_types=1);

namespace LauroGuedes\DemoMode\Restrictions;

use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Notifications\Events\NotificationSending;
use LauroGuedes\DemoMode\Contracts\Restriction;
use LauroGuedes\DemoMode\Support\Options;

/**
 * Stops the notification channels that cost money or reach strangers.
 *
 * Mail has its own restriction; this is for the rest. An SMS channel on a public
 * demo is a stranger-operated way to send texts to arbitrary numbers at the
 * project's expense, and a Slack channel is a stranger-operated way to post into
 * the team's workspace.
 *
 * Works by refusing the send rather than by unbinding the channel, so an
 * application's own code paths run unchanged and only the delivery stops.
 */
final readonly class DisableNotifications implements Restriction
{
    public function __construct(private Dispatcher $events) {}

    public function apply(array $options): void
    {
        $blocked = Options::strings($options['channels'] ?? []);

        if ($blocked === []) {
            return;
        }

        $this->events->listen(NotificationSending::class, static fn (NotificationSending $event): bool => ! in_array($event->channel, $blocked, true));
    }

    public function describe(): string
    {
        return 'Costly notification channels are off';
    }
}
