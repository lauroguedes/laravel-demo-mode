<?php

declare(strict_types=1);

namespace LauroGuedes\DemoMode\Credentials;

use Illuminate\Contracts\Cache\Factory as CacheFactory;
use Illuminate\Contracts\Filesystem\Factory as FilesystemFactory;
use LauroGuedes\DemoMode\Configuration;
use LauroGuedes\DemoMode\Contracts\CredentialStore;
use LauroGuedes\DemoMode\Credentials\Stores\CacheStore;
use LauroGuedes\DemoMode\Credentials\Stores\FileStore;
use LauroGuedes\DemoMode\Credentials\Stores\NullStore;
use LauroGuedes\DemoMode\Exceptions\InvalidConfiguration;
use LauroGuedes\DemoMode\Support\CacheKeys;

/**
 * Builds the configured credential store.
 *
 * An unrecognised name is an exception rather than a fallback to null. Falling
 * back would give a demo whose login page silently shows nothing, and "the demo
 * publishes no credentials" is not a state worth reaching by typo.
 */
final readonly class StoreFactory
{
    public function __construct(
        private Configuration $config,
        private FilesystemFactory $filesystem,
        private CacheFactory $cache,
        private CacheKeys $keys,
    ) {}

    public function make(?string $name = null): CredentialStore
    {
        $name ??= $this->config->string('credentials.store', 'file');

        return match ($name) {
            'file' => new FileStore(
                $this->filesystem,
                $this->config->string('credentials.stores.file.disk', 'local'),
                $this->config->string('credentials.stores.file.path', 'demo-credentials.json'),
            ),
            'cache' => new CacheStore(
                $this->cache,
                $this->config->nullableString('credentials.stores.cache.store'),
                $this->keys->credentials(),
                $this->config->nullableString('credentials.stores.cache.ttl') === null
                    ? null
                    : $this->config->integer('credentials.stores.cache.ttl'),
            ),
            'null', 'none' => new NullStore,
            default => throw InvalidConfiguration::unknownCredentialStore($name),
        };
    }
}
