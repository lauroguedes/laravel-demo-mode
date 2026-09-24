<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Route;
use LauroGuedes\DemoMode\DemoModeServiceProvider;
use LauroGuedes\DemoMode\Jobs\ResetTheDemo;
use LauroGuedes\DemoMode\Support\CacheKeys;

/**
 * @param  array<string, mixed>  $config
 */
function onDemand(array $config = []): void
{
    demo([
        'demo.on_demand.enabled' => true,
        'demo.on_demand.cooldown' => 0,
        'demo.on_demand.middleware' => [],
        ...$config,
    ]);
}

it('registers no route at all when the installation is not a demo', function (): void {
    Config::set('demo.enabled', false);
    Config::set('demo.on_demand.enabled', true);

    app()->register(DemoModeServiceProvider::class, force: true);

    expect(collect(Route::getRoutes())->contains(fn ($route): bool => $route->uri() === 'demo/reset'))->toBeFalse();
});

it('registers no route when the feature is switched off', function (): void {
    demo(['demo.on_demand.enabled' => false]);

    expect(collect(Route::getRoutes())->contains(fn ($route): bool => $route->uri() === 'demo/reset'))->toBeFalse();
});

it('queues a rebuild rather than running it inside the request', function (): void {
    Queue::fake();

    onDemand();

    $this->postJson('/demo/reset')->assertStatus(202);

    Queue::assertPushed(ResetTheDemo::class);
});

/**
 * Two visitors pressing the button together should queue one rebuild. The
 * Runner's lock would refuse the second anyway; this stops it being dispatched,
 * which keeps a failed-job table from filling with refusals.
 */
it('dispatches one job however many ask for it', function (): void {
    expect((new ResetTheDemo)->uniqueId())->toBe('demo-mode:reset');
});

/**
 * The cooldown reads the recorded reset, so a rebuild from the scheduler or the
 * command line also starts the clock. A visitor pressing the button ten seconds
 * after the cron ran should be told to wait.
 *
 * Told to wait, not told how long: the number stays in Retry-After, which is for
 * clients, and out of the sentence, which is for people. A countdown to the next
 * allowed attempt reads as an invitation to come back and spend it.
 */
it('refuses inside the cooldown without saying when to come back', function (): void {
    Queue::fake();

    onDemand(['demo.on_demand.cooldown' => 900]);

    cache()->store()->forever(CacheKeys::LAST_RESET, CarbonImmutable::now()->subMinutes(5)->toIso8601String());

    $response = $this->postJson('/demo/reset');

    $response->assertStatus(429);

    expect((int) $response->headers->get('Retry-After'))->toBeGreaterThan(0)
        ->and((int) $response->headers->get('Retry-After'))->toBeLessThanOrEqual(900);

    expect($response->json('message'))
        ->toContain('later')
        ->not->toMatch('/\d+\s*(second|minute|hour)/i');

    Queue::assertNothingPushed();
});

it('allows a rebuild once the cooldown has passed', function (): void {
    Queue::fake();

    onDemand(['demo.on_demand.cooldown' => 60]);

    cache()->store()->forever(CacheKeys::LAST_RESET, CarbonImmutable::now()->subMinutes(5)->toIso8601String());

    $this->postJson('/demo/reset')->assertStatus(202);
});

it('throttles one visitor pressing the button repeatedly', function (): void {
    Queue::fake();

    onDemand(['demo.on_demand.throttle' => ['attempts' => 1, 'minutes' => 60]]);

    $this->postJson('/demo/reset')->assertStatus(202);
    $this->postJson('/demo/reset')->assertStatus(429);
});

/**
 * An application reachable on two hostnames should not be resettable from the one
 * that was never meant to be a demo. Checked against the host that arrived, not
 * only the one in APP_URL.
 */
it('pretends not to exist on a host the demo was never meant to be', function (): void {
    Queue::fake();

    onDemand(['demo.allowed_hosts' => ['demo.example.com']]);

    $this->postJson('http://app.example.com/demo/reset')->assertStatus(404);

    Queue::assertNothingPushed();
});

it('answers on a host the demo was meant to be', function (): void {
    Queue::fake();

    onDemand(['demo.allowed_hosts' => ['demo.example.com']]);

    $this->postJson('http://demo.example.com/demo/reset')->assertStatus(202);
});

/**
 * Refusal reasons name environments, hostnames and database settings. They belong
 * in the log the Runner writes, not in a response to whoever pressed the button.
 */
it('says nothing about why a refused reset was refused', function (): void {
    onDemand(['demo.on_demand.queue' => false, 'demo.environments' => ['nowhere']]);

    $response = $this->postJson('/demo/reset');

    $response->assertStatus(503);

    expect($response->json('message'))->toBe('This demonstration cannot be rebuilt right now.')
        ->and($response->getContent())->not->toContain('nowhere');
});

it('sends a browser back with a message when asked to', function (): void {
    Queue::fake();

    onDemand([
        'demo.on_demand.redirect' => '/demo',
        'demo.on_demand.middleware' => [StartSession::class],
    ]);

    $this->post('/demo/reset')
        ->assertRedirect('/demo')
        ->assertSessionHas('status');
});

/**
 * A redirect configured without session middleware is the application's mistake;
 * answering it with a 500 from inside this package would hide which mistake.
 */
it('redirects without flashing when there is no session to flash to', function (): void {
    Queue::fake();

    onDemand(['demo.on_demand.redirect' => '/demo']);

    $this->post('/demo/reset')->assertRedirect('/demo');
});

it('only answers POST', function (): void {
    onDemand();

    $this->get('/demo/reset')->assertStatus(405);
});

/**
 * Both throttle keys are discardable by the visitor — a session cookie is
 * deleted, an IP is changed — so neither is a real limit on somebody determined.
 * The cooldown is, because it counts resets rather than requesters. This asserts
 * the layering rather than pretending the throttle is a boundary.
 */
it('still refuses a second visitor inside the cooldown, whatever the throttle counts', function (): void {
    Queue::fake();

    onDemand(['demo.on_demand.cooldown' => 900, 'demo.on_demand.per' => 'session']);

    cache()->store()->forever(CacheKeys::LAST_RESET, CarbonImmutable::now()->toIso8601String());

    /* A fresh session each time, as a visitor clearing cookies would have. */
    $this->flushSession();
    $this->postJson('/demo/reset')->assertStatus(429);

    $this->flushSession();
    $this->postJson('/demo/reset')->assertStatus(429);

    Queue::assertNothingPushed();
});

/**
 * The throttle is middleware, so a request the controller would have 404'd had
 * already spent a rate-limit slot getting there. With 'per' set to 'global' and
 * one attempt an hour, one request with a forged Host header took the reset
 * button away from every real visitor for an hour.
 */
it('does not let a wrong-host request spend the throttle', function (): void {
    Queue::fake();

    onDemand([
        'demo.allowed_hosts' => ['demo.example.com'],
        'demo.on_demand.per' => 'global',
        'demo.on_demand.throttle' => ['attempts' => 1, 'minutes' => 60],
    ]);

    $this->postJson('http://app.example.com/demo/reset')->assertStatus(404);

    /* The real visitor still has their one attempt. */
    $this->postJson('http://demo.example.com/demo/reset')->assertStatus(202);
});
