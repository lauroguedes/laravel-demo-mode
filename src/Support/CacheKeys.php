<?php

declare(strict_types=1);

namespace LauroGuedes\DemoMode\Support;

use Illuminate\Support\Str;
use LauroGuedes\DemoMode\Configuration;

/**
 * Every cache key this package writes, in one place.
 *
 * Four collaborators had to agree about these and agreed only by hand: the
 * Runner writes the lock and the last-reset timestamp, DemoMode reads the
 * timestamp, the cache credential store writes a key an application can rename,
 * and FlushCache is responsible for preserving all of them across the flush it
 * performs in the middle of a reset.
 *
 * That last one is why this exists rather than a handful of constants. The cache
 * cleaner runs *during* a reset, between the strategy and the credential write,
 * and 'demo-mode:*' did not cover the reset lock — so clearing the store dropped
 * the lock guarding the run in progress, and for the rest of that reset a second
 * one could start concurrently. A wildcard that does not enumerate what it
 * claims to match is worse than no wildcard.
 *
 * The TTL matters too. The lock is a lease, not a value: restoring it with
 * forever() would make a reset that crashed after this point block every later
 * one permanently, which is a demo that never resets again.
 */
final readonly class CacheKeys
{
    public const string PREFIX = 'demo-mode:';

    public const string LOCK = 'demo-mode:reset';

    public const string LAST_RESET = 'demo-mode:last-reset';

    public function __construct(private Configuration $config) {}

    /**
     * The key the cache credential store writes to, which an application may
     * rename — which is why the doctor and the cleaner have to ask rather than
     * assume.
     */
    public function credentials(): string
    {
        return $this->config->string('credentials.stores.cache.key', self::PREFIX.'credentials');
    }

    /**
     * Every key, mapped to the lifetime it should be restored with.
     *
     * Null means forever. A number means the key is a lease and gets what is
     * left of one, rounded up to the configured ceiling.
     *
     * @return array<string, int|null>
     */
    public function all(): array
    {
        return [
            self::LOCK => $this->config->integer('reset.lock_ttl', 1800),
            self::LAST_RESET => null,
            $this->credentials() => null,
        ];
    }

    /**
     * Whether a FlushCache 'except' list keeps this key.
     *
     * Asked by more than one doctor check, because more than one thing breaks
     * when the answer is no and each breaks silently. Str::is() is used so the
     * question is answered by the same matcher the cleaner uses rather than by a
     * second reading of the pattern rules.
     *
     * @param  list<string>  $patterns
     */
    public function isPreserved(string $key, array $patterns): bool
    {
        return Str::is($patterns, $key);
    }

    /**
     * The keys a pattern stands for.
     *
     * Cache stores cannot be enumerated — no driver offers "list every key under
     * this prefix" — so a wildcard resolves against what the package knows it
     * writes. An application preserving a key of its own names it in full, which
     * is the honest interface: a pattern that silently matched nothing would be
     * worse than one that does not pretend.
     *
     * @return array<string, int|null>
     */
    public function matching(string $pattern): array
    {
        if (! str_ends_with($pattern, '*')) {
            return [$pattern => null];
        }

        $prefix = mb_substr($pattern, 0, -1);

        return array_filter(
            $this->all(),
            static fn (string $key): bool => str_starts_with($key, $prefix),
            ARRAY_FILTER_USE_KEY,
        );
    }
}
