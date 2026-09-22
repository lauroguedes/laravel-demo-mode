<?php

declare(strict_types=1);

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use LauroGuedes\DemoMode\Exceptions\DemoModeException;
use LauroGuedes\DemoMode\Reset\Strategies\SqlDump;
use LauroGuedes\DemoMode\Reset\StrategyFactory;
use LauroGuedes\DemoMode\Support\DestructiveCommands;

/**
 * The strategies against a database that actually exists.
 *
 * Runs on sqlite everywhere, and on MySQL 8 and PostgreSQL 16 in the database CI
 * job. Everything else in the suite proves the package calls the right things;
 * this is the part that proves they work.
 */
beforeEach(function (): void {
    DestructiveCommands::prohibit(true);

    $GLOBALS['demo_dumps'] = [];

    demo([
        'demo.reset.maintenance' => false,
        'demo.cleaners' => [],
        'demo.credentials.enabled' => false,
    ]);
});

afterEach(function (): void {
    DestructiveCommands::prohibit(false);

    Schema::dropIfExists('demo_widgets');

    /* Cleared here so a test that fails early does not leak its dump. */
    foreach ($GLOBALS['demo_dumps'] ?? [] as $dump) {
        @unlink($dump);
    }

    $GLOBALS['demo_dumps'] = [];
});

/**
 * A baseline dump this driver will accept.
 *
 * The DDL is the only part that differs between the three, and writing it out
 * per test made every test mostly boilerplate about auto-increment syntax.
 */
function baselineDump(): string
{
    /*
     * Not tempnam().'.sql', which creates a file at one path and writes to
     * another, leaving an empty temp file behind on every call.
     */
    $path = sys_get_temp_dir().'/demo-baseline-'.uniqid().'.sql';

    $id = match (DB::connection()->getDriverName()) {
        'pgsql' => 'id serial primary key',
        'mysql', 'mariadb' => 'id INT AUTO_INCREMENT PRIMARY KEY',
        default => 'id INTEGER PRIMARY KEY AUTOINCREMENT',
    };

    file_put_contents($path, sprintf(
        "CREATE TABLE demo_widgets (%s, name VARCHAR(255));\nINSERT INTO demo_widgets (name) VALUES ('seeded');\n",
        $id,
    ));

    $GLOBALS['demo_dumps'][] = $path;

    return $path;
}

function widgetsTable(): void
{
    Schema::create('demo_widgets', function (Blueprint $table): void {
        $table->id();
        $table->string('name');
    });
}

/**
 * The dump path is fed through an argument array rather than a shell string, so
 * a name a shell would treat as syntax is just a name.
 */
it('loads a dump into the database', function (): void {
    $path = baselineDump();

    config()->set('demo.reset.strategies.sql-dump.path', $path);
    config()->set('demo.reset.strategy', 'sql-dump');

    $strategy = app(StrategyFactory::class)->make();

    expect($strategy)->toBeInstanceOf(SqlDump::class)
        ->and($strategy->validate())->toBe([]);

    DestructiveCommands::permitting(fn () => $strategy->run(resetContext()));

    expect(DB::table('demo_widgets')->pluck('name')->all())->toBe(['seeded']);
})->group('database');

/**
 * Loading over the top of what a visitor left behind restores the seeded rows and
 * keeps theirs, which is not a reset. The wipe is what makes it one.
 */
it('drops what a visitor left behind before loading', function (): void {
    $path = baselineDump();

    widgetsTable();
    DB::table('demo_widgets')->insert(['name' => 'what a stranger typed']);

    config()->set('demo.reset.strategies.sql-dump.path', $path);

    DestructiveCommands::permitting(
        fn () => app(StrategyFactory::class)->make('sql-dump')->run(resetContext()),
    );

    expect(DB::table('demo_widgets')->pluck('name')->all())->toBe(['seeded']);
})->group('database');

/**
 * The ordering that matters most in this strategy. A dump discovered missing
 * after db:wipe leaves the demo with an empty database and nothing to refill it
 * — the same failure shape as a seeder that does not exist.
 */
it('refuses a dump it cannot read rather than wiping and failing', function (): void {
    config()->set('demo.reset.strategies.sql-dump.path', '/tmp/not-here-'.uniqid().'.sql');

    widgetsTable();
    DB::table('demo_widgets')->insert(['name' => 'still here']);

    $strategy = app(StrategyFactory::class)->make('sql-dump');

    expect(fn (): mixed => DestructiveCommands::permitting(fn () => $strategy->run(resetContext())))
        ->toThrow(DemoModeException::class, 'cannot be read')
        ->and(DB::table('demo_widgets')->pluck('name')->all())->toBe(['still here']);
})->group('database');

it('says so rather than guessing when it does not know the driver, before wiping', function (): void {
    $path = baselineDump();

    widgetsTable();
    DB::table('demo_widgets')->insert(['name' => 'still here']);

    config()->set('database.connections.pretend', ['driver' => 'sqlsrv', 'database' => 'demo', 'host' => '127.0.0.1']);
    config()->set('demo.reset.connection', 'pretend');
    config()->set('demo.reset.strategies.sql-dump.path', $path);

    $strategy = app(StrategyFactory::class)->make('sql-dump');

    expect(fn (): mixed => DestructiveCommands::permitting(fn () => $strategy->run(resetContext(connection: 'pretend'))))
        ->toThrow(DemoModeException::class, 'does not know how to load a dump into')
        ->and(DB::table('demo_widgets')->pluck('name')->all())->toBe(['still here']);
})->group('database');
