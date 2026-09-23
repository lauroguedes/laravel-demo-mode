<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Log;
use Illuminate\View\Component;
use LauroGuedes\DemoMode\Configuration;
use LauroGuedes\DemoMode\Contracts\Cleaner;
use LauroGuedes\DemoMode\Contracts\DoctorCheck;
use LauroGuedes\DemoMode\Contracts\ResetGuard;
use LauroGuedes\DemoMode\Contracts\ResetStrategy;
use LauroGuedes\DemoMode\Contracts\Restriction;
use LauroGuedes\DemoMode\Credentials\Credential;
use LauroGuedes\DemoMode\DemoModeServiceProvider;
use LauroGuedes\DemoMode\Doctor\Doctor;
use LauroGuedes\DemoMode\Exceptions\DemoModeException;
use LauroGuedes\DemoMode\Reset\Runner;
use LauroGuedes\DemoMode\Reset\Strategies\MigrateFreshSeed;
use LauroGuedes\DemoMode\Restrictions\BlockPrivilegedAccounts;
use LauroGuedes\DemoMode\Restrictions\Pipeline;
use LauroGuedes\DemoMode\Support\DestructiveCommands;
use Psr\Log\LoggerInterface;

/**
 * The invariants that are cheap to state and expensive to lose.
 *
 * Each of these is a property someone could remove while making an ordinary,
 * sensible-looking change, and whose absence would not fail any other test.
 */
arch('nothing is left behind from debugging')
    ->expect(['dd', 'dump', 'var_dump', 'ray', 'die', 'exit'])
    ->not->toBeUsed();

arch('everything declares strict types')
    ->expect('LauroGuedes\DemoMode')
    ->toUseStrictTypes();

/**
 * The single point of truth, enforced. Both implementations this package was
 * extracted from read the config key directly in several files, which is how the
 * meaning of "is this a demo" drifted between them.
 */
arch('only Configuration reads the demo config key')
    ->expect('LauroGuedes\DemoMode')
    ->not->toUse('config')
    ->ignoring([
        Configuration::class,
        BlockPrivilegedAccounts::class,
    ]);

/**
 * The provider must be inert on an installation that is not a demo. A reference
 * to the reset namespace from register() or boot() would mean a class loaded, a
 * binding resolved, or — worst case — a code path reachable.
 */
arch('the service provider never reaches into the reset machinery')
    ->expect(DemoModeServiceProvider::class)
    ->not->toUse([
        Runner::class,
        MigrateFreshSeed::class,
        DestructiveCommands::class,
    ]);

/**
 * A published password reaching a log, a report or an exception outlives the
 * reset that was meant to retire it. The credential classes hold the value, so
 * they are the ones that must never hand it to a logger.
 */
arch('credentials never reach a logger')
    ->expect('LauroGuedes\DemoMode\Credentials')
    ->not->toUse([
        LoggerInterface::class,
        Log::class,
        'logger',
        'report',
    ]);

arch('the reset events carry no password')
    ->expect('LauroGuedes\DemoMode\Events')
    ->not->toUse(Credential::class);

arch('contracts are interfaces')
    ->expect('LauroGuedes\DemoMode\Contracts')
    ->toBeInterfaces();

arch('exceptions extend the package base')
    ->expect('LauroGuedes\DemoMode\Exceptions')
    ->toExtend(DemoModeException::class)
    ->ignoring(DemoModeException::class);

arch('every guard implements the contract')
    ->expect('LauroGuedes\DemoMode\Reset\Guards')
    ->toImplement(ResetGuard::class);

arch('every cleaner implements the contract')
    ->expect('LauroGuedes\DemoMode\Cleaners')
    ->toImplement(Cleaner::class);

arch('every restriction implements the contract')
    ->expect('LauroGuedes\DemoMode\Restrictions')
    ->toImplement(Restriction::class)
    ->ignoring(Pipeline::class);

/**
 * A check that is not in Doctor::CHECKS reports nothing while looking like it
 * works. Two of them shipped that way before this test existed.
 */
test('every doctor check is registered', function (): void {
    $registered = Doctor::shipped();

    $files = glob(__DIR__.'/../../src/Doctor/Checks/*.php') ?: [];

    $written = array_map(
        static fn (string $file): string => 'LauroGuedes\\DemoMode\\Doctor\\Checks\\'.basename($file, '.php'),
        $files,
    );

    expect(array_values(array_diff($written, $registered)))->toBe([]);
});

arch('every doctor check implements the contract')
    ->expect('LauroGuedes\DemoMode\Doctor\Checks')
    ->toImplement(DoctorCheck::class);

/**
 * Both components have to be safe in a layout that does not know whether this is
 * a demo, which means the decision lives in shouldRender() rather than in a
 * caller's @demo wrapper.
 */
arch('the view components decide for themselves whether to render')
    ->expect('LauroGuedes\DemoMode\View\Components')
    ->toHaveMethod('shouldRender')
    ->toExtend(Component::class);

arch('every strategy implements the contract')
    ->expect('LauroGuedes\DemoMode\Reset\Strategies')
    ->toImplement(ResetStrategy::class);
