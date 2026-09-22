<?php

declare(strict_types=1);

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Route;
use LauroGuedes\DemoMode\Contracts\CredentialStore;
use LauroGuedes\DemoMode\DemoModeServiceProvider;
use LauroGuedes\DemoMode\Facades\Demo;
use LauroGuedes\DemoMode\Http\Middleware\ShareDemoState;

it('boots with the flag off and registers nothing destructive', function (): void {
    expect(Demo::enabled())->toBeFalse();

    $commands = array_keys(app(Kernel::class)->all());

    expect($commands)->toContain('demo:doctor', 'demo:status', 'demo:install')
        ->and($commands)->not->toContain('demo:reset');
});

it('registers the reset command once the installation says it is a demo', function (): void {
    demo();

    app()->register(DemoModeServiceProvider::class, force: true);

    expect(Demo::enabled())->toBeTrue()
        ->and(array_keys(app(Kernel::class)->all()))->toContain('demo:reset');
});

/**
 * Blade builds a component before it asks shouldRender(), so anything expensive
 * in a constructor is paid by every page of every installation with this package
 * — and the @demo wrapper that used to cover for that is gone, because the
 * components answer for themselves now.
 *
 * Asserted by what gets built rather than by timing, and measured as a delta so
 * it does not depend on what the test harness already resolved. The credential
 * store is the one the components used to drag in: injecting it pulled in
 * StoreFactory, the filesystem manager and the cache manager, on a login page
 * that was never a demo and rendered nothing.
 */
it('renders both components on a non-demo without building the credential store', function (): void {
    expect(Demo::enabled())->toBeFalse()
        ->and(app()->resolved(CredentialStore::class))->toBeFalse();

    $html = Blade::render('<x-demo-banner /><x-demo-credentials />');

    expect(trim($html))->toBe('')
        ->and(app()->resolved(CredentialStore::class))->toBeFalse();
});

/**
 * And on a demo it is built, because then it is actually needed — otherwise the
 * test above would pass on a package that had simply stopped working.
 */
it('builds the credential store on a demo that publishes credentials', function (): void {
    published();

    Blade::render('<x-demo-credentials />');

    expect(app()->resolved(CredentialStore::class))->toBeTrue();
});

/**
 * The same for the middleware, which an application puts in its web group and
 * therefore runs on every request whether or not this is a demo.
 */
it('passes a request through on a non-demo without building the demo service', function (): void {
    Route::middleware(ShareDemoState::class)->get('/cheap', fn (): string => 'ok');

    $this->get('/cheap')->assertOk();

    expect(app()->resolved(CredentialStore::class))->toBeFalse();
});
