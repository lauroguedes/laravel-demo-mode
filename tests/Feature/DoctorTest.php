<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Storage;
use LauroGuedes\DemoMode\Cleaners\FlushCache;
use LauroGuedes\DemoMode\Doctor\Doctor;
use LauroGuedes\DemoMode\Doctor\Finding;
use LauroGuedes\DemoMode\Restrictions\DisableMail;
use LauroGuedes\DemoMode\Support\CacheKeys;
use Workbench\App\Models\DemoUser;
use Workbench\App\Models\SandboxedNote;

function findings(): array
{
    return array_map(static fn (Finding $f): string => $f->toArray()['check'].':'.$f->toArray()['level'], app(Doctor::class)->run());
}

it('says an installation is not a demo before saying anything else', function (): void {
    Config::set('demo.enabled', false);

    expect(findings())->toBe(['demo-mode:warning']);
});

/**
 * published() rather than demo(), and on a faked disk, for two reasons that both
 * bit this test.
 *
 * A demo set up properly but never reset has nothing published, which is now a
 * finding of its own — correctly, because that demo's login page is two empty
 * boxes. "Set up properly" has to mean one that has actually run.
 *
 * And reading the real disk made the result depend on whether a stray
 * demo-credentials.json was lying around in the testbench application. One was,
 * left by a manual run, so this passed locally and only failed on CI — the same
 * way a published config once shadowed the package's own for two days.
 */
it('finds nothing wrong with a demo that is set up properly', function (): void {
    published([
        'demo.allowed_hosts' => ['demo.example.com'],
        'app.url' => 'https://demo.example.com',
        'demo.reset.strategies.migrate-fresh-seed.seeder' => Seeder::class,
        'demo.guards.protected' => [DemoUser::class => ['email' => 'admin@demo.test']],
        databaseNameKey() => 'demo_playground',
        /* The suite's array store would otherwise warn about single-process locks. */
        'cache.default' => 'file',
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
    demo([databaseNameKey() => '/var/lib/acme_production']);

    expect(findings())->toContain('database-name:warning');
});

it('says nothing about a database that looks disposable', function (string $name): void {
    demo([databaseNameKey() => $name]);

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

/**
 * With this guard on and a database session driver, the demo answers 403 to its
 * own framework on the first request — which reads like the guard working
 * rather than the guard misconfigured.
 */
it('errors when the connection guard would block the framework itself', function (): void {
    demo([
        'demo.guards.connection.enabled' => true,
        'demo.guards.connection.except_tables' => [],
        'session.driver' => 'database',
    ]);

    expect(findings())->toContain('connection-guard:error');
});

it('says nothing when the tables the framework writes to are excepted', function (): void {
    demo([
        'demo.guards.connection.enabled' => true,
        'demo.guards.connection.except_tables' => ['sessions'],
        'session.driver' => 'database',
    ]);

    expect(findings())->not->toContain('connection-guard:error');
});

it('says nothing about tables this application does not keep in the database', function (): void {
    demo([
        'demo.guards.connection.enabled' => true,
        'demo.guards.connection.except_tables' => [],
        'session.driver' => 'file',
        'cache.default' => 'array',
        'queue.default' => 'sync',
    ]);

    expect(findings())->not->toContain('connection-guard:error');
});

it('says nothing about the on-demand route when it is off', function (): void {
    demo(['demo.on_demand.enabled' => false]);

    expect(findings())->not->toContain('on-demand-reset:error', 'on-demand-reset:warning');
});

/**
 * Without the web group there is no CSRF token, and without that any page on the
 * internet could rebuild the demo with a form post the visitor never saw.
 */
it('errors when the on-demand route has no CSRF protection', function (): void {
    demo(['demo.on_demand.enabled' => true, 'demo.on_demand.middleware' => []]);

    expect(findings())->toContain('on-demand-reset:error');
});

it('accepts the web group', function (): void {
    demo(['demo.on_demand.enabled' => true, 'demo.on_demand.middleware' => ['web']]);

    expect(findings())->not->toContain('on-demand-reset:error');
});

/**
 * A throttle counts per visitor, so enough visitors are enough rebuilds.
 */
it('warns when the on-demand route has no cooldown', function (): void {
    demo([
        'demo.on_demand.enabled' => true,
        'demo.on_demand.middleware' => ['web'],
        'demo.on_demand.cooldown' => 0,
    ]);

    expect(findings())->toContain('on-demand-reset:warning');
});

it('warns when a queued rebuild would run inline anyway', function (): void {
    demo([
        'demo.on_demand.enabled' => true,
        'demo.on_demand.middleware' => ['web'],
        'demo.on_demand.queue' => true,
        'queue.default' => 'sync',
    ]);

    expect(findings())->toContain('on-demand-reset:warning');
});

/**
 * The cooldown is measured from the last recorded reset, which lives in the same
 * cache the reset flushes. Without the "except" list keeping it, every rebuild
 * erases the clock, the cooldown reads "never reset" and permits everything, and
 * nothing anywhere says so.
 */
it('errors when the reset would erase the clock its own cooldown is measured from', function (): void {
    demo([
        'demo.on_demand.enabled' => true,
        'demo.on_demand.middleware' => ['web'],
        'demo.on_demand.cooldown' => 900,
        'demo.cleaners' => [FlushCache::class => ['except' => []]],
    ]);

    expect(findings())->toContain('on-demand-reset:error');
});

it('accepts a cleaner that keeps the package its own keys', function (): void {
    demo([
        'demo.on_demand.enabled' => true,
        'demo.on_demand.middleware' => ['web'],
        'demo.on_demand.cooldown' => 900,
        'demo.cleaners' => [FlushCache::class => ['except' => ['demo-mode:*']]],
    ]);

    expect(findings())->not->toContain('on-demand-reset:error');
});

/**
 * NullStore implements LockProvider and hands back a lock whose acquire()
 * returns true for everybody, so the Runner's own instanceof check passes and
 * two migrate:fresh runs proceed against one database.
 */
it('errors when the cache store grants every lock', function (): void {
    demo(['cache.default' => 'null']);

    expect(findings())->toContain('reset-lock:error');
});

/**
 * The array store's locks are real but live inside one PHP process, so a reset in
 * a queue worker and one in a web request never contend.
 */
it('warns when locks cannot cross a process boundary', function (): void {
    demo(['cache.default' => 'array']);

    expect(findings())->toContain('reset-lock:warning');
});

it('says nothing about a store whose locks are real and shared', function (): void {
    demo(['cache.default' => 'file']);

    expect(findings())->not->toContain('reset-lock:error', 'reset-lock:warning');
});

/*
 * The demo that looks right and cannot be signed in to. Everything else passes —
 * flag on, guards clean, banner counting down — while the login page offers two
 * empty boxes, because publishing a password is something a reset does and no
 * reset has happened.
 */

it('warns when a demo publishes credentials and none are published', function (): void {
    Storage::fake('local');

    demo();

    expect(findings())->toContain('credentials-published:warning');
});

/**
 * A warning and not an error: this command is documented as belonging in a deploy
 * pipeline ahead of the first reset, so a fresh deployment legitimately has
 * nothing published and an error would fail the usage the README recommends.
 */
it('does not fail a pipeline that has simply not reset yet', function (): void {
    Storage::fake('local');

    demo();

    expect(findings())->not->toContain('credentials-published:error');
});

it('says nothing once a reset has published something', function (): void {
    published();

    expect(findings())->not->toContain('credentials-published:warning');
});

/**
 * The second shape, and the one worth telling apart: a reset happened and still
 * nothing is readable here. On one machine that means the cleaner ate it; across
 * machines it means the file store wrote to a disk the web process does not read.
 * The wording differs because the remedy does.
 */
it('says a reset ran when one did and there is still nothing to show', function (): void {
    Storage::fake('local');

    demo();

    cache()->store()->forever(CacheKeys::LAST_RESET, CarbonImmutable::now()->subHour()->toIso8601String());

    $doctor = app(Doctor::class)->run();

    $message = collect($doctor)
        ->first(fn (Finding $f): bool => $f->toArray()['check'] === 'credentials-published')
        ?->message;

    expect($message)->toContain('A reset ran at')
        ->and($message)->not->toContain('no reset has ever run');
});

it('says nothing at all when this demo publishes no credentials', function (): void {
    demo(['demo.credentials.enabled' => false]);

    expect(findings())->not->toContain('credentials-published:warning', 'credentials-published:error');
});

it('says nothing about the sandbox on a demo that shares its data', function (): void {
    demo(['demo.sandbox.driver' => 'shared']);

    expect(findings())->not->toContain('sandbox:error');
});

/**
 * The mistake demo:install's own checklist can produce: publish the migration,
 * add the column, mark the models, and never set DEMO_SANDBOX=scoped.
 *
 * Every scoped check returns early on a driver that is not scoped, so before this
 * warning existed that demo passed the doctor while every visitor shared
 * everything — and the checklist said the doctor would catch it.
 */
it('warns when models are marked for isolation nobody switched on', function (): void {
    demo(['demo.sandbox.driver' => 'shared', 'demo.sandbox.models' => [SandboxedNote::class]]);

    expect(findings())->toContain('sandbox:warning');
});

/**
 * A warning and not an error: a project can carry the trait and the list the whole
 * time and switch isolation on per deployment, which is what the env var is for.
 * A shared staging copy of a scoped demo should not fail a pipeline.
 */
it('does not fail a pipeline over it', function (): void {
    demo(['demo.sandbox.driver' => 'shared', 'demo.sandbox.models' => [SandboxedNote::class]]);

    expect(findings())->not->toContain('sandbox:error');
});

/**
 * A scoped demo with no marked models behaves exactly like a shared one, and
 * nothing about it looks wrong.
 */
it('errors when scoped isolation isolates nothing', function (): void {
    demo(['demo.sandbox.driver' => 'scoped', 'demo.sandbox.models' => []]);

    expect(findings())->toContain('sandbox:error');
});

/**
 * The silent one: an unmarked model means visitors see each other's rows in that
 * table while the rest of the demo looks isolated.
 */
it('errors on a model that is listed as sandboxed but is not', function (): void {
    demo(['demo.sandbox.driver' => 'scoped', 'demo.sandbox.models' => [DemoUser::class]]);

    expect(findings())->toContain('sandbox:error');
});

it('accepts a model that actually carries the trait', function (): void {
    demo(['demo.sandbox.driver' => 'scoped', 'demo.sandbox.models' => [SandboxedNote::class]]);

    freshSchema();

    expect(findings())->not->toContain('sandbox:error');
});

/**
 * Read-only blocks POSTs by route name and the reset route's name is a config key
 * of its own, so renaming either without the other makes the reset button 403 to
 * everybody.
 */
it('errors when read-only would block the demo reset route', function (): void {
    demo([
        'demo.on_demand.enabled' => true,
        'demo.on_demand.middleware' => ['web'],
        'demo.on_demand.name' => 'demo.rebuild',
        'demo.guards.read_only.enabled' => true,
        'demo.guards.read_only.except' => ['login'],
    ]);

    expect(findings())->toContain('on-demand-reset:error');
});

it('says nothing when read-only excepts the reset route', function (): void {
    demo([
        'demo.on_demand.enabled' => true,
        'demo.on_demand.middleware' => ['web'],
        'demo.guards.read_only.enabled' => true,
        'demo.guards.read_only.except' => ['demo.reset'],
    ]);

    expect(findings())->not->toContain('on-demand-reset:error');
});

/*
 * A button that reports success for doing nothing. The runtime deliberately does
 * not rescue this by falling back, because the other meaning of that button
 * rebuilds the installation.
 */
it('errors when the reset button is pointed at a sandbox that cannot exist', function (): void {
    demo([
        'demo.on_demand.enabled' => true,
        'demo.on_demand.scope' => 'sandbox',
        'demo.sandbox.driver' => 'shared',
    ]);

    $this->artisan('demo:doctor')
        ->expectsOutputToContain('nothing to clear')
        ->assertFailed();
});

/*
 * The exit code is not the assertion: turning on_demand on brings its own
 * findings, and this is about one of them being absent.
 */
it('says nothing when the button and the driver agree', function (): void {
    demo([
        'demo.on_demand.enabled' => true,
        'demo.on_demand.scope' => 'auto',
        'demo.sandbox.driver' => 'shared',
    ]);

    $this->artisan('demo:doctor')->doesntExpectOutputToContain('nothing to clear');
});
