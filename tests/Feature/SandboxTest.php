<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use LauroGuedes\DemoMode\Events\SandboxCreated;
use LauroGuedes\DemoMode\Events\SandboxExpired;
use LauroGuedes\DemoMode\Sandbox\Manager;
use LauroGuedes\DemoMode\Sandbox\Sandbox;
use Workbench\App\Models\SandboxedNote;

beforeEach(function (): void {
    demo(['demo.sandbox.driver' => 'scoped']);

    freshSchema();

    /* What the seeder made: everybody's. */
    DB::table('demo_notes')->insert(['body' => 'A seeded note', 'demo_sandbox_id' => null]);

    /*
     * The session middleware rather than the whole web group: sessions are what
     * scoped isolation actually needs, and CSRF is the one part of web these
     * tests are not about.
     */
    Route::middleware([EncryptCookies::class, AddQueuedCookiesToResponse::class, StartSession::class, 'demo.sandbox'])->group(function (): void {
        Route::get('/notes', fn (): array => ['notes' => SandboxedNote::pluck('body')->all()]);
        Route::post('/notes', function (): array {
            SandboxedNote::create(['body' => request()->string('body')->toString()]);

            return ['notes' => SandboxedNote::pluck('body')->all()];
        });
    });
});

it('gives a visitor the seeded baseline plus nothing of their own yet', function (): void {
    $this->getJson('/notes')->assertJson(['notes' => ['A seeded note']]);
});

/**
 * Reading never creates. The scope asks the Manager on every query against a
 * marked model, so minting a row there meant one INSERT for every request that
 * arrived without a cookie — which a crawler produces as fast as it likes. The
 * package puts three limits in front of the reset route; this would have been
 * the same exposure with none.
 */
it('creates nothing for a visitor who only looks', function (): void {
    $this->getJson('/notes');
    $this->flushSession();
    $this->getJson('/notes');
    $this->flushSession();
    $this->getJson('/notes');

    expect(Sandbox::query()->count())->toBe(0);
});

it('creates one the moment a visitor writes something', function (): void {
    $this->postJson('/notes', ['body' => 'mine']);

    expect(Sandbox::query()->count())->toBe(1);
});

/**
 * The property the whole feature exists for.
 */
it('keeps two visitors out of each other\'s notes', function (): void {
    $this->postJson('/notes', ['body' => 'mine'])
        ->assertJson(['notes' => ['A seeded note', 'mine']]);

    /* A different browser: no session, so a different sandbox. */
    $this->flushSession();

    $this->getJson('/notes')->assertJson(['notes' => ['A seeded note']]);

    $this->postJson('/notes', ['body' => 'theirs'])
        ->assertJson(['notes' => ['A seeded note', 'theirs']]);
});

/**
 * The security design: the identifier a browser sends is a lookup key with no
 * authority. A forged one has to mint a new empty sandbox, never select
 * somebody else's rows — otherwise the global scope is an access-control
 * decision made from user input.
 */
/**
 * The sandbox separates what visitors create. It does not copy what the seeder
 * made, so the baseline stays one shared set of rows — and a visitor who deletes
 * one has deleted it for everybody until the next reset.
 *
 * Asserted rather than left implied, because it is the thing people assume
 * isolation means. guards.protected and guards.read_only are the answer if the
 * seeded rows must survive a visitor; the reset cycle is the other half.
 */
it('does not isolate the seeded baseline from the visitor who deletes it', function (): void {
    Route::middleware([EncryptCookies::class, AddQueuedCookiesToResponse::class, StartSession::class, 'demo.sandbox'])
        ->delete('/notes', function (): array {
            SandboxedNote::where('body', 'A seeded note')->delete();

            return ['notes' => SandboxedNote::pluck('body')->all()];
        });

    /* One visitor, with a sandbox of their own, removes a seeded row. */
    $this->postJson('/notes', ['body' => 'mine'])->assertOk();
    $this->deleteJson('/notes')->assertJson(['notes' => ['mine']]);

    /* And it is gone for somebody who never had one. */
    $this->flushSession();

    $this->getJson('/notes')->assertJson(['notes' => []]);
});

it('gives a forged identifier nothing but the baseline', function (): void {
    $this->postJson('/notes', ['body' => 'mine']);

    $victim = Sandbox::query()->sole()->id;

    $this->flushSession();
    $this->withSession(['demo_sandbox' => $victim]);

    /*
     * The session is the trust boundary, so a value put there legitimately is
     * honoured — this asserts the row-must-exist rule from the other side.
     */
    $this->getJson('/notes')->assertJson(['notes' => ['A seeded note', 'mine']]);

    $this->flushSession();
    $this->withSession(['demo_sandbox' => (string) Str::ulid()]);

    /* An identifier matching no live row selects nothing and creates nothing. */
    $this->getJson('/notes')->assertJson(['notes' => ['A seeded note']]);

    expect(Sandbox::query()->count())->toBe(1);
});

it('stops honouring a sandbox once it has expired', function (): void {
    $this->postJson('/notes', ['body' => 'mine']);

    $sandbox = Sandbox::query()->sole();
    $sandbox->forceFill(['expires_at' => CarbonImmutable::now()->subMinute()])->save();

    $this->getJson('/notes')->assertJson(['notes' => ['A seeded note']]);
});

it('keeps a sandbox alive while its visitor is still using it', function (): void {
    CarbonImmutable::setTestNow('2026-09-23 10:00:00');

    $this->postJson('/notes', ['body' => 'mine']);
    $first = Sandbox::query()->sole()->expires_at;

    /* Past half the hour-long TTL, so the renewal is worth a write. */
    CarbonImmutable::setTestNow('2026-09-23 10:40:00');

    $this->getJson('/notes');

    expect(Sandbox::query()->sole()->expires_at->greaterThan($first))->toBeTrue();
});

/**
 * Every request from every anonymous visitor used to issue an UPDATE against this
 * table — an unthrottled write path on a public surface that one visitor with a
 * loop can turn into as much database load as they like.
 */
it('does not write on every request just to say the visitor is still here', function (): void {
    CarbonImmutable::setTestNow('2026-09-23 10:00:00');

    $this->postJson('/notes', ['body' => 'mine']);
    $first = Sandbox::query()->sole()->expires_at;

    CarbonImmutable::setTestNow('2026-09-23 10:05:00');

    $this->getJson('/notes');

    expect(Sandbox::query()->sole()->expires_at->equalTo($first))->toBeTrue();
});

it('announces a new sandbox', function (): void {
    Event::fake([SandboxCreated::class]);

    $this->postJson('/notes', ['body' => 'mine']);

    Event::assertDispatched(SandboxCreated::class);
});

describe('the shared driver', function (): void {
    it('scopes nothing at all', function (): void {
        demo(['demo.sandbox.driver' => 'shared']);

        expect(app(Manager::class)->scoped())->toBeFalse()
            ->and(app(Manager::class)->current())->toBeNull();
    });
});

describe('pruning', function (): void {
    it('removes the expired and keeps the rest', function (): void {
        Sandbox::query()->create(['id' => 'gone', 'expires_at' => CarbonImmutable::now()->subMinute()]);
        Sandbox::query()->create(['id' => 'here', 'expires_at' => CarbonImmutable::now()->addHour()]);

        $this->artisan('demo:sandbox:prune')->assertSuccessful();

        expect(Sandbox::query()->pluck('id')->all())->toBe(['here']);
    });

    it('announces what it removed, for anything kept outside the database', function (): void {
        Event::fake([SandboxExpired::class]);

        Sandbox::query()->create(['id' => 'gone', 'expires_at' => CarbonImmutable::now()->subMinute()]);

        $this->artisan('demo:sandbox:prune')->assertSuccessful();

        Event::assertDispatched(SandboxExpired::class, fn (SandboxExpired $e): bool => $e->id === 'gone');
    });

    it('does nothing on a demo that shares its data', function (): void {
        demo(['demo.sandbox.driver' => 'shared']);

        $this->artisan('demo:sandbox:prune')
            ->expectsOutputToContain('shares its data')
            ->assertSuccessful();
    });
});

/**
 * The code that genuinely has to see everything says so by name.
 */
it('can be stepped outside deliberately', function (): void {
    $this->postJson('/notes', ['body' => 'mine']);

    $this->flushSession();
    $this->postJson('/notes', ['body' => 'theirs']);

    expect(SandboxedNote::withoutSandbox()->pluck('body')->all())
        ->toBe(['A seeded note', 'mine', 'theirs']);
});

/**
 * The write side of "the identifier is not a claim".
 *
 * Demo models are usually written with $guarded = [], so a visitor posting
 * demo_sandbox_id alongside the rest of a form could plant a row in somebody
 * else's sandbox. Whatever they send is overwritten with theirs.
 */
it('will not let a visitor plant a row in another sandbox', function (): void {
    Route::middleware([EncryptCookies::class, AddQueuedCookiesToResponse::class, StartSession::class, 'demo.sandbox'])
        ->post('/plant', function (): array {
            SandboxedNote::create(request()->only('body', 'demo_sandbox_id'));

            return ['ok' => true];
        });

    $this->postJson('/notes', ['body' => 'mine']);
    $victim = Sandbox::query()->sole()->id;

    $this->flushSession();
    $this->postJson('/plant', ['body' => 'planted', 'demo_sandbox_id' => $victim]);

    expect(DB::table('demo_notes')->where('body', 'planted')->value('demo_sandbox_id'))->not->toBe($victim);

    /* And the victim never sees it. */
    $this->flushSession();
    $this->withSession(['demo_sandbox' => $victim]);

    $this->getJson('/notes')->assertJson(['notes' => ['A seeded note', 'mine']]);
});

/**
 * The other direction: publishing a row to everybody by sending a null id.
 */
it('will not let a visitor publish a row to everybody', function (): void {
    Route::middleware([EncryptCookies::class, AddQueuedCookiesToResponse::class, StartSession::class, 'demo.sandbox'])
        ->post('/plant', function (): array {
            SandboxedNote::create(request()->only('body', 'demo_sandbox_id'));

            return ['ok' => true];
        });

    $this->postJson('/plant', ['body' => 'everybodys', 'demo_sandbox_id' => null]);

    expect(DB::table('demo_notes')->where('body', 'everybodys')->value('demo_sandbox_id'))->not->toBeNull();

    $this->flushSession();
    $this->getJson('/notes')->assertJson(['notes' => ['A seeded note']]);
});

/**
 * And the seeder still gets to make the shared baseline, because it has no
 * session and therefore no sandbox to attribute rows to.
 */
it('lets a seeder create rows that belong to everybody', function (): void {
    SandboxedNote::create(['body' => 'seeded later']);

    expect(DB::table('demo_notes')->where('body', 'seeded later')->value('demo_sandbox_id'))->toBeNull();
});

/**
 * Signing in regenerates the session id. Laravel's migrate() keeps the session
 * data and only changes the id, so the visitor should keep their corner — and the
 * Manager's memo, which is keyed on the session id, has to notice the change
 * rather than serve a stale answer.
 */
it('keeps a visitor their sandbox across a session regeneration', function (): void {
    Route::middleware([EncryptCookies::class, AddQueuedCookiesToResponse::class, StartSession::class, 'demo.sandbox'])
        ->post('/sign-in', function (): array {
            $before = SandboxedNote::pluck('body')->all();

            session()->regenerate();

            return ['before' => $before, 'after' => SandboxedNote::pluck('body')->all()];
        });

    $this->postJson('/notes', ['body' => 'mine']);

    $this->postJson('/sign-in')->assertJson([
        'before' => ['A seeded note', 'mine'],
        'after' => ['A seeded note', 'mine'],
    ]);

    /* And still theirs on the next request. */
    $this->getJson('/notes')->assertJson(['notes' => ['A seeded note', 'mine']]);

    expect(Sandbox::query()->count())->toBe(1);
});

/**
 * The failure mode that mattered most, because it failed in the wrong direction.
 *
 * A transient database error during resolution used to be swallowed: current()
 * answered null, the scope added no constraint at all, and for that request every
 * visitor's rows were visible to whoever triggered it. Under the concurrent
 * anonymous load a public demo attracts, that is not hypothetical.
 *
 * A failed request is the correct outcome. Showing everybody everything is not.
 */
it('fails the request rather than unscoping when the database errors', function (): void {
    /* A visitor with a sandbox, so the lookup actually runs. */
    $this->postJson('/notes', ['body' => 'mine']);

    /* The table is there; the query against it is what breaks. */
    DB::statement('drop table demo_sandboxes');
    DB::statement('create table demo_sandboxes (wrong_column integer)');

    /* A failed request, not a request that quietly showed everything. */
    $this->getJson('/notes')->assertStatus(500);
});

/**
 * And the other half of the same decision: a table that was never migrated is a
 * feature that is not set up, not a transient error. Unscoped is the honest
 * description, and demo:doctor reports it.
 */
it('serves unscoped when the feature was configured but never migrated', function (): void {
    $this->postJson('/notes', ['body' => 'mine']);

    DB::statement('drop table demo_sandboxes');

    $this->flushSession();

    /* Unscoped, so this visitor sees the other one's note. demo:doctor errors. */
    $this->getJson('/notes')->assertJson(['notes' => ['A seeded note', 'mine']]);
});
