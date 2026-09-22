<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Storage;
use LauroGuedes\DemoMode\Credentials\Manager;

it('renders nothing when the installation is not a demo', function (): void {
    published();

    Config::set('demo.enabled', false);
    app()->forgetInstance(Manager::class);

    expect(trim(Blade::render('<x-demo-credentials />')))->toBe('');
});

it('renders nothing when nothing has been published yet', function (): void {
    Storage::fake('local');
    demo();

    expect(trim(Blade::render('<x-demo-credentials />')))->toBe('');
});

it('renders nothing when the demo publishes no credentials', function (): void {
    demo(['demo.credentials.enabled' => false]);

    expect(trim(Blade::render('<x-demo-credentials />')))->toBe('');
});

it('shows the email and the password a visitor signs in with', function (): void {
    $published = published()[0];

    $html = Blade::render('<x-demo-credentials />');

    expect($html)->toContain($published->email)
        ->and($html)->toContain($published->password)
        ->toContain('data-demo-primary');
});

it('lists every published account with its label', function (): void {
    published(['demo.credentials.accounts' => [
        ['email' => 'admin@demo.test', 'label' => 'Administrator', 'primary' => true],
        ['email' => 'viewer@demo.test', 'label' => 'Read-only'],
    ]]);

    $html = Blade::render('<x-demo-credentials />');

    expect(substr_count($html, 'data-demo-account'))->toBe(2)
        ->and($html)->toContain('Administrator')
        ->toContain('Read-only');
});

/**
 * Asserted on the attribute with its value, because the script's own selector
 * text is 'data-demo-copy' too and matching that would pass either way.
 */
it('can be asked not to render copy buttons', function (): void {
    published();

    expect(Blade::render('<x-demo-credentials :copyable="false" />'))->not->toContain('data-demo-copy="');
});

/**
 * Same reasoning as the banner's dismiss button: a copy button with no script
 * behind it does nothing when clicked.
 */
it('does not render copy buttons when the script will not be on the page', function (): void {
    published(['demo.script' => false]);

    expect(Blade::render('<x-demo-credentials />'))->not->toContain('data-demo-copy="');
});

it('escapes a password that happens to contain markup characters', function (): void {
    published(['demo.credentials.accounts' => [
        ['email' => 'admin@demo.test', 'rotate' => false, 'password' => '<b>x&y</b>', 'primary' => true],
    ]]);

    expect(Blade::render('<x-demo-credentials />'))->not->toContain('<b>x&y</b>')
        ->and(Blade::render('<x-demo-credentials />'))->toContain('&lt;b&gt;');
});
