<?php

declare(strict_types=1);

use Illuminate\Auth\Events\Login;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Support\Facades\Config;
use Illuminate\Validation\ValidationException;
use LauroGuedes\DemoMode\Configuration;
use LauroGuedes\DemoMode\DemoModeServiceProvider;
use LauroGuedes\DemoMode\Restrictions\BlockPrivilegedAccounts;
use LauroGuedes\DemoMode\Restrictions\DisableMail;
use LauroGuedes\DemoMode\Restrictions\ForceConfig;
use LauroGuedes\DemoMode\Support\PinnedConfigRepository;
use Workbench\App\Models\DemoUser;

it('applies nothing at all when the installation is not a demo', function (): void {
    Config::set('demo.enabled', false);
    Config::set('mail.default', 'smtp');

    app()->register(DemoModeServiceProvider::class, force: true);

    expect(config('mail.default'))->toBe('smtp');
});

it('sends mail nowhere on a demo', function (): void {
    demo(['mail.default' => 'smtp']);

    expect(config('mail.default'))->toBe('array');
});

/**
 * Setting a value at boot is not pinning it. An application with a settings
 * screen writes config at request time, and the settings that matter on a demo
 * are the ones whose wrong value locks the next visitor out.
 */
it('refuses a runtime write to a pinned key', function (): void {
    demo(['demo.restrictions' => [ForceConfig::class => ['pin' => ['settings.verify_email' => false]]]]);

    config(['settings.verify_email' => true]);

    expect(config('settings.verify_email'))->toBeFalse();
});

it('refuses a pinned key inside a batch write and lets the rest through', function (): void {
    demo(['demo.restrictions' => [ForceConfig::class => ['pin' => ['settings.verify_email' => false]]]]);

    config(['settings.verify_email' => true, 'settings.theme' => 'dark']);

    expect(config('settings.verify_email'))->toBeFalse()
        ->and(config('settings.theme'))->toBe('dark');
});

/**
 * ForceConfig swaps the container's config binding. Configuration is documented
 * as the single place that reads the demo config, so it has to be reading the
 * repository that is actually installed rather than the one it was built with.
 */
it('keeps the single point of truth pointed at the live repository', function (): void {
    demo(['demo.restrictions' => [ForceConfig::class => ['pin' => ['settings.verify_email' => false]]]]);

    expect(app(Repository::class))->toBeInstanceOf(PinnedConfigRepository::class);

    app(Repository::class)->set('demo.reset.schedule', 'daily');

    expect(app(Configuration::class)->string('reset.schedule'))->toBe('daily');
});

it('leaves the config repository alone when nothing is pinned', function (): void {
    demo(['demo.restrictions' => [ForceConfig::class => ['pin' => []]]]);

    expect(app(Repository::class))->not->toBeInstanceOf(PinnedConfigRepository::class);
});

it('stops a privileged account signing in', function (): void {
    demo(['demo.restrictions' => [
        BlockPrivilegedAccounts::class => ['emails' => ['owner@example.com'], 'message' => 'Not here.'],
    ]]);

    $user = new DemoUser(['email' => 'owner@example.com']);

    expect(fn (): mixed => event(new Login('web', $user, false)))
        ->toThrow(ValidationException::class);
});

it('lets an ordinary account through', function (): void {
    demo(['demo.restrictions' => [
        BlockPrivilegedAccounts::class => ['emails' => ['owner@example.com']],
    ]]);

    $user = new DemoUser(['email' => 'visitor@demo.test']);

    event(new Login('web', $user, false));

    expect(true)->toBeTrue();
});

it('matches a privileged address whatever its case', function (): void {
    demo(['demo.restrictions' => [
        BlockPrivilegedAccounts::class => ['emails' => ['Owner@Example.com']],
    ]]);

    $user = new DemoUser(['email' => 'owner@example.com']);

    expect(fn (): mixed => event(new Login('web', $user, false)))
        ->toThrow(ValidationException::class);
});

it('does nothing when no roles or addresses are named', function (): void {
    demo(['demo.restrictions' => [BlockPrivilegedAccounts::class => []]]);

    $user = new DemoUser(['email' => 'anyone@demo.test']);

    event(new Login('web', $user, false));

    expect(true)->toBeTrue();
});

it('describes what is active for demo:status', function (): void {
    demo();

    $this->artisan('demo:status')->assertSuccessful();
});

it('is reported by the mail restriction description', function (): void {
    expect(app(DisableMail::class)->describe())->toBe('Mail goes nowhere');
});
