<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Config;

/**
 * Safe to put in a layout unconditionally. If it rendered anything on a
 * non-demo, every application would have to wrap it in @demo, and the one that
 * forgot would tell its real users their data was temporary.
 */
it('renders nothing at all when the installation is not a demo', function (): void {
    Config::set('demo.enabled', false);

    expect(trim(Blade::render('<x-demo-banner />')))->toBe('');
});

it('renders nothing when the banner is switched off', function (): void {
    demo(['demo.banner.enabled' => false]);

    expect(trim(Blade::render('<x-demo-banner />')))->toBe('');
});

/**
 * The bug this component exists to fix: a hardcoded "resets every 24 hours"
 * message on a demo whose schedule was configurable and usually hourly.
 */
it('counts down from the same cron expression the scheduler runs', function (): void {
    CarbonImmutable::setTestNow('2026-09-22 09:30:00');

    demo(['demo.reset.schedule' => 'hourly']);

    $html = Blade::render('<x-demo-banner />');

    expect($html)->toContain('30 minutes')
        ->and($html)->toContain('data-demo-reset-at="2026-09-22T10:00:00+00:00"')
        ->and($html)->toContain('<time datetime="2026-09-22T10:00:00+00:00" data-demo-countdown');
});

it('says the data is temporary without a countdown when the schedule is unreadable', function (): void {
    demo(['demo.reset.schedule' => 'whenever', 'demo.script' => false]);

    $html = Blade::render('<x-demo-banner />');

    expect($html)->toContain('deleted periodically')
        ->and($html)->not->toContain('<time');
});

it('uses a configured message verbatim', function (): void {
    demo(['demo.banner.message' => 'Have a look around.']);

    expect(Blade::render('<x-demo-banner />'))->toContain('Have a look around.');
});

/**
 * A translation file is data, and data does not get to inject markup into every
 * page of an application.
 */
it('escapes a message rather than rendering it as markup', function (): void {
    demo(['demo.banner.message' => '<script>alert(1)</script>']);

    $html = Blade::render('<x-demo-banner />');

    expect($html)->not->toContain('<script>alert(1)</script>')
        ->and($html)->toContain('&lt;script&gt;');
});

it('escapes a translation that tries the same thing', function (): void {
    app('translator')->addLines([
        'demo.banner.with_countdown' => 'Deleted in :time <img src=x onerror=1>',
    ], 'en', 'demo');

    demo();

    expect(Blade::render('<x-demo-banner />'))->toContain('&lt;img src=x')
        ->and(Blade::render('<x-demo-banner />'))->toContain('data-demo-countdown');
});

it('takes the variant, position and classes from the config', function (): void {
    demo([
        'demo.banner.variant' => 'danger',
        'demo.banner.position' => 'bottom',
        'demo.banner.classes' => ['danger' => 'alert alert-danger'],
    ]);

    $html = Blade::render('<x-demo-banner />');

    expect($html)->toContain('data-demo-variant="danger"')
        ->and($html)->toContain('data-demo-position="bottom"')
        ->and($html)->toContain('alert alert-danger');
});

it('lets an attribute override the config', function (): void {
    demo(['demo.banner.variant' => 'warning', 'demo.banner.dismissible' => true, 'demo.script' => false]);

    $html = Blade::render('<x-demo-banner variant="info" :dismissible="false" />');

    expect($html)->toContain('data-demo-variant="info"')
        ->and($html)->not->toContain('data-demo-dismiss');
});

it('renders a dismiss button with an accessible label', function (): void {
    demo(['demo.banner.dismissible' => true]);

    $html = Blade::render('<x-demo-banner />');

    expect($html)->toContain('data-demo-dismiss')
        ->and($html)->toContain('aria-label="Dismiss"');
});

/**
 * A dismiss button with no script behind it is a control that lies. Under a
 * strict Content-Security-Policy every one of them did: the markup asked
 * banner.dismissible, the behaviour came from the script, and nothing reconciled
 * the two.
 */
it('does not render a dismiss button when the script will not be on the page', function (): void {
    demo(['demo.banner.dismissible' => true, 'demo.script' => false]);

    expect(Blade::render('<x-demo-banner />'))->not->toContain('data-demo-dismiss');
});

it('gives the countdown its unit words from the translations', function (): void {
    demo();
    app()->setLocale('pt_BR');

    expect(Blade::render('<x-demo-banner />'))->toContain('data-demo-unit-minute="min"');
});

it('announces itself politely rather than interrupting a screen reader', function (): void {
    demo();

    expect(Blade::render('<x-demo-banner />'))
        ->toContain('role="status"')
        ->toContain('aria-live="polite"');
});

it('ships its script once, and not at all when switched off', function (): void {
    demo(['demo.script' => true]);

    expect(substr_count(Blade::render('<x-demo-banner /><x-demo-banner />'), 'data-demo-script'))->toBe(1);

    demo(['demo.script' => false]);

    expect(Blade::render('<x-demo-banner />'))->not->toContain('data-demo-script');
});

it('speaks Brazilian Portuguese', function (): void {
    CarbonImmutable::setTestNow('2026-09-22 09:30:00');

    demo(['demo.reset.schedule' => 'hourly']);
    app()->setLocale('pt_BR');

    expect(Blade::render('<x-demo-banner />'))->toContain('Esta é uma demonstração');
});

/**
 * Two components, one script. @once without an explicit id compiles to a fresh
 * UUID per occurrence, so the banner's block and the credentials component's
 * block were separate keys and a page carrying both emitted the script twice.
 * Two banners did not catch it: they share one compiled view, and so one id.
 */
it('ships its script once across different components on the same page', function (): void {
    published(['demo.script' => true]);

    $html = Blade::render('<x-demo-banner /><x-demo-credentials />');

    expect(substr_count($html, 'data-demo-script'))->toBe(1);
});
