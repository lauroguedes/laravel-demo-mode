<?php

declare(strict_types=1);

namespace LauroGuedes\DemoMode\Sandbox;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Contracts\Session\Session;
use Illuminate\Support\Str;
use LauroGuedes\DemoMode\Configuration;
use LauroGuedes\DemoMode\Events\SandboxCreated;
use Throwable;

/**
 * Whose corner of the demonstration this request is in.
 *
 * The identity question, and the one place it is answered. Everything about how
 * it is answered is a security decision, so each is worth stating:
 *
 * The identifier lives in the **session**, not in a cookie of its own. Laravel's
 * session cookie is already signed and encrypted, so reusing it means no new
 * trust boundary and nothing new to get wrong. A bare cookie would be a value a
 * visitor edits.
 *
 * Even so, the identifier is treated as **untrusted**. It is a lookup key: the
 * row has to exist and not have expired, or a fresh empty sandbox is minted. So
 * the worst a forged, guessed or replayed identifier achieves is a new sandbox of
 * one's own — never somebody else's rows. That is what stops the global scope
 * being an access-control decision made from user input.
 *
 * A sandbox exists only where there is a visitor, and the test for that is a
 * request with a session — not runningInConsole(), which is a proxy for it and a
 * worse one: it is true under PHPUnit, so it made the feature unresolvable in
 * every test that drove it over HTTP. A queued job and an Artisan command have no
 * session either, so they still get null and a seeder's writes still land
 * unscoped, which is what they should do.
 */
final class Manager
{
    private ?Sandbox $resolved = null;

    /**
     * The session the memo was resolved from, so it cannot outlive it.
     *
     * Keyed rather than simply remembered, because a singleton lives longer than
     * a request in more than one place: Octane keeps the container between
     * requests, and so does a test making two of them. Memoising unconditionally
     * meant the second visitor was handed the first one's sandbox — which, in a
     * feature whose whole job is keeping them apart, is the only bug that matters.
     */
    private ?string $resolvedFor = null;

    public function __construct(
        private readonly Configuration $config,
        private readonly Application $app,
        private readonly Dispatcher $events,
    ) {}

    /**
     * Whether visitors are isolated from each other at all.
     */
    public function scoped(): bool
    {
        return $this->config->scoped();
    }

    /**
     * This request's sandbox, if it already has one.
     *
     * Reading never creates. That is the difference between a feature and an
     * unbounded write path: the scope asks this on every query against a marked
     * model, so minting a row here meant one INSERT for every request that
     * arrived without a cookie — which a visitor, or a crawler, can produce as
     * fast as they like. The package is careful to put three limits in front of
     * the reset route; creating rows on anonymous reads would have been the same
     * exposure with none.
     *
     * Null when there is nobody to have one: a demo that shares its data,
     * anything without a session, or a visitor who has not written anything yet.
     * What callers do with null differs, and the difference matters — see
     * SandboxScope.
     */
    public function current(): ?Sandbox
    {
        if (! $this->scoped()) {
            return null;
        }

        $session = $this->session();

        if (! $session instanceof Session) {
            return null;
        }

        if ($this->resolvedFor === $session->getId()) {
            return $this->resolved;
        }

        $this->resolvedFor = $session->getId();

        return $this->resolved = $this->fromSession($session);
    }

    /**
     * This request's sandbox, making one if it does not have it yet.
     *
     * Called from exactly one place: the moment a visitor first writes something
     * that has to belong to them. A row in this table then means "somebody
     * created something", which is also what makes pruning meaningful.
     */
    public function currentOrCreate(): ?Sandbox
    {
        $existing = $this->current();

        if ($existing instanceof Sandbox) {
            return $existing;
        }

        $session = $this->session();

        if (! $this->scoped() || ! $session instanceof Session) {
            return null;
        }

        $this->resolvedFor = $session->getId();

        return $this->resolved = $this->create($session, $this->config->string('sandbox.key', 'demo_sandbox'));
    }

    /**
     * Whether a sandbox would be resolvable here at all.
     *
     * The distinction the scope needs: a request with a session on a scoped demo
     * is a visitor who should see only the baseline until they create something,
     * while a console command or an API route has no visitor and should see
     * everything.
     */
    public function applies(): bool
    {
        return $this->scoped() && $this->session() instanceof Session && ! $this->tableIsMissing();
    }

    /**
     * Push the expiry out, for a visitor who is still here.
     *
     * Called by the middleware on every request, so a sandbox lives for as long
     * as somebody keeps using it and is pruned once they stop.
     */
    public function touch(): void
    {
        $sandbox = $this->current();

        if (! $sandbox instanceof Sandbox || ! $this->worthTouching($sandbox)) {
            return;
        }

        $sandbox->forceFill(['expires_at' => $this->expiry()])->save();
    }

    /**
     * Whether the expiry has moved enough to be worth a write.
     *
     * Without this, every request from every anonymous visitor issued an UPDATE
     * against this table — an unthrottled write path on a public surface, which
     * one visitor with a loop can turn into as much database load as they like.
     * Half the TTL is late enough to be cheap and early enough that a sandbox in
     * use is never pruned.
     */
    private function worthTouching(Sandbox $sandbox): bool
    {
        $ttl = $this->config->integer('sandbox.ttl', 3600);

        if ($ttl <= 0 || $sandbox->expires_at === null) {
            return false;
        }

        /*
         * Signed, and measured as time remaining rather than time elapsed. The
         * first version of this compared an absolute difference, which renewed
         * while there was most of the TTL left and stopped renewing as expiry
         * approached — precisely backwards, and a sandbox in active use would
         * have been pruned out from under its visitor.
         */
        $remaining = CarbonImmutable::now()->diffInSeconds($sandbox->expires_at, absolute: false);

        return $remaining < $ttl / 2;
    }

    private function fromSession(Session $session): ?Sandbox
    {
        $id = $session->get($this->config->string('sandbox.key', 'demo_sandbox'));

        try {
            /*
             * The identifier is a lookup key and nothing else. An unknown or
             * expired one falls through to a new sandbox rather than selecting
             * anything, which is what keeps a forged value worthless.
             */
            $existing = is_string($id) && $id !== ''
                ? Sandbox::query()->whereKey($id)->first()
                : null;

            return $existing instanceof Sandbox && ! $existing->expired() ? $existing : null;
        } catch (Throwable $e) {
            /*
             * Two very different failures arrive here and they must not be
             * treated alike.
             *
             * The table not existing means the feature was configured but never
             * migrated. Answering null leaves the demo unscoped, which is the
             * honest description of a feature that is not set up, and
             * demo:doctor reports it as an error.
             *
             * Anything else — a timeout, a deadlock, a connection the pool could
             * not give out — is transient, and swallowing it was a fail-open:
             * current() returned null, the scope added no constraint at all, and
             * for that request every visitor's rows were visible to whoever
             * triggered it. Under the concurrent anonymous load a public demo
             * attracts, that is not a hypothetical. It rethrows now. A failed
             * request is the correct outcome; showing everybody everything is not.
             */
            if ($this->tableIsMissing()) {
                return null;
            }

            throw $e;
        }
    }

    private function create(Session $session, string $key): Sandbox
    {
        $sandbox = new Sandbox;

        $sandbox->forceFill([
            /*
             * A ULID rather than an incrementing id: nothing should be able to
             * enumerate other sandboxes by counting, even though holding an
             * identifier buys nothing on its own.
             */
            'id' => (string) Str::ulid(),
            'expires_at' => $this->expiry(),
        ])->save();

        $session->put($key, $sandbox->id);

        $this->events->dispatch(new SandboxCreated($sandbox->id));

        return $sandbox;
    }

    /**
     * Whether the sandboxes table is simply not there.
     *
     * Asked only on the error path, so the common case pays nothing for it.
     */
    private function tableIsMissing(): bool
    {
        try {
            return ! $this->app->make('db')->connection()
                ->getSchemaBuilder()
                ->hasTable((new Sandbox)->getTable());
        } catch (Throwable) {
            /*
             * If even this cannot be answered the database is unreachable, which
             * is the transient case, not the unconfigured one.
             */
            return false;
        }
    }

    private function expiry(): ?CarbonImmutable
    {
        $ttl = $this->config->integer('sandbox.ttl', 3600);

        return $ttl > 0 ? CarbonImmutable::now()->addSeconds($ttl) : null;
    }

    private function session(): ?Session
    {
        try {
            $request = $this->app->make('request');

            return $request->hasSession() ? $request->session() : null;
        } catch (Throwable) {
            return null;
        }
    }
}
