<?php

declare(strict_types=1);

namespace LauroGuedes\DemoMode;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Carbon\CarbonInterval;
use Closure;
use Illuminate\Contracts\Cache\Factory as CacheFactory;
use Illuminate\Contracts\Container\Container;
use LauroGuedes\DemoMode\Credentials\Credential;
use LauroGuedes\DemoMode\Credentials\Manager as Credentials;
use LauroGuedes\DemoMode\Reset\ResetReport;
use LauroGuedes\DemoMode\Reset\Runner;
use LauroGuedes\DemoMode\Reset\Schedule;
use LauroGuedes\DemoMode\Support\CacheKeys;
use LauroGuedes\DemoMode\Support\Options;
use LauroGuedes\DemoMode\View\BannerState;
use Throwable;

/**
 * What a public demonstration does differently, in one place.
 *
 * The single point of truth the specification asks for. Both of the hand-rolled
 * implementations this package was extracted from failed in the same way, to
 * different degrees: config('app.demo.enabled') read directly in seven files, so
 * that changing what "is this a demo" means required finding all seven, and
 * getting one wrong was invisible until a visitor found it.
 *
 * Everything an application needs is reachable from here, through the Demo facade.
 * Nothing else in the package — and nothing in an application — should be reading
 * the config key.
 */
class DemoMode
{
    private ?Schedule $schedule = null;

    private bool $scheduleResolved = false;

    /**
     * The cache factory is resolved on use rather than injected, because this is
     * a singleton that view components and middleware construct on every request
     * of every installation. Only lastResetAt() needs it, and building the cache
     * manager to answer "is this a demo" was work every non-demo page paid.
     */
    public function __construct(
        private readonly Configuration $config,
        private readonly Container $container,
    ) {}

    /**
     * Whether this installation declared itself a public demonstration.
     */
    public function enabled(): bool
    {
        return $this->config->enabled();
    }

    public function disabled(): bool
    {
        return ! $this->enabled();
    }

    /**
     * Run something only on a demo.
     *
     * Exists to replace the scattered 'if (config(...))', which is how the
     * single point of truth stops being single. Reads better at the call site
     * too: Demo::when(fn () => $user->markEmailAsVerified()) says what it is for.
     */
    public function when(Closure $callback): mixed
    {
        return $this->enabled() ? $callback() : null;
    }

    public function unless(Closure $callback): mixed
    {
        return $this->disabled() ? $callback() : null;
    }

    /**
     * When the scheduler will next rebuild the data.
     *
     * Derived from the same cron expression the scheduler registers, which is
     * what stops the banner saying "resets every 24 hours" on a demo that resets
     * hourly — a real bug in one of the projects this came from, where the
     * message was hardcoded and the schedule was configurable.
     */
    public function nextResetAt(): ?CarbonImmutable
    {
        return $this->schedule()?->nextRunAt();
    }

    /**
     * When it last happened, as recorded by the Runner.
     *
     * Null on a demo that has not reset yet, which is a different answer from
     * "the schedule says it should have" and is worth keeping distinct: a demo
     * whose scheduler is not running should not claim it reset an hour ago.
     */
    public function lastResetAt(): ?CarbonImmutable
    {
        if ($this->disabled()) {
            return null;
        }

        try {
            $recorded = $this->container->make(CacheFactory::class)->store()->get(CacheKeys::LAST_RESET);
        } catch (Throwable) {
            return null;
        }

        if (! is_string($recorded)) {
            return null;
        }

        try {
            return CarbonImmutable::parse($recorded);
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * How long until the next reset, in words.
     *
     * One owner for an expression that was written out in three places.
     */
    public function resetsIn(): ?string
    {
        return $this->nextResetAt()?->diffForHumans(
            CarbonImmutable::now(),
            syntax: CarbonInterface::DIFF_ABSOLUTE,
        );
    }

    public function timeUntilReset(): ?CarbonInterval
    {
        $next = $this->nextResetAt();

        return $next?->diffAsCarbonInterval(CarbonImmutable::now(), absolute: true);
    }

    /**
     * Rebuild the demonstration data now.
     *
     * The same path the command and the scheduler take, guards included. There is
     * no version of this that skips them.
     *
     * @param  array{strategy?: string, seeder?: string, maintenance?: bool, dry-run?: bool}  $options
     */
    public function reset(array $options = [], ?Closure $output = null): ResetReport
    {
        return $this->container->make(Runner::class)->run($options, $output);
    }

    /**
     * What a login form should prefill, if anything.
     *
     * @return array{email: string, password: string, label: string|null}|null
     */
    public function credentials(): ?array
    {
        $primary = $this->credentialManager()->primary();

        return $primary instanceof Credential
            ? ['email' => $primary->email, 'password' => $primary->password, 'label' => $primary->label]
            : null;
    }

    /**
     * The published password for one address, for a seeder to hash.
     *
     * Null when this is not a demo, when nothing has been published yet, or when
     * that address is not one of the published accounts — so a seeder writes
     *
     *     bcrypt(Demo::passwordFor($email) ?? 'password')
     *
     * and keeps working when it is run on its own. That matters more than it
     * looks: the first thing anybody does after writing a seeder is run
     * 'db:seed', long before the first reset exists to stage anything, and a
     * seeder that only works inside a reset is a seeder that fails the first
     * time it is used.
     */
    public function passwordFor(string $email): ?string
    {
        return $this->credentialManager()->passwordFor($email);
    }

    /**
     * Every published account.
     *
     * @return list<array{email: string, password: string, label: string|null, primary: bool}>
     */
    public function allCredentials(): array
    {
        return Credential::toArrays($this->credentialManager()->all());
    }

    /**
     * @return list<array{email: string, password: string, label: string|null, primary: bool}>
     */
    public function rotate(): array
    {
        return Credential::toArrays($this->credentialManager()->rotate());
    }

    /**
     * The whole demo state, for an Inertia share or a Livewire component.
     *
     * One payload for every front end, which is what "front-end agnostic" comes
     * down to in practice: Blade, Livewire and Inertia all read this array and
     * none of them needs the package to know which one it is talking to.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        if ($this->disabled()) {
            return ['enabled' => false];
        }

        $exposes = $this->exposesCredentials();

        return [
            'enabled' => true,
            'next_reset_at' => $this->nextResetAt()?->toIso8601String(),
            'resets_in' => $this->resetsIn(),
            'last_reset_at' => $this->lastResetAt()?->toIso8601String(),
            'credentials' => $exposes ? $this->credentials() : null,
            /*
             * Every published account, not only the one a form prefills. The
             * Blade component lists them all, and a payload that carried one was
             * the Inertia path being quietly poorer than the Blade path rather
             * than differently shaped.
             */
            'accounts' => $exposes ? $this->allCredentials() : [],
            'banner' => $this->banner()?->toArray(),
            'scripted' => $this->scripted(),
        ];
    }

    /**
     * The banner, resolved once for whichever front end is asking.
     *
     * Null when there is nothing to show. Both the Blade component and the
     * shared payload read this rather than the banner.* keys, which is what
     * stops them disagreeing about a default.
     */
    public function banner(): ?BannerState
    {
        if ($this->disabled() || ! $this->config->boolean('banner.enabled', true)) {
            return null;
        }

        $variant = $this->config->string('banner.variant', 'warning');
        $classes = $this->config->array('banner.classes');

        return new BannerState(
            variant: $variant,
            class: Options::string($classes[$variant] ?? $classes['default'] ?? null, '') ?: null,
            dismissible: $this->config->boolean('banner.dismissible', true) && $this->scripted(),
            position: $this->config->string('banner.position', 'top'),
            message: $this->config->nullableString('banner.message'),
            nextResetAt: $this->nextResetAt(),
            resetsIn: $this->resetsIn(),
        );
    }

    /**
     * @return array<string, mixed>|null
     */
    public function toBanner(): ?array
    {
        return $this->banner()?->toArray();
    }

    /**
     * Whether the package's one small script will be on the page.
     *
     * Read by anything that renders a control the script operates. A dismiss
     * button or a copy button with no script behind it is a control that lies,
     * so both fold this in rather than rendering regardless.
     */
    public function scripted(): bool
    {
        return $this->enabled() && $this->config->boolean('script', true);
    }

    /**
     * The parsed reset schedule, or null when it cannot be read.
     *
     * Swallows the parse error rather than throwing, because the callers are a
     * banner and a status line. A demo whose schedule is unreadable should render
     * without a countdown, not return a 500 to every visitor; 'demo:doctor' is
     * where the misconfiguration is meant to surface.
     */
    public function schedule(): ?Schedule
    {
        if ($this->disabled()) {
            return null;
        }

        /*
         * Memoised because one toArray() would otherwise parse the cron three
         * times — once for the payload, once for the banner, once for the
         * countdown inside it. The expression is config-derived and immutable,
         * so the parse is safe to keep; the computed date is not, and is
         * deliberately recomputed on every call so it cannot go stale under
         * Octane.
         */
        if (! $this->scheduleResolved) {
            $this->scheduleResolved = true;

            try {
                $this->schedule = Schedule::parse($this->config->string('reset.schedule', '0 */6 * * *'));
            } catch (Throwable) {
                $this->schedule = null;
            }
        }

        return $this->schedule;
    }

    private function exposesCredentials(): bool
    {
        return $this->config->boolean('credentials.expose_in_payload', true);
    }

    private function credentialManager(): Credentials
    {
        return $this->container->make(Credentials::class);
    }
}
