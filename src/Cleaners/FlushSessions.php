<?php

declare(strict_types=1);

namespace LauroGuedes\DemoMode\Cleaners;

use Illuminate\Contracts\Cache\Factory as CacheFactory;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Database\DatabaseManager;
use Illuminate\Filesystem\Filesystem;
use LauroGuedes\DemoMode\Contracts\Cleaner;
use Throwable;

/**
 * Sign everybody out.
 *
 * The one cleaner that is never optional. A session that survives the reset
 * leaves its visitor authenticated as a user id that now belongs to a different
 * person, or to nobody — which in an application with any kind of ownership check
 * is a visitor holding somebody else's row.
 *
 * Each driver is handled the way Laravel's own SessionManager resolves it, so
 * changing SESSION_DRIVER cannot quietly stop this from working.
 *
 * One caveat worth knowing before choosing redis: a cache-backed session store is
 * emptied with the store's own flush(), and Redis implements that as FLUSHDB. If
 * sessions and cache share a connection, this empties both. On a demo that is
 * usually what you wanted anyway — but set 'session.connection' to a separate
 * database if anything else lives there.
 */
final readonly class FlushSessions implements Cleaner
{
    public function __construct(
        private Repository $config,
        private DatabaseManager $database,
        private CacheFactory $cache,
        private Filesystem $files,
    ) {}

    public function clean(array $options): void
    {
        $driver = $options['driver'] ?? $this->config->get('session.driver');

        if (! is_string($driver)) {
            return;
        }

        match ($driver) {
            'file' => $this->flushFiles(),
            'database' => $this->flushTable(),
            'array', 'cookie' => null,
            default => $this->flushCacheStore($driver),
        };
    }

    public function describe(): string
    {
        return 'Sign every visitor out';
    }

    private function flushFiles(): void
    {
        $path = $this->config->get('session.files');

        if (! is_string($path) || ! $this->files->isDirectory($path)) {
            return;
        }

        foreach ($this->files->files($path) as $file) {
            if ($file->getFilename() !== '.gitignore') {
                $this->files->delete($file->getPathname());
            }
        }
    }

    /**
     * The strategy has usually dropped this table already. Truncating anyway
     * covers the strategies that restore from a dump taken while somebody was
     * signed in, where the rows come back with the schema.
     */
    private function flushTable(): void
    {
        $table = $this->config->get('session.table', 'sessions');
        $connection = $this->config->get('session.connection');

        if (! is_string($table)) {
            return;
        }

        try {
            $this->database->connection(is_string($connection) ? $connection : null)
                ->table($table)
                ->delete();
        } catch (Throwable) {
            // The strategy owns this table's existence, not this cleaner.
        }
    }

    /**
     * Resolved exactly as SessionManager::createCacheHandler() does, so the
     * store emptied here is the store the sessions were actually written to.
     */
    private function flushCacheStore(string $driver): void
    {
        $store = $this->config->get('session.store') ?: $driver;

        if (! is_string($store)) {
            return;
        }

        try {
            $this->cache->store($store)->clear();
        } catch (Throwable) {
            // An unreachable store is not a reason to leave the demo down.
        }
    }
}
