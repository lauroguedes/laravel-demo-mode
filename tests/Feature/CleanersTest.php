<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Storage;
use LauroGuedes\DemoMode\Cleaners\FlushCache;
use LauroGuedes\DemoMode\Cleaners\FlushStorage;
use LauroGuedes\DemoMode\Support\CacheKeys;

beforeEach(function (): void {
    demo();
});

/**
 * The cache cleaner runs in the middle of a reset, so everything the reset is
 * currently relying on lives in the store it empties — including the lock
 * guarding the run in progress. Clearing that leaves a window in which a second
 * reset can start against a half-rebuilt database.
 *
 * Asserted against the raw key rather than through the lock API, because on
 * every driver that matters a lock *is* an ordinary key: Redis sets the lock
 * name with SETNX and empties it with FLUSHDB, the database store writes a row
 * in the table it truncates, the file store writes a file in the directory it
 * empties. The array store used in tests is the one exception — it keeps locks
 * in a separate property that flush() does not touch — so driving this through
 * lock() here would pass whether or not the bug was fixed.
 */
it('keeps the reset lock it would otherwise clear out from under the running reset', function (): void {
    cache()->store()->put(CacheKeys::LOCK, 'the-owner-of-the-running-reset', 1800);

    app(FlushCache::class)->clean(['except' => ['demo-mode:*']]);

    expect(cache()->store()->get(CacheKeys::LOCK))->toBe('the-owner-of-the-running-reset');
});

/**
 * And it has to come back as a lease. A lock restored forever would leave a
 * reset that died mid-run blocking every later one permanently — a demo that
 * never resets again.
 */
it('restores the lock with a lifetime rather than forever', function (): void {
    Config::set('demo.reset.lock_ttl', 60);

    expect(app(CacheKeys::class)->all()[CacheKeys::LOCK])->toBe(60)
        ->and(app(CacheKeys::class)->all()[CacheKeys::LAST_RESET])->toBeNull();
});

it('keeps the last reset timestamp', function (): void {
    cache()->store()->forever(CacheKeys::LAST_RESET, '2026-09-22T10:00:00+00:00');
    cache()->store()->forever('settings.theme', 'whatever the visitor chose');

    app(FlushCache::class)->clean(['except' => ['demo-mode:*']]);

    expect(cache()->store()->get(CacheKeys::LAST_RESET))->toBe('2026-09-22T10:00:00+00:00')
        ->and(cache()->store()->get('settings.theme'))->toBeNull();
});

/**
 * An application that renames the cache credential key still gets it preserved:
 * the wildcard asks what the package actually writes rather than matching a
 * hardcoded list.
 */
it('keeps a renamed credential key', function (): void {
    Config::set('demo.credentials.stores.cache.key', 'demo-mode:our-own-name');

    cache()->store()->forever('demo-mode:our-own-name', ['published']);

    app(FlushCache::class)->clean(['except' => ['demo-mode:*']]);

    expect(cache()->store()->get('demo-mode:our-own-name'))->toBe(['published']);
});

it('deletes named upload directories and leaves the rest alone', function (): void {
    Storage::fake('public');
    Storage::disk('public')->put('avatars/stranger.png', 'x');
    Storage::disk('public')->put('seeded/logo.png', 'x');

    app(FlushStorage::class)->clean(['disks' => ['public' => ['avatars']]]);

    expect(Storage::disk('public')->exists('avatars/stranger.png'))->toBeFalse()
        ->and(Storage::disk('public')->exists('seeded/logo.png'))->toBeTrue();
});

it('recreates the directory it emptied, so the next upload does not fail', function (): void {
    Storage::fake('public');
    Storage::disk('public')->put('avatars/stranger.png', 'x');

    app(FlushStorage::class)->clean(['disks' => ['public' => ['avatars']]]);

    expect(Storage::disk('public')->directoryExists('avatars'))->toBeTrue();
});

it('does nothing at all when no disks are named', function (): void {
    Storage::fake('public');
    Storage::disk('public')->put('avatars/seeded.png', 'x');

    app(FlushStorage::class)->clean([]);

    expect(Storage::disk('public')->exists('avatars/seeded.png'))->toBeTrue();
});
