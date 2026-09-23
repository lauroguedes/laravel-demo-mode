<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Event;
use LauroGuedes\DemoMode\Configuration;
use LauroGuedes\DemoMode\Events\WriteBlocked;
use LauroGuedes\DemoMode\Exceptions\DemoWriteProhibited;
use LauroGuedes\DemoMode\Guards\ProtectedRecords;
use Workbench\App\Models\DemoUser;
use Workbench\App\Models\GuardedWidget;

beforeEach(function (): void {
    app('migrator')->path(__DIR__.'/../../workbench/database/migrations');

    demo(['demo.guards.protected' => [DemoUser::class => ['email' => 'admin@demo.test']]]);

    $this->artisan('migrate', ['--force' => true]);

    /*
     * In-memory sqlite starts empty every test; MySQL and PostgreSQL do not, and
     * a count assertion that passes only on the throwaway connection is not
     * testing what it says.
     */
    DB::table('demo_users')->delete();
    DB::table('demo_widgets')->delete();
});

/**
 * The concrete failure: a visitor signs in with the published credentials, opens
 * the profile page and changes the email. Both are ordinary features working
 * correctly. From that moment until the next reset the login page shows
 * credentials that do not work and nobody else can get in.
 */
it('stops a visitor changing the published account out from under the next one', function (): void {
    $admin = DemoUser::create(['email' => 'admin@demo.test', 'password' => 'x']);

    expect(fn (): mixed => $admin->update(['email' => 'mine@example.com']))
        ->toThrow(DemoWriteProhibited::class);

    expect(DemoUser::where('email', 'admin@demo.test')->exists())->toBeTrue();
});

it('stops the password being changed too', function (): void {
    $admin = DemoUser::create(['email' => 'admin@demo.test', 'password' => 'x']);

    expect(fn (): mixed => $admin->update(['password' => 'theirs']))
        ->toThrow(DemoWriteProhibited::class);
});

it('stops the published account being deleted', function (): void {
    $admin = DemoUser::create(['email' => 'admin@demo.test', 'password' => 'x']);

    expect(fn (): mixed => $admin->delete())->toThrow(DemoWriteProhibited::class)
        ->and(DemoUser::count())->toBe(1);
});

/**
 * Guarding only the stored values would let a visitor rename some other record
 * into the protected identity and then own it. The incoming values are checked
 * for that reason.
 */
it('stops another record being renamed into the protected identity', function (): void {
    $other = DemoUser::create(['email' => 'visitor@demo.test', 'password' => 'x']);

    expect(fn (): mixed => $other->update(['email' => 'admin@demo.test']))
        ->toThrow(DemoWriteProhibited::class);
});

/**
 * And the seeder has to be able to make the thing in the first place. A guard
 * that blocked creation would fail on the demo's own first reset.
 */
it('lets the seeder create the protected record', function (): void {
    $admin = DemoUser::create(['email' => 'admin@demo.test', 'password' => 'x']);

    expect($admin->exists)->toBeTrue();
});

it('leaves every other record alone', function (): void {
    $visitor = DemoUser::create(['email' => 'visitor@demo.test', 'password' => 'x']);

    $visitor->update(['email' => 'changed@demo.test']);

    expect($visitor->fresh()->email)->toBe('changed@demo.test');
});

/**
 * The observer is only attached when the provider boots on a demo, so the
 * cheapest honest assertion is that a non-demo never attaches it.
 */
it('attaches no observer on an installation that is not a demo', function (): void {
    config(['demo.enabled' => false, 'demo.guards.protected' => [DemoUser::class => ['email' => 'admin@demo.test']]]);

    expect(app(ProtectedRecords::class)->models())
        ->toBe([DemoUser::class]);

    /* Configured, but the provider returns before registering anything. */
    expect(app(Configuration::class)->enabled())->toBeFalse();
});

it('accepts a closure matcher for anything an attribute map cannot express', function (): void {
    demo(['demo.guards.protected' => [
        DemoUser::class => fn (DemoUser $user): bool => str_ends_with((string) $user->email, '@demo.test'),
    ]]);

    $visitor = DemoUser::create(['email' => 'visitor@example.com', 'password' => 'x']);

    expect(fn (): mixed => $visitor->update(['email' => 'someone@demo.test']))
        ->toThrow(DemoWriteProhibited::class);
});

it('announces every block so an application can count them', function (): void {
    Event::fake([WriteBlocked::class]);

    $admin = DemoUser::create(['email' => 'admin@demo.test', 'password' => 'x']);

    try {
        $admin->update(['email' => 'mine@example.com']);
    } catch (DemoWriteProhibited) {
        //
    }

    Event::assertDispatched(WriteBlocked::class, fn (WriteBlocked $e): bool => $e->layer === 'model');
});

it('renders as a refusal rather than a server error', function (): void {
    expect(new DemoWriteProhibited('nope')->getStatusCode())->toBe(403);
});

/**
 * The trait is the alternative to being listed in the provider's loop, and it
 * has to end up at the same guard. It still needs a matcher in config to say
 * which records are protected — a trait that guessed would freeze a whole table.
 */
it('protects a model that declares the trait itself', function (): void {
    demo(['demo.guards.protected' => [GuardedWidget::class => ['name' => 'seeded']]]);

    $widget = GuardedWidget::create(['name' => 'seeded']);

    expect(fn (): mixed => $widget->update(['name' => 'mine']))
        ->toThrow(DemoWriteProhibited::class);
});

it('leaves a trait-declared model alone when nothing about it is protected', function (): void {
    demo(['demo.guards.protected' => []]);

    $widget = GuardedWidget::create(['name' => 'seeded']);
    $widget->update(['name' => 'mine']);

    expect($widget->fresh()->name)->toBe('mine');
});

/**
 * Documented rather than fixed, and asserted so the documentation cannot drift
 * from the behaviour. Eloquent\Builder::update() calls straight through to the
 * query builder, so no per-model event fires and this guard never sees it.
 *
 * A visitor cannot choose this path — only the application's own code can — but
 * "the record is protected" is a sentence people rely on, so what it does not
 * cover belongs in a test as much as in the docs.
 */
it('does not see a bulk update, which is why the docs say so', function (): void {
    DemoUser::create(['email' => 'admin@demo.test', 'password' => 'x']);

    DemoUser::where('email', 'admin@demo.test')->update(['password' => 'theirs']);

    expect(DemoUser::where('email', 'admin@demo.test')->value('password'))->toBe('theirs');
});

it('does not see a write that suppresses events', function (): void {
    $admin = DemoUser::create(['email' => 'admin@demo.test', 'password' => 'x']);

    $admin->updateQuietly(['password' => 'theirs']);

    expect($admin->fresh()->password)->toBe('theirs');
});

/**
 * Laravel passes an HttpExceptionInterface to the error view and the stock 403
 * view prints its message, so whatever is in there is shown to the visitor. The
 * model class belongs in the log, not on a page.
 */
it('tells the visitor a sentence, not a class name', function (): void {
    $admin = DemoUser::create(['email' => 'admin@demo.test', 'password' => 'x']);

    try {
        $admin->update(['email' => 'mine@example.com']);
        $message = null;
    } catch (DemoWriteProhibited $e) {
        $message = $e->getMessage();
    }

    expect($message)->toBe('That cannot be changed in the demonstration.')
        ->and($message)->not->toContain('DemoUser')
        ->not->toContain('\\');
});
