<?php

declare(strict_types=1);

namespace LauroGuedes\DemoMode\Doctor\Checks;

use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\NullStore;
use Illuminate\Contracts\Cache\Factory as CacheFactory;
use Illuminate\Contracts\Cache\LockProvider;
use LauroGuedes\DemoMode\Contracts\RunsOnDemosOnly;
use LauroGuedes\DemoMode\Doctor\Finding;
use Throwable;

/**
 * The lock that stops two resets overlapping has to be a real lock.
 *
 * The Runner asks whether the cache store implements LockProvider and trusts the
 * answer, which is not enough on its own. NullStore implements the interface and
 * hands back a lock whose acquire() returns true for everybody — so the check
 * passes, the lock is granted twice, and two migrate:fresh runs proceed against
 * one database. ArrayStore is real but per-process, so a web request and a queue
 * worker never contend with each other.
 *
 * Neither of those is an exotic configuration. CACHE_STORE=array is what somebody
 * reaches for on a small box precisely because a demo feels like it does not need
 * infrastructure, and it is the one setting that makes the reset lock, the
 * on-demand cooldown and the queue job's uniqueness all stop working at once,
 * silently.
 */
final readonly class ResetLockIsReal implements RunsOnDemosOnly
{
    public function __construct(private CacheFactory $cache) {}

    /**
     * @return list<Finding>
     */
    public function run(): array
    {
        try {
            $store = $this->cache->store()->getStore();
        } catch (Throwable) {
            /* An unreachable cache is a bigger problem than this check. */
            return [];
        }

        if ($store instanceof NullStore) {
            return [Finding::error(
                'reset-lock',
                'The cache store is "null", whose locks are granted to everyone. Two resets could run against the '
                    .'same database at once, the on-demand cooldown would never hold, and nothing would say so.',
                'Point CACHE_STORE at a real store — file, database, redis or memcached.',
            )];
        }

        if ($store instanceof ArrayStore) {
            return [Finding::warning(
                'reset-lock',
                'The cache store is "array", so its locks live inside one PHP process. A reset in a queue worker and '
                    .'one in a web request cannot see each other\'s lock, and the last reset time is forgotten '
                    .'between requests.',
                'Use file, database, redis or memcached on anything that serves more than one process.',
            )];
        }

        if (! $store instanceof LockProvider) {
            return [Finding::error(
                'reset-lock',
                sprintf(
                    'The cache store [%s] does not support locks, so nothing stops two resets running at once.',
                    $store::class,
                ),
                'Use a store that implements LockProvider — file, database, redis or memcached.',
            )];
        }

        return [];
    }
}
