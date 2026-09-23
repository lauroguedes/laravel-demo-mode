<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Storage;
use LauroGuedes\DemoMode\Cleaners\FlushCache;
use LauroGuedes\DemoMode\Credentials\Manager;
use LauroGuedes\DemoMode\Facades\Demo;

beforeEach(function (): void {
    demo();
    Storage::fake('local');
});

it('publishes a password nobody has seen', function (): void {
    $published = app(Manager::class)->rotate();

    expect($published)->toHaveCount(1)
        ->and($published[0]->password)->toHaveLength(16)
        ->and($published[0]->email)->toBe('admin@demo.test');
});

it('publishes a different password every time', function (): void {
    $first = app(Manager::class)->rotate()[0]->password;
    $second = app(Manager::class)->rotate()[0]->password;

    expect($first)->not->toBe($second);
});

/**
 * The line that makes "the demo box got promoted" survivable. A credentials file
 * left on disk by an installation that has since stopped being a demo reads back
 * as nothing at all.
 */
it('reads nothing back once the installation stops being a demo', function (): void {
    app(Manager::class)->rotate();

    expect(Demo::credentials())->not->toBeNull();

    Config::set('demo.enabled', false);

    expect(Demo::credentials())->toBeNull()
        ->and(Demo::allCredentials())->toBe([])
        ->and(Storage::disk('local')->exists('demo-credentials.json'))->toBeTrue();
});

it('writes nothing at all when the installation is not a demo', function (): void {
    Config::set('demo.enabled', false);

    expect(app(Manager::class)->rotate())->toBe([])
        ->and(Storage::disk('local')->exists('demo-credentials.json'))->toBeFalse();
});

it('keeps a fixed password for an account that does not rotate', function (): void {
    Config::set('demo.credentials.accounts', [
        ['email' => 'fixed@demo.test', 'rotate' => false, 'password' => 'documented-password', 'primary' => true],
    ]);

    expect(app(Manager::class)->rotate()[0]->password)->toBe('documented-password');
});

it('prefills the account marked primary', function (): void {
    Config::set('demo.credentials.accounts', [
        ['email' => 'viewer@demo.test', 'rotate' => true],
        ['email' => 'admin@demo.test', 'rotate' => true, 'primary' => true],
    ]);

    app(Manager::class)->rotate();

    expect(Demo::credentials()['email'])->toBe('admin@demo.test')
        ->and(Demo::allCredentials())->toHaveCount(2);
});

it('survives the cache being flushed when it lives in the cache', function (): void {
    Config::set('demo.credentials.store', 'cache');

    app(Manager::class)->rotate();
    $published = Demo::credentials()['password'];

    app(FlushCache::class)->clean(['except' => ['demo-mode:*']]);

    app()->forgetInstance(Manager::class);

    expect(Demo::credentials()['password'])->toBe($published);
});

/**
 * The silent failure demo:doctor exists to catch. Read from a later request —
 * the manager holds this reset's values in memory, so only a fresh one sees what
 * a visitor's browser would.
 */
it('is lost when the cache cleaner is not told to keep it', function (): void {
    Config::set('demo.credentials.store', 'cache');

    app(Manager::class)->rotate();

    app(FlushCache::class)->clean(['except' => []]);

    app()->forgetInstance(Manager::class);

    expect(Demo::credentials())->toBeNull();
});

/**
 * A password printed on a login page is public there and nowhere else. Anything
 * that serialises a Credential for a log, a stack trace or a dump has to redact.
 */
it('redacts the password everywhere except toArray', function (): void {
    $credential = app(Manager::class)->rotate()[0];

    expect($credential->redacted())->not->toHaveKey('password')
        ->and((string) $credential)->not->toContain($credential->password)
        ->and(print_r($credential, true))->not->toContain($credential->password)
        ->and($credential->toArray()['password'])->toBe($credential->password);
});

/**
 * What a seeder calls. Null outside a reset, so the documented
 * `?? 'password'` keeps a seeder working when it is run on its own — which is the
 * first thing anybody does after writing one.
 */
it('hands a seeder the staged password, and null when there is none', function (): void {
    demo();

    expect(Demo::passwordFor('admin@demo.test'))->toBeNull();

    $published = app(Manager::class)->stage()[0];

    expect(Demo::passwordFor('admin@demo.test'))->toBe($published->password)
        ->and(Demo::passwordFor('nobody@demo.test'))->toBeNull();
});

it('hands a seeder nothing once the installation stops being a demo', function (): void {
    demo();
    app(Manager::class)->rotate();

    Config::set('demo.enabled', false);
    app()->forgetInstance(Manager::class);

    expect(Demo::passwordFor('admin@demo.test'))->toBeNull();
});
