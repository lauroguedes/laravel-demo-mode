<?php

declare(strict_types=1);

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Config;
use LauroGuedes\DemoMode\Cleaners\FlushCache;
use LauroGuedes\DemoMode\Doctor\Doctor;
use LauroGuedes\DemoMode\Doctor\Finding;
use LauroGuedes\DemoMode\Restrictions\DisableMail;

function findings(): array
{
    return array_map(static fn (Finding $f): string => $f->toArray()['check'].':'.$f->toArray()['level'], app(Doctor::class)->run());
}

it('says an installation is not a demo before saying anything else', function (): void {
    Config::set('demo.enabled', false);

    expect(findings())->toBe(['demo-mode:warning']);
});

it('finds nothing wrong with a demo that is set up properly', function (): void {
    demo([
        'demo.allowed_hosts' => ['demo.example.com'],
        'app.url' => 'https://demo.example.com',
        'demo.reset.strategies.migrate-fresh-seed.seeder' => Seeder::class,
        'demo.guards.protected' => [stdClass::class => ['email' => 'admin@demo.test']],
        'database.connections.testing.database' => 'demo_playground',
    ]);

    expect(findings())->toBe([]);
});

it('errors when a demo would refuse to reset', function (): void {
    demo(['demo.environments' => ['nowhere']]);

    expect(findings())->toContain('reset-guards:error');
});

/**
 * The worst failure mode in the package: a working administrator password served
 * at a guessable URL, which stays served after DEMO_MODE goes off because the
 * flag has no say over what a web server hands out.
 */
it('errors when the credentials would be served over HTTP', function (array $disk): void {
    demo(['filesystems.disks.leaky' => $disk, 'demo.credentials.stores.file.disk' => 'leaky']);

    expect(findings())->toContain('credentials-disk:error');
})->with([
    'a disk with a public URL' => [['driver' => 'local', 'root' => '/srv/app/public', 'url' => 'https://demo.example.com/storage']],
    'a publicly visible disk' => [['driver' => 'local', 'root' => '/srv/app/storage', 'visibility' => 'public']],
]);

it('errors when the credentials disk does not exist', function (): void {
    demo(['demo.credentials.stores.file.disk' => 'nowhere']);

    expect(findings())->toContain('credentials-disk:error');
});

/**
 * Silent in production, which is why it is worth a check: every reset publishes
 * a password and then erases it, and the login page shows credentials that do
 * not work with nothing in any log to say why.
 */
it('errors when cached credentials would be flushed away', function (): void {
    demo([
        'demo.credentials.store' => 'cache',
        'demo.cleaners' => [FlushCache::class => ['except' => []]],
    ]);

    expect(findings())->toContain('credentials-store:error');
});

it('accepts cached credentials that the cleaner is told to keep', function (): void {
    demo([
        'demo.credentials.store' => 'cache',
        'demo.cleaners' => [FlushCache::class => ['except' => ['demo-mode:*']]],
    ]);

    expect(findings())->not->toContain('credentials-store:error');
});

it('errors on a seeder that does not exist', function (): void {
    demo(['demo.reset.strategies.migrate-fresh-seed.seeder' => 'Database\Seeders\NotHere']);

    expect(findings())->toContain('reset-strategy:error');
});

it('errors on a schedule it cannot read', function (): void {
    demo(['demo.reset.schedule' => 'every so often']);

    expect(findings())->toContain('reset-schedule:error');
});

it('warns when mail can still leave the server', function (): void {
    demo(['mail.default' => 'smtp', 'demo.restrictions' => []]);

    expect(findings())->toContain('mail:warning');
});

it('says nothing about mail when DisableMail is enabled', function (): void {
    demo(['mail.default' => 'smtp', 'demo.restrictions' => [DisableMail::class => []]]);

    expect(findings())->not->toContain('mail:warning');
});

it('warns when a visitor could lock the next one out', function (): void {
    demo(['demo.guards.protected' => []]);

    expect(findings())->toContain('protected-accounts:warning');
});

it('warns when nothing rotates', function (): void {
    demo(['demo.credentials.accounts' => [['email' => 'fixed@demo.test', 'rotate' => false, 'password' => 'x']]]);

    expect(findings())->toContain('credentials-rotation:warning');
});

it('warns when the database does not look disposable', function (): void {
    demo(['database.connections.testing.database' => '/var/lib/acme_production']);

    expect(findings())->toContain('database-name:warning');
});

it('says nothing about a database that looks disposable', function (string $name): void {
    demo(['database.connections.testing.database' => $name]);

    expect(findings())->not->toContain('database-name:warning');
})->with(['acme_demo', 'staging', '/var/lib/playground.sqlite', 'acme_sandbox', ':memory:']);

it('exits non-zero on an error, so a deploy pipeline stops', function (): void {
    demo(['demo.environments' => ['nowhere']]);

    $this->artisan('demo:doctor')->assertFailed();
});

/**
 * Warnings deliberately do not stop a pipeline. A command that failed on every
 * imperfection would be a command people pass --no-verify to.
 */
it('exits zero on warnings alone', function (): void {
    demo([
        'demo.allowed_hosts' => null,
        'demo.reset.strategies.migrate-fresh-seed.seeder' => Seeder::class,
        'demo.guards.protected' => [],
        'mail.default' => 'smtp',
        'demo.restrictions' => [],
    ]);

    $this->artisan('demo:doctor')->assertSuccessful();
});

it('prints JSON a pipeline can read', function (): void {
    demo(['demo.environments' => ['nowhere']]);

    $this->artisan('demo:doctor', ['--json' => true])->assertFailed();
});
