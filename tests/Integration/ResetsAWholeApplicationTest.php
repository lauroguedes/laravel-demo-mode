<?php

declare(strict_types=1);

use Illuminate\Contracts\Cache\LockProvider;
use Illuminate\Contracts\Foundation\MaintenanceMode;
use Illuminate\Database\DatabaseManager;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use LauroGuedes\DemoMode\Cleaners\FlushCache;
use LauroGuedes\DemoMode\Cleaners\FlushSessions;
use LauroGuedes\DemoMode\Cleaners\FlushStorage;
use LauroGuedes\DemoMode\Exceptions\DemoWriteProhibited;
use LauroGuedes\DemoMode\Facades\Demo;
use LauroGuedes\DemoMode\Guards\ConnectionGuard;
use LauroGuedes\DemoMode\Support\DestructiveCommands;
use LauroGuedes\DemoMode\Tests\Fixtures\SpyStrategy;
use Workbench\App\Models\DemoUser;
use Workbench\Database\Seeders\DemoSeeder;
use Workbench\Database\Seeders\TwoSaveSeeder;

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

/**
 * The connection guard refuses any write outside its exception list, and a reset
 * drops and recreates every application table — none of which is on that list,
 * because the list is about what a visitor's request legitimately touches.
 *
 * Without the Runner lifting it, the first statement of every reset was refused
 * and the demo could never rebuild again. Nothing announced that: the scheduler
 * failed quietly every six hours.
 */
it('still rebuilds with the connection guard switched on', function (): void {
    demo([
        'demo.reset.maintenance' => false,
        'demo.reset.strategies.migrate-fresh-seed.seeder' => DemoSeeder::class,
        'demo.cleaners' => [],
        'demo.guards.connection.enabled' => true,
        'demo.guards.connection.except_tables' => [],
    ]);

    app('migrator')->path(__DIR__.'/../../workbench/database/migrations');

    app(ConnectionGuard::class)
        ->register(app(DatabaseManager::class));

    $this->artisan('demo:reset', ['--force' => true])->assertSuccessful();

    expect(DB::table('demo_posts')->count())->toBe(2);

    /* And the guard is back on the moment the reset finishes. */
    expect(fn (): mixed => DB::table('demo_posts')->insert(['title' => 'a visitor']))
        ->toThrow(DemoWriteProhibited::class);
});

/**
 * A snapshot or a dump drops every table and restores only what its baseline
 * holds — and a hand-maintained .sql file does not hold demo_sandboxes. The
 * Manager reads a missing table as "the feature was never set up" and serves
 * unscoped, so without putting it back a scoped demo came back from a reset
 * quietly sharing one dataset between every visitor.
 */
it('puts the sandbox table back when a strategy drops it', function (): void {
    demo([
        'demo.sandbox.driver' => 'scoped',
        'demo.reset.maintenance' => false,
        'demo.cleaners' => [],
        'demo.credentials.enabled' => false,
        'demo.reset.strategy' => 'sql-dump',
    ]);

    app('migrator')->path(__DIR__.'/../../workbench/database/migrations');
    $this->artisan('migrate', ['--force' => true]);

    $path = sys_get_temp_dir().'/demo-baseline-'.uniqid().'.sql';
    /* Portable DDL: this test is about the sandbox table, not about dialects. */
    file_put_contents($path, "CREATE TABLE demo_posts (id INT, title VARCHAR(255));\n");
    config()->set('demo.reset.strategies.sql-dump.path', $path);

    expect(Schema::hasTable('demo_sandboxes'))->toBeTrue();

    $this->artisan('demo:reset', ['--force' => true])->assertSuccessful();

    expect(Schema::hasTable('demo_sandboxes'))->toBeTrue();

    @unlink($path);
});

/**
 * The same failure as the connection guard, one layer up, and it took installing
 * the package into a real application to find: the protected-record guard
 * refused the seeder that creates the record it protects.
 *
 * Skipping creates covered a seeder that inserts once. It did not cover the
 * ordinary case — create the account, then save it again to verify the address or
 * assign a role — because by the second save the record exists. The demo seeded,
 * reported success on the seeding step, and then failed the reset.
 */
it('still rebuilds when the seeder saves the protected record twice', function (): void {
    demo([
        'demo.reset.maintenance' => false,
        'demo.reset.strategies.migrate-fresh-seed.seeder' => TwoSaveSeeder::class,
        'demo.cleaners' => [],
        'demo.guards.protected' => [DemoUser::class => ['email' => 'admin@demo.test']],
    ]);

    app('migrator')->path(__DIR__.'/../../workbench/database/migrations');

    $this->artisan('demo:reset', ['--force' => true])->assertSuccessful();

    expect(DemoUser::where('email', 'admin@demo.test')->count())->toBe(1);

    /* And the guard is back on the moment the reset finishes. */
    expect(fn (): mixed => DemoUser::where('email', 'admin@demo.test')->first()?->forceFill(['email' => 'visitor@demo.test'])->save())
        ->toThrow(DemoWriteProhibited::class);
});
