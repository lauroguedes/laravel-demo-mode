<?php

declare(strict_types=1);

namespace LauroGuedes\DemoMode\Doctor\Checks;

use Illuminate\Contracts\Config\Repository;
use LauroGuedes\DemoMode\Configuration;
use LauroGuedes\DemoMode\Contracts\RunsOnDemosOnly;
use LauroGuedes\DemoMode\Doctor\Finding;
use LauroGuedes\DemoMode\Restrictions\DisableMail;

/**
 * A demo that can send mail sends it wherever a stranger typed.
 *
 * Every address an application mails is an address somebody entered, and on a
 * public demo that somebody is anonymous. Left alone, a demo becomes a free
 * relay for password-reset and invitation emails addressed to anyone, sent from
 * a domain with the project's reputation on it.
 *
 * A warning rather than an error, because an application may have contained mail
 * some other way — a catch-all transport, a sandboxed SMTP provider — and the
 * package cannot see that from here.
 */
final readonly class MailIsContained implements RunsOnDemosOnly
{
    private const array HARMLESS = ['array', 'log', 'null'];

    public function __construct(
        private Configuration $config,
        private Repository $appConfig,
    ) {}

    public function run(): array
    {
        if (array_key_exists(DisableMail::class, $this->config->classMap('restrictions'))) {
            return [];
        }

        $mailer = $this->appConfig->get('mail.default');

        if (! is_string($mailer) || in_array($mailer, self::HARMLESS, true)) {
            return [];
        }

        return [Finding::warning(
            'mail',
            sprintf(
                'Mail goes out through [%s] and the DisableMail restriction is not enabled. '
                    .'Everything this demo sends is addressed to whatever a stranger typed in.',
                $mailer,
            ),
            'Add LauroGuedes\DemoMode\Restrictions\DisableMail::class to demo.restrictions.',
        )];
    }
}
