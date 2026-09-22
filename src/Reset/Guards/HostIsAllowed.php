<?php

declare(strict_types=1);

namespace LauroGuedes\DemoMode\Reset\Guards;

use Illuminate\Contracts\Config\Repository;
use LauroGuedes\DemoMode\Configuration;
use LauroGuedes\DemoMode\Contracts\ResetGuard;

/**
 * The application's own URL has to be a host this demo was meant to be.
 *
 * The guard that survives the case the other three do not: an .env copied to a
 * server whose APP_ENV happens to match the allowed list. The environment name
 * is a convention; the hostname is what the internet actually resolves. Setting
 * demo.allowed_hosts on anything public is the cheapest insurance in the package.
 *
 * Null disables the check, because requiring it would make the package unusable
 * on the machine of anyone whose demo is not deployed yet. 'demo:doctor' warns
 * about that rather than this guard refusing.
 */
final readonly class HostIsAllowed implements ResetGuard
{
    public function __construct(
        private Configuration $config,
        private Repository $appConfig,
    ) {}

    public function name(): string
    {
        return 'host-allowed';
    }

    public function check(): ?string
    {
        $allowed = $this->config->nullableStrings('allowed_hosts');

        if ($allowed === null) {
            return null;
        }

        if ($allowed === []) {
            return 'demo.allowed_hosts is an empty list, which allows no host. Set it to null to disable the check.';
        }

        $url = $this->appConfig->get('app.url');
        $host = is_string($url) ? parse_url($url, PHP_URL_HOST) : null;

        if (! is_string($host) || $host === '') {
            return sprintf(
                'APP_URL [%s] has no readable host, so it cannot be checked against demo.allowed_hosts.',
                is_string($url) ? $url : get_debug_type($url),
            );
        }

        if (in_array(mb_strtolower($host), array_map(mb_strtolower(...), $allowed), true)) {
            return null;
        }

        return sprintf(
            'APP_URL host [%s] is not in demo.allowed_hosts [%s].',
            $host,
            implode(', ', $allowed),
        );
    }
}
