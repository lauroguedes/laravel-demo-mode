<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Route;
use LauroGuedes\DemoMode\Http\Controllers\AssetController;

/**
 * The floating bar. Everything it shows is decided on the server and handed over
 * as one JSON payload, because the element renders inside a shadow root where
 * nothing the application ships can reach it — which is the point, and also means
 * a wrong payload is invisible until somebody opens the page.
 */
function bar(array $config = []): string
{
    demo(['demo.banner.style' => 'pill', ...$config]);

    return Blade::render('<x-demo-banner />');
}

function state(string $html): array
{
    expect($html)->toContain('<demo-mode-bar');

    preg_match('/data-demo-state="([^"]*)"/', $html, $matches);

    return json_decode(htmlspecialchars_decode($matches[1] ?? '', ENT_QUOTES), true) ?? [];
}

it('renders the element and its script', function (): void {
    $html = bar();

    expect($html)->toContain('<demo-mode-bar')
        ->and($html)->toContain('/demo-mode/bar.js?v='.AssetController::version());
});

it('renders nothing at all when this is not a demo', function (): void {
    Config::set('demo.enabled', false);
    Config::set('demo.banner.style', 'pill');

    expect(Blade::render('<x-demo-banner />'))->toBe('');
});

/**
 * Word order differs by language, so the bar is handed the sentence either side
 * of the time rather than a string it has to cut up itself.
 */
it('splits the sentence around the countdown', function (): void {
    $payload = state(bar(['demo.reset.schedule' => 'hourly']));

    expect($payload['messageBefore'])->toContain('Resets in')
        ->and($payload['countdown'])->not->toBeNull()
        ->and($payload['nextResetAt'])->not->toBeNull()
        ->and($payload['units']['minute'])->toBe('m');
});

/**
 * A fixed message cannot count down, so the bar is told there is nothing to
 * wrap rather than being left to notice.
 */
it('hands over a flat sentence when the message is fixed', function (): void {
    $payload = state(bar(['demo.banner.message' => 'Look but do not touch.']));

    expect($payload['message'])->toBe('Look but do not touch.')
        ->and($payload['countdown'])->toBeNull()
        ->and($payload['messageBefore'])->toBeNull();
});

it('carries the badge and the call to action', function (): void {
    $payload = state(bar([
        'demo.banner.label' => 'PLAYGROUND',
        'demo.banner.cta' => ['label' => 'Deploy your own', 'url' => 'https://example.test'],
    ]));

    expect($payload['label'])->toBe('PLAYGROUND')
        ->and($payload['cta'])->toBe(['label' => 'Deploy your own', 'url' => 'https://example.test']);
});

it('leaves the call to action out when it is half configured', function (): void {
    $payload = state(bar(['demo.banner.cta' => ['label' => 'Deploy your own']]));

    expect($payload['cta'])->toBeNull();
});

describe('the reset button', function (): void {
    /*
     * Through a real request rather than Blade::render: the token comes from the
     * session, and a session only exists once the middleware has started one.
     */
    it('is offered with a token when visitors may rebuild', function (): void {
        demo(['demo.banner.style' => 'pill', 'demo.on_demand.enabled' => true]);

        Route::middleware('web')->get('/bar', fn (): string => Blade::render('<x-demo-banner />'));

        $payload = state($this->get('/bar')->assertOk()->getContent());

        expect($payload['resetUrl'])->toEndWith('/demo/reset')
            ->and($payload['token'])->not->toBeEmpty();
    });

    /*
     * A layout carrying the bar is also rendered outside a request — a mail
     * view, a queued document. csrf_token() throws there, and refusing to render
     * the page because a control on it wants a token is the wrong trade.
     */
    it('renders without one rather than throwing when there is no session', function (): void {
        $payload = state(bar(['demo.on_demand.enabled' => true]));

        expect($payload['resetUrl'])->toEndWith('/demo/reset')
            ->and($payload['token'])->toBeNull();
    });

    it('is absent when they may not, and so is the token', function (): void {
        $payload = state(bar(['demo.on_demand.enabled' => false]));

        expect($payload['resetUrl'])->toBeNull()
            ->and($payload['token'])->toBeNull();
    });

    it('can be turned off without turning the route off', function (): void {
        $payload = state(bar([
            'demo.on_demand.enabled' => true,
            'demo.banner.reset_button' => false,
        ]));

        expect($payload['resetUrl'])->toBeNull();
    });
});

/**
 * A shadow root cannot be expressed as markup, so a pill with no script is a
 * blank page. It becomes the bare banner instead, which needs none.
 */
it('falls back to the bare banner when the script is switched off', function (): void {
    $html = bar(['demo.script' => false]);

    expect($html)->not->toContain('<demo-mode-bar')
        ->and($html)->toContain('data-demo-banner');
});

describe('the script route', function (): void {
    it('serves the file and lets a browser cache it for good', function (): void {
        demo(['demo.banner.style' => 'pill']);

        $this->get('/demo-mode/bar.js')
            ->assertOk()
            ->assertHeader('Cache-Control', 'immutable, max-age=31536000, public')
            ->assertSee('demo-mode-bar', escape: false);
    });

    it('answers a revalidation without sending the file again', function (): void {
        demo(['demo.banner.style' => 'pill']);

        $this->get('/demo-mode/bar.js', ['If-None-Match' => '"'.AssetController::version().'"'])
            ->assertStatus(304);
    });

    /*
     * The route exists for the bar and nothing else, so an installation on the
     * bare banner should not be serving a script nothing asks for.
     */
    it('is not registered for the bare banner', function (): void {
        demo(['demo.banner.style' => 'bare']);

        $this->get('/demo-mode/bar.js')->assertNotFound();
    });
});
