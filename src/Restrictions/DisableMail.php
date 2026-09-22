<?php

declare(strict_types=1);

namespace LauroGuedes\DemoMode\Restrictions;

use Illuminate\Contracts\Config\Repository;
use LauroGuedes\DemoMode\Contracts\Restriction;
use LauroGuedes\DemoMode\Support\Options;

/**
 * Nothing leaves the building.
 *
 * Every address an application sends to is an address somebody typed, and on a
 * public demo that somebody is anonymous. Without this, a demo is a free relay:
 * anyone can trigger a password reset, an invitation or a notification addressed
 * to any third party, sent from a domain carrying the project's reputation.
 *
 * The 'array' transport rather than 'log', because these messages have no reader
 * here and the log does have a size. A demo that runs for months writing every
 * rendered email to disk is a slow way to fill a volume.
 */
final readonly class DisableMail implements Restriction
{
    public function __construct(private Repository $config) {}

    public function apply(array $options): void
    {
        $this->config->set('mail.default', Options::string($options['transport'] ?? null, 'array'));
    }

    public function describe(): string
    {
        return 'Mail goes nowhere';
    }
}
