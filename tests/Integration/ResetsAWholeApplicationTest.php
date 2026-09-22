<?php

declare(strict_types=1);

use Illuminate\Contracts\Cache\LockProvider;
use Illuminate\Contracts\Foundation\MaintenanceMode;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use LauroGuedes\DemoMode\Cleaners\FlushCache;
use LauroGuedes\DemoMode\Cleaners\FlushSessions;
use LauroGuedes\DemoMode\Cleaners\FlushStorage;
use LauroGuedes\DemoMode\Facades\Demo;
use LauroGuedes\DemoMode\Support\DestructiveCommands;
use LauroGuedes\DemoMode\Tests\Fixtures\SpyStrategy;
use Workbench\Database\Seeders\DemoSeeder;

/**
 * The reset the specification calls mandatory: a whole application, demo on,
 * running a real demo:reset against a real database, with the database, the
 * sessions, the storage and the credentials all checked afterwards.
 *
 * Everything else in the suite uses a spy strategy, which is right for proving
 * guards but proves nothing about whether the thing actually works.
 */
beforeEach(function (): void {
    DestructiveCommands::prohibit(true);

    Storage::fake('local');
    Storage::fake('public');

    demo([
        'demo.reset.maintenance' => true,
        'demo.reset.strategies.migrate-fresh-seed.seeder' => DemoSeeder::class,
        'demo.cleaners' => [
            FlushSessions::class => ['driver' => 'file'],
            FlushCache::class => ['except' => ['demo-mode:*']],
            FlushStorage::class => ['disks' => ['public' => ['avatars']]],
        ],
        'session.driver' => 'file',
    ]);

    /*
     * Registered with the migrator rather than run once, because the point of
     * this test is that migrate:fresh drops everything and builds it back — so
     * the migrations have to be on the path the fresh run reads, not merely
     * applied before it.
     */
    app('migrator')->path(__DIR__.'/../../workbench/database/migrations');

    $this->artisan('migrate', ['--force' => true]);
});

afterEach(function (): void {
    DestructiveCommands::prohibit(false);

    if (app(MaintenanceMode::class)->active()) {
        app(MaintenanceMode::class)->deactivate();
    }
});

it('rebuilds the database, empties the uploads and republishes the credentials', function (): void {
    /* What a visitor did to the demo before the reset. */
    DB::table('demo_posts')->insert(['title' => 'Something a stranger typed']);
    DB::table('demo_users')->insert(['email' => 'stranger@example.com', 'password' => 'x']);
    Storage::disk('public')->put('avatars/stranger.png', 'not really a png');
    cache()->forever('settings.theme', 'whatever the last visitor chose');

    $before = Demo::credentials()['password'] ?? null;

    $this->artisan('demo:reset', ['--force' => true])->assertSuccessful();

    expect(DB::table('demo_posts')->pluck('title')->all())->toBe(['A seeded post', 'Another seeded post'])
        ->and(DB::table('demo_users')->pluck('email')->all())->toBe(['admin@demo.test'])
        ->and(Storage::disk('public')->exists('avatars/stranger.png'))->toBeFalse()
        ->and(cache()->get('settings.theme'))->toBeNull()
        ->and(Demo::credentials()['password'])->not->toBe($before)
        ->and(Demo::lastResetAt())->not->toBeNull();
});

it('leaves the application serving and the prohibition back on', function (): void {
    $this->artisan('demo:reset', ['--force' => true])->assertSuccessful();

    expect(app(MaintenanceMode::class)->active())->toBeFalse()
        ->and(SpyStrategy::prohibited())->toBeTrue();
});

it('seeds an account a visitor can actually sign in with', function (): void {
    $this->artisan('demo:reset', ['--force' => true])->assertSuccessful();

    $published = Demo::credentials();
    $stored = DB::table('demo_users')->where('email', $published['email'])->value('password');

    expect(Hash::check($published['password'], $stored))->toBeTrue();
});

it('refuses a second reset while one holds the lock', function (): void {
    Config::set('cache.default', 'array');

    $store = cache()->store()->getStore();

    if (! $store instanceof LockProvider) {
        $this->markTestSkipped('This cache store has no locks.');
    }

    $store->lock('demo-mode:reset', 60)->get();

    $this->artisan('demo:reset', ['--force' => true])->assertFailed();
})->skip(fn (): bool => ! cache()->store()->getStore() instanceof LockProvider);
