<?php

declare(strict_types=1);

namespace LauroGuedes\DemoMode\Restrictions;

use Illuminate\Config\Repository;
use Illuminate\Contracts\Config\Repository as RepositoryContract;
use Illuminate\Contracts\Container\Container;
use LauroGuedes\DemoMode\Contracts\Restriction;
use LauroGuedes\DemoMode\Support\PinnedConfigRepository;
use Psr\Log\LoggerInterface;

/**
 * Pins configuration values a visitor must not be able to change back.
 *
 * The subtle half of demo restrictions, and the one an earlier implementation got
 * only half right: setting the value at boot is not the same as pinning it. An
 * application with a settings screen writes to config at request time, so a
 * visitor can undo at 10:01 what the boot set at 10:00 — and the settings that
 * matter on a demo are exactly the ones whose wrong value locks the next visitor
 * out.
 *
 * Swaps the container's config repository for one that ignores writes to the
 * named keys. Done in the restriction rather than the provider because it should
 * happen only on a demo, and only when an application actually pins something.
 */
final readonly class ForceConfig implements Restriction
{
    public function __construct(
        private Container $container,
        private LoggerInterface $logger,
    ) {}

    public function apply(array $options): void
    {
        $pin = $options['pin'] ?? [];

        if (! is_array($pin) || $pin === []) {
            return;
        }

        /*
         * Reads the concrete repository rather than the contract, because the
         * replacement has to carry the existing items across and only the
         * concrete class exposes them.
         */
        $current = $this->container->make(Repository::class);

        /** @var array<string, mixed> $pin */
        $replacement = PinnedConfigRepository::replacing($current, $pin, $this->logger);

        $this->container->instance('config', $replacement);
        $this->container->instance(Repository::class, $replacement);
        $this->container->instance(RepositoryContract::class, $replacement);
    }

    public function describe(): string
    {
        return 'Some settings cannot be changed by visitors';
    }
}
