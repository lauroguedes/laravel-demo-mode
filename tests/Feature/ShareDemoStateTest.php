<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Route;
use LauroGuedes\DemoMode\Facades\Demo;
use LauroGuedes\DemoMode\Http\Middleware\ShareDemoState;

beforeEach(function (): void {
    Route::middleware(ShareDemoState::class)->get('/shared', fn (): array => [
        'shared' => view()->shared('demo') instanceof Closure ? (view()->shared('demo'))() : null,
    ]);
});

it('shares nothing when the installation is not a demo', function (): void {
    Config::set('demo.enabled', false);

    $this->get('/shared')->assertOk()->assertJson(['shared' => null]);
});

it('shares one payload every front end can read', function (): void {
    CarbonImmutable::setTestNow('2026-09-22 09:30:00');

    published(['demo.reset.schedule' => 'hourly']);

    $this->get('/shared')
        ->assertOk()
        ->assertJsonPath('shared.enabled', true)
        ->assertJsonPath('shared.next_reset_at', '2026-09-22T10:00:00+00:00')
        ->assertJsonPath('shared.resets_in', '30 minutes')
        ->assertJsonPath('shared.credentials.email', 'admin@demo.test')
        ->assertJsonPath('shared.banner.variant', 'warning');
});

/**
 * Shared as a closure, so a response that never reads it computes nothing —
 * which is what keeps this safe to put in the web group of an application whose
 * API routes render no views.
 */
it('shares a closure rather than a computed payload', function (): void {
    demo();

    Route::middleware(ShareDemoState::class)->get('/lazy', fn (): array => [
        'lazy' => view()->shared('demo') instanceof Closure,
    ]);

    $this->get('/lazy')->assertOk()->assertJson(['lazy' => true]);
});

/**
 * An Inertia payload is in the page source of every response it decorates,
 * including ones served to a crawler. Turning the password off there has to be
 * possible without turning credentials off altogether.
 */
it('leaves the password out of the payload when asked to', function (): void {
    published(['demo.credentials.expose_in_payload' => false]);

    expect(Demo::credentials())->not->toBeNull()
        ->and(Demo::toArray()['credentials'])->toBeNull();
});

it('says only that it is off when the installation is not a demo', function (): void {
    Config::set('demo.enabled', false);

    expect(Demo::toArray())->toBe(['enabled' => false]);
});
