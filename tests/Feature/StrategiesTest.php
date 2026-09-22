<?php

declare(strict_types=1);

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Storage;
use LauroGuedes\DemoMode\Exceptions\DemoModeException;
use LauroGuedes\DemoMode\Exceptions\InvalidConfiguration;
use LauroGuedes\DemoMode\Reset\ResetContext;
use LauroGuedes\DemoMode\Reset\Strategies\Callback;
use LauroGuedes\DemoMode\Reset\Strategies\MigrateFreshSeed;
use LauroGuedes\DemoMode\Reset\Strategies\Snapshot;
use LauroGuedes\DemoMode\Reset\Strategies\SqlDump;
use LauroGuedes\DemoMode\Reset\StrategyFactory;

beforeEach(function (): void {
    demo();
});

it('builds each shipped strategy from its own config block', function (string $name, string $class): void {
    expect(app(StrategyFactory::class)->make($name))->toBeInstanceOf($class);
})->with([
    ['migrate-fresh-seed', MigrateFreshSeed::class],
    ['snapshot', Snapshot::class],
    ['sql-dump', SqlDump::class],
    ['callback', Callback::class],
]);

it('refuses a strategy that is not configured', function (): void {
    expect(fn (): mixed => app(StrategyFactory::class)->make('telepathy'))
        ->toThrow(InvalidConfiguration::class, 'is not configured');
});

it('lets a command-line option override the configured strategy', function (): void {
    Config::set('demo.reset.strategy', 'migrate-fresh-seed');

    expect(app(StrategyFactory::class)->make('callback'))->toBeInstanceOf(Callback::class);
});

/**
 * Each strategy's own options stay in its own block, so switching strategy is one
 * env var with the other's settings still sitting there ready.
 */
it('gives a strategy only its own options', function (): void {
    Config::set('demo.reset.strategies.sql-dump.path', '/tmp/baseline.sql');

    expect(app(StrategyFactory::class)->make('sql-dump')->describe())->toContain('baseline.sql')
        ->and(app(StrategyFactory::class)->make('snapshot')->describe())->toContain('demo-baseline');
});

describe('the snapshot strategy', function (): void {
    it('reports a missing name rather than restoring nothing', function (): void {
        Config::set('demo.reset.strategies.snapshot.name');

        expect(app(StrategyFactory::class)->make('snapshot')->validate())
            ->toContain('No snapshot name is configured. Set demo.reset.strategies.snapshot.name.');
    });

    /**
     * spatie's Load command returns quietly when the named snapshot is not
     * there, so without this a reset against a missing baseline drops every
     * table and reports success.
     */
    it('reports a baseline that has not been taken', function (): void {
        Storage::fake('snapshots');
        Config::set('db-snapshots.disk', 'snapshots');

        expect(app(StrategyFactory::class)->make('snapshot')->validate()[0])
            ->toContain('does not exist')
            ->toContain('demo:snapshot');
    });

    it('is usable once the baseline is there', function (): void {
        Storage::fake('snapshots');
        Config::set('db-snapshots.disk', 'snapshots');
        Storage::disk('snapshots')->put('demo-baseline.sql', 'select 1;');

        expect(app(StrategyFactory::class)->make('snapshot')->validate())->toBe([]);
    });

    /**
     * Rotation only means anything if something re-hashes the new password. A
     * restore brings back whatever hash the baseline froze.
     */
    it('says it does not re-seed the published credentials', function (): void {
        expect(app(StrategyFactory::class)->make('snapshot')->seedsCredentials())->toBeFalse();
    });

    /**
     * Without --drop-tables the restore lands on top of whatever a visitor left
     * behind, which restores the seeded rows and keeps theirs. That is not a
     * reset, and it is silent.
     */
    it('drops the tables it restores over, so a restore is a reset', function (): void {
        $artisan = Mockery::spy(Kernel::class);
        $artisan->shouldReceive('call')->andReturn(0);

        new Snapshot(['name' => 'demo-baseline'])->run(resetContext($artisan));

        $artisan->shouldHaveReceived('call')->withArgs(
            fn (string $command, array $parameters): bool => $command === 'snapshot:load'
                && $parameters['name'] === 'demo-baseline'
                && $parameters['--drop-tables'] === true,
        );
    });

    /**
     * spatie spells the flag its own way. Hard-coding Laravel's convention meant
     * any demo that set demo.reset.connection got "The --database option does
     * not exist" at reset time, on a path validate() could not see.
     */
    it('names the connection the way the command it calls spells it', function (): void {
        $artisan = Mockery::spy(Kernel::class);
        $artisan->shouldReceive('call')->andReturn(0);

        new Snapshot(['name' => 'demo-baseline'])->run(resetContext($artisan, connection: 'demo_db'));

        $artisan->shouldHaveReceived('call')->withArgs(
            fn (string $command, array $parameters): bool => array_key_exists('--connection', $parameters)
                && ! array_key_exists('--database', $parameters),
        );
    });

    it('fails rather than reporting success when the restore fails', function (): void {
        $artisan = Mockery::spy(Kernel::class);
        $artisan->shouldReceive('call')->andReturn(1);

        expect(fn (): mixed => new Snapshot(['name' => 'demo-baseline'])->run(resetContext($artisan)))
            ->toThrow(DemoModeException::class, 'failed');
    });
});

describe('the callback strategy', function (): void {
    it('runs what the application gave it, with the context', function (): void {
        $seen = null;

        $strategy = new Callback(['using' => function (ResetContext $context) use (&$seen): void {
            $seen = $context;
        }]);

        $strategy->run(resetContext(options: ['strategy' => 'callback']));

        expect($seen)->toBeInstanceOf(ResetContext::class)
            ->and($seen->option('strategy'))->toBe('callback');
    });

    /**
     * Falling through would have the Runner record a completed reset, publish
     * credentials and dispatch ResetCompleted, while the demo went on
     * accumulating everything visitors left.
     */
    it('throws rather than silently doing nothing', function (): void {
        expect(fn (): mixed => (new Callback)->run(resetContext()))
            ->toThrow(DemoModeException::class, 'no callable to run');
    });

    it('reports a missing callback', function (): void {
        expect((new Callback)->validate())
            ->toContain('The callback strategy has no callback. Set demo.reset.strategies.callback.using.');
    });

    it('reports something that is not callable', function (): void {
        expect(new Callback(['using' => 'not a function'])->validate()[0])->toContain('should be callable');
    });
});

describe('the sql-dump strategy', function (): void {
    it('reports a dump that is not configured', function (): void {
        expect(app(StrategyFactory::class)->make('sql-dump')->validate())
            ->toContain('No dump path is configured. Set demo.reset.strategies.sql-dump.path.');
    });

    it('reports a dump that is not there', function (): void {
        Config::set('demo.reset.strategies.sql-dump.path', '/tmp/does-not-exist-'.uniqid().'.sql');

        expect(app(StrategyFactory::class)->make('sql-dump')->validate()[0])->toContain('does not exist or cannot be read');
    });

    it('is usable once the dump exists', function (): void {
        $path = sys_get_temp_dir().'/demo-'.uniqid().'.sql';
        file_put_contents($path, 'select 1;');

        Config::set('demo.reset.strategies.sql-dump.path', $path);

        expect(app(StrategyFactory::class)->make('sql-dump')->validate())->toBe([]);

        unlink($path);
    });
});
