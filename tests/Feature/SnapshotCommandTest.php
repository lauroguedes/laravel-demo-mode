<?php

declare(strict_types=1);

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Storage;
use LauroGuedes\DemoMode\Reset\StrategyFactory;

beforeEach(function (): void {
    Config::set('filesystems.disks.snapshots', ['driver' => 'local', 'root' => storage_path('framework/testing/snapshots')]);
    Storage::fake('snapshots');
    Config::set('db-snapshots.disk', 'snapshots');
});

it('is not a command at all on an installation that is not a demo', function (): void {
    Config::set('demo.enabled', false);

    expect(array_keys(app(Kernel::class)->all()))
        ->not->toContain('demo:snapshot');
});

/**
 * The command looks harmless and is not. demo:reset destroys data; this one
 * publishes it — every row into a file every later reset restores to a server
 * strangers sign in to. Refusing to drop the tables on a production box while
 * happily copying them all into the demo's baseline would be the wrong half to
 * guard.
 */
it('refuses on a host the demo was never meant to be', function (): void {
    demo([
        'demo.allowed_hosts' => ['demo.example.com'],
        'app.url' => 'https://app.example.com',
    ]);

    $this->artisan('demo:snapshot', ['--force' => true])->assertFailed();

    expect(Storage::disk('snapshots')->allFiles())->toBe([]);
});

it('refuses in an environment that is not on the list', function (): void {
    demo(['demo.environments' => ['nowhere']]);

    $this->artisan('demo:snapshot', ['--force' => true])->assertFailed();

    expect(Storage::disk('snapshots')->allFiles())->toBe([]);
});

it('takes nothing when the confirmation is declined', function (): void {
    demo();

    $this->artisan('demo:snapshot')
        ->expectsConfirmation('Every row in this database becomes the demonstration data. Continue?', 'no')
        ->assertFailed();

    expect(Storage::disk('snapshots')->allFiles())->toBe([]);
});

it('takes the baseline where the guards allow it', function (): void {
    demo();

    $this->artisan('demo:snapshot', ['--force' => true])->assertSuccessful();

    expect(Storage::disk('snapshots')->allFiles())->toHaveCount(1);
});

/**
 * One source of truth for the name. The command used to default to
 * 'demo-baseline' while the strategy defaulted to '', so a demo with no
 * configured name got a snapshot the strategy then refused to restore.
 */
it('takes the name the strategy will look for', function (): void {
    demo(['demo.reset.strategies.snapshot.name' => 'our-baseline']);

    $this->artisan('demo:snapshot', ['--force' => true])->assertSuccessful();

    expect(Storage::disk('snapshots')->allFiles())->toBe(['our-baseline.sql'])
        ->and(app(StrategyFactory::class)->make('snapshot')->validate())->toBe([]);
});
