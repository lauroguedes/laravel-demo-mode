<?php

declare(strict_types=1);

namespace LauroGuedes\DemoMode\Cleaners;

use Illuminate\Cache\TaggableStore;
use Illuminate\Contracts\Cache\Factory as CacheFactory;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use LauroGuedes\DemoMode\Contracts\Cleaner;
use LauroGuedes\DemoMode\Support\CacheKeys;
use LauroGuedes\DemoMode\Support\Options;
use Throwable;

/**
 * Forget what the last visitor configured.
 *
 * A settings table read through a cache outlives the table it came from: drop the
 * rows and the server keeps serving whatever was cached, which on a demo means
 * every visitor inherits the preferences of the one before them. One of the two
 * projects this package was extracted from had to flush its settings cache by
 * hand at the end of its reset command for exactly this reason.
 *
 * The 'except' option is what makes the whole thing safe to run mid-reset. This
 * cleaner executes between the strategy and the credential write, so everything
 * the package is currently relying on lives in the store it is about to empty:
 * the published credentials, the last-reset timestamp, and — the one that is easy
 * to forget — the lock guarding the reset in progress. CacheKeys enumerates all
 * three, so 'demo-mode:*' means what it says rather than what somebody once
 * listed.
 *
 * Preserving by read-and-restore rather than by selective deletion is deliberate:
 * a store's flush() is the only operation every driver implements, and a demo
 * holds few enough of these keys that the round trip is free.
 */
final readonly class FlushCache implements Cleaner
{
    public function __construct(
        private CacheFactory $cache,
        private CacheKeys $keys,
    ) {}

    public function clean(array $options): void
    {
        $tags = Options::strings($options['tags'] ?? []);
        $store = $this->cache->store(is_string($options['store'] ?? null) ? $options['store'] : null);

        if ($tags !== []) {
            $this->flushTags($store, $tags);

            return;
        }

        $preserved = $this->read($store, Options::strings($options['except'] ?? []));

        $store->clear();

        foreach ($preserved as $key => [$value, $lifetime]) {
            $lifetime === null
                ? $store->forever($key, $value)
                : $store->put($key, $value, $lifetime);
        }
    }

    public function describe(): string
    {
        return 'Forget the cached application state';
    }

    /**
     * Tagged flushes touch only their own entries, so nothing needs preserving.
     *
     * @param  list<string>  $tags
     */
    private function flushTags(CacheRepository $store, array $tags): void
    {
        if (! $store->getStore() instanceof TaggableStore) {
            return;
        }

        $store->tags($tags)->flush();
    }

    /**
     * Read back the keys named by 'except' before the store is emptied, each
     * with the lifetime it should come back with.
     *
     * The lifetime is why this is a pair rather than a value. The reset lock is a
     * lease, not a setting: restoring it with forever() would leave a reset that
     * died after this point blocking every later one permanently, which is a demo
     * that never resets again.
     *
     * @param  list<string>  $patterns
     * @return array<string, array{0: mixed, 1: int|null}>
     */
    private function read(CacheRepository $store, array $patterns): array
    {
        $preserved = [];

        foreach ($patterns as $pattern) {
            foreach ($this->keys->matching($pattern) as $key => $lifetime) {
                try {
                    $value = $store->get($key);
                } catch (Throwable) {
                    continue;
                }

                if ($value !== null) {
                    $preserved[$key] = [$value, $lifetime];
                }
            }
        }

        return $preserved;
    }
}
