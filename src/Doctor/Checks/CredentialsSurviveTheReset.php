<?php

declare(strict_types=1);

namespace LauroGuedes\DemoMode\Doctor\Checks;

use LauroGuedes\DemoMode\Cleaners\FlushCache;
use LauroGuedes\DemoMode\Configuration;
use LauroGuedes\DemoMode\Contracts\RunsOnDemosOnly;
use LauroGuedes\DemoMode\Doctor\Finding;
use LauroGuedes\DemoMode\Support\CacheKeys;
use LauroGuedes\DemoMode\Support\Options;

/**
 * The cache store and the cache cleaner have to agree.
 *
 * The failure this catches is silent, which is what makes it worth a check. With
 * credentials in the cache and FlushCache configured without an 'except' for the
 * package's own keys, every reset publishes a password and then immediately
 * erases it. The demo comes back up, the login page renders, the prefilled
 * password does not work, and nothing anywhere says why.
 *
 * Also warns when nothing rotates. A published password that never changes is a
 * permanent fact of the internet — whatever a visitor wrote down keeps working
 * for as long as the demo exists.
 */
final readonly class CredentialsSurviveTheReset implements RunsOnDemosOnly
{
    public function __construct(
        private Configuration $config,
        private CacheKeys $keys,
    ) {}

    public function run(): array
    {
        if (! $this->config->boolean('credentials.enabled', true)) {
            return [];
        }

        return [...$this->cacheStoreIsPreserved(), ...$this->somethingRotates()];
    }

    /**
     * @return list<Finding>
     */
    private function cacheStoreIsPreserved(): array
    {
        if ($this->config->string('credentials.store', 'file') !== 'cache') {
            return [];
        }

        $cleaners = $this->config->classMap('cleaners');
        $options = $cleaners[FlushCache::class] ?? null;

        if ($options === null) {
            return [];
        }

        $key = $this->keys->credentials();

        if ($this->keys->isPreserved($key, Options::strings($options['except'] ?? []))) {
            return [];
        }

        return [Finding::error(
            'credentials-store',
            'Credentials are kept in the cache, and FlushCache will empty it without preserving them. '
                .'Every reset would publish a password and then erase it, with nothing in any log to say so.',
            sprintf('Add "%s" (or "demo-mode:*") to the FlushCache cleaner\'s "except" list.', $key),
        )];
    }

    /**
     * @return list<Finding>
     */
    private function somethingRotates(): array
    {
        foreach ($this->config->array('credentials.accounts') as $account) {
            if (is_array($account) && ($account['rotate'] ?? true)) {
                return [];
            }
        }

        return [Finding::warning(
            'credentials-rotation',
            'No published account rotates its password, so whatever a visitor writes down keeps working forever.',
            'Set "rotate" => true on at least the primary account.',
        )];
    }
}
