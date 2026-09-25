<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Route;
use LauroGuedes\DemoMode\DemoModeServiceProvider;

/**
 * Both middleware this package ships are documented as one line in
 * bootstrap/app.php:
 *
 *     $middleware->web(append: ['demo.readonly']);
 *     $middleware->web(append: ['demo.sandbox']);
 *
 * A line in bootstrap/app.php is there on every deployment of that application,
 * including every one that is not a demo — which is all of them by default, and
 * every developer's checkout. An alias registered only while the flag is on is
 * therefore an alias missing exactly when the application still names it, and
 * Laravel resolves an unknown alias as a class name: ReflectionException on every
 * request, from following the documentation.
 *
 * The middleware themselves already do nothing off a demo. It is only the alias
 * that was conditional.
 */
beforeEach(function (): void {
    Config::set('demo.enabled', false);

    app()->register(DemoModeServiceProvider::class, force: true);
});

it('resolves its middleware aliases on an installation that is not a demo', function (string $alias): void {
    Route::middleware($alias)->get('/not-a-demo', fn (): string => 'fine');

    $this->get('/not-a-demo')->assertOk()->assertSee('fine');
})->with(['demo.readonly', 'demo.sandbox']);
