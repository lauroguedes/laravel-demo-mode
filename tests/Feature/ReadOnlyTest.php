<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Route;
use LauroGuedes\DemoMode\Events\WriteBlocked;

function readOnlyRoutes(): void
{
    Route::middleware('demo.readonly')->group(function (): void {
        Route::get('/things', fn (): string => 'listed')->name('things.index');
        Route::post('/things', fn (): string => 'created')->name('things.store');
        Route::delete('/things/1', fn (): string => 'deleted')->name('things.destroy');
        Route::post('/login', fn (): string => 'signed in')->name('login');
        Route::post('/unnamed', fn (): string => 'written');
    });
}

beforeEach(function (): void {
    demo(['demo.guards.read_only.enabled' => true]);

    readOnlyRoutes();
});

it('lets every read through', function (): void {
    $this->get('/things')->assertOk()->assertSee('listed');
});

it('refuses a write', function (): void {
    $this->post('/things')->assertForbidden();
});

it('refuses every configured verb', function (string $method, string $uri): void {
    $this->call($method, $uri)->assertForbidden();
})->with([['POST', '/things'], ['DELETE', '/things/1']]);

/**
 * Signing in has to keep working, or the demo is a screenshot.
 */
it('lets the excepted routes through', function (): void {
    $this->post('/login')->assertOk()->assertSee('signed in');
});

/**
 * Fail-closed, and worth knowing before turning this on: 'except' is by route
 * name because a URL is not a stable thing to write in a config file, so a route
 * with no name cannot be excepted.
 */
it('refuses a route that has no name to except it by', function (): void {
    $this->post('/unnamed')->assertForbidden();
});

it('does nothing when read-only is off', function (): void {
    demo(['demo.guards.read_only.enabled' => false]);
    readOnlyRoutes();

    $this->post('/things')->assertOk();
});

it('does nothing when the installation is not a demo', function (): void {
    config(['demo.enabled' => false, 'demo.guards.read_only.enabled' => true]);
    readOnlyRoutes();

    $this->post('/things')->assertOk();
});

/**
 * A visitor who clicked "save" and got a 403 learns that the demo is broken; one
 * who lands back on the form with a sentence learns that it is a demo.
 */
it('can send the visitor back with a message instead of an error page', function (): void {
    demo([
        'demo.guards.read_only.enabled' => true,
        'demo.guards.read_only.redirect' => '/things',
    ]);
    readOnlyRoutes();

    $this->post('/things')
        ->assertRedirect('/things')
        ->assertSessionHas('error', 'This demonstration is read-only.');
});

it('still answers JSON with a refusal rather than a redirect', function (): void {
    demo([
        'demo.guards.read_only.enabled' => true,
        'demo.guards.read_only.redirect' => '/things',
    ]);
    readOnlyRoutes();

    $this->postJson('/things')->assertForbidden();
});

it('announces every refusal', function (): void {
    Event::fake([WriteBlocked::class]);

    $this->post('/things');

    Event::assertDispatched(WriteBlocked::class, fn (WriteBlocked $e): bool => $e->layer === 'http');
});

/**
 * The default is stated as "not a known-safe method" rather than as a list of
 * methods to block, because a list can be short and Laravel honours _method
 * overrides. A narrowed list is an application's own choice; the default has
 * nothing to get wrong.
 */
it('blocks anything that is not a known-safe method by default', function (): void {
    demo(['demo.guards.read_only.enabled' => true, 'demo.guards.read_only.methods' => null]);

    Route::middleware('demo.readonly')->group(function (): void {
        Route::get('/things', fn (): string => 'listed')->name('things.index');
        Route::put('/things', fn (): string => 'replaced')->name('things.replace');
        Route::patch('/things', fn (): string => 'patched')->name('things.patch');
    });

    $this->put('/things')->assertForbidden();
    $this->patch('/things')->assertForbidden();
    $this->get('/things')->assertOk();
});

/**
 * A narrowed list is honoured, including against a spoofed method — a POST
 * carrying _method=PUT reports itself as PUT, and the real method is checked
 * too so the narrowing cannot be stepped around where a matching route exists.
 */
it('honours a narrowed list against both the reported and the real method', function (): void {
    demo(['demo.guards.read_only.enabled' => true, 'demo.guards.read_only.methods' => ['POST']]);

    Route::middleware('demo.readonly')->group(function (): void {
        Route::put('/things', fn (): string => 'replaced')->name('things.replace');
    });

    /* Reported PUT, real POST. Blocked because the real method is on the list. */
    $this->post('/things', ['_method' => 'PUT'])->assertForbidden();
});
