<?php

declare(strict_types=1);

namespace LauroGuedes\DemoMode\Credentials\Stores;

use Illuminate\Contracts\Cache\Factory as CacheFactory;
use LauroGuedes\DemoMode\Contracts\CredentialStore;
use LauroGuedes\DemoMode\Credentials\Credential;
use Throwable;

/**
 * The cache.
 *
 * Simpler than a file and with no disk to get wrong, at the cost of one ordering
 * hazard that has to be respected: the reset flushes the cache, so a rotation
 * written before that step would be erased by it. The package handles this from
 * both sides — credentials rotate after the cleaners run, and FlushCache's
 * default 'except' keeps 'demo-mode:*' — but an application that reorders either
 * one gets a login page showing a password that does not work, silently.
 *
 * 'demo:doctor' checks for that combination. Prefer the file store unless you
 * have a reason.
 */
final readonly class CacheStore implements CredentialStore
{
    public function __construct(
        private CacheFactory $cache,
        private ?string $store,
        private string $key,
        private ?int $ttl,
    ) {}

    public function put(array $credentials): void
    {
        $payload = Credential::toArrays($credentials);

        try {
            $repository = $this->cache->store($this->store);

            $this->ttl === null
                ? $repository->forever($this->key, $payload)
                : $repository->put($this->key, $payload, $this->ttl);
        } catch (Throwable) {
            //
        }
    }

    public function get(): array
    {
        try {
            $payload = $this->cache->store($this->store)->get($this->key);
        } catch (Throwable) {
            return [];
        }

        return is_array($payload) ? Credential::listFromArray($payload) : [];
    }

    public function forget(): void
    {
        try {
            $this->cache->store($this->store)->forget($this->key);
        } catch (Throwable) {
            //
        }
    }

    public function describe(): string
    {
        return sprintf('cache key [%s]%s', $this->key, $this->store === null ? '' : ' on store ['.$this->store.']');
    }
}
