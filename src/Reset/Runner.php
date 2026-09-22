<?php

declare(strict_types=1);

namespace LauroGuedes\DemoMode\Reset;

use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Contracts\Cache\Factory as CacheFactory;
use Illuminate\Contracts\Cache\Lock;
use Illuminate\Contracts\Cache\LockProvider;
use Illuminate\Contracts\Console\Kernel as Artisan;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Contracts\Foundation\MaintenanceMode;
use LauroGuedes\DemoMode\Configuration;
use LauroGuedes\DemoMode\Contracts\ResetStrategy;
use LauroGuedes\DemoMode\Credentials\Manager as Credentials;
use LauroGuedes\DemoMode\Events\ResetCompleted;
use LauroGuedes\DemoMode\Events\ResetFailed;
use LauroGuedes\DemoMode\Events\ResetStarting;
use LauroGuedes\DemoMode\Exceptions\ResetInProgress;
use LauroGuedes\DemoMode\Exceptions\ResetRefused;
use LauroGuedes\DemoMode\Support\CacheKeys;
use LauroGuedes\DemoMode\Support\DestructiveCommands;
use Throwable;

/**
 * Runs one reset, in an order chosen so that every failure leaves the demo
 * serving.
 *
 * The sequence:
 *
 *   1. every guard passes, or nothing happens at all
 *   2. the lock is acquired, or another reset already holds it
 *   3. ResetStarting — the last moment the old data exists
 *   4. maintenance mode on
 *   5. the strategy runs, and only the strategy runs, with destructive
 *      Artisan commands temporarily permitted
 *   6. the cleaners run, with the prohibition already back on
 *   7. credentials rotate, after the cleaners rather than before
 *   8. maintenance mode off, always, including on the way out of a throw
 *
 * Step 5's window is narrow on purpose. DB::prohibitDestructiveCommands() sets a
 * static flag on five command classes — it is process-global with no per-call
 * scope — so a window opened around the whole run would leave migrate:fresh
 * available to anything else executing in the process while cleaners and
 * credential writes happen. Wrapping the strategy call alone, and restoring in a
 * finally, keeps the opening to the one step that needs it.
 *
 * That also answers the thing the scheduled run makes tempting: because the
 * scheduler uses runInBackground(), the reset is a separate process that boots
 * the application fresh and inherits nothing. It has to lower the prohibition
 * itself. Which means an application never has to disable ProhibitDestructive-
 * Commands globally to have a demo — the prohibition stays on permanently and
 * the only thing that ever lowers it is this method, for one call.
 *
 * Step 7's order matters for a reason that is silent when you get it wrong: the
 * cache credential store writes into the cache, and the cache cleaner empties it.
 * Rotating first would publish a password the next step erases, and the login
 * page would show credentials that do not work with nothing in any log to say so.
 */
final readonly class Runner
{
    public function __construct(
        private Application $app,
        private Configuration $config,
        private GuardChain $guards,
        private StrategyFactory $strategies,
        private CleanerPipeline $cleaners,
        private Credentials $credentials,
        private CacheFactory $cache,
        private MaintenanceMode $maintenance,
        private Artisan $artisan,
        private Dispatcher $events,
    ) {}

    /**
     * @param  array{strategy?: string, seeder?: string, maintenance?: bool, dry-run?: bool}  $options
     * @param  (Closure(string): void)|null  $output
     */
    public function run(array $options = [], ?Closure $output = null): ResetReport
    {
        $write = $output ?? static fn (string $message): null => null;

        $this->guards->enforce();

        $strategy = $this->strategies->make($options['strategy'] ?? null, $options);

        /*
         * Asked here rather than only by demo:doctor. Every "refuses before
         * anything is dropped" promise in a strategy is worth exactly what the
         * destructive path does about it, and an advisory command somebody may
         * or may not have run is not a barrier. --force does not skip this, for
         * the same reason it does not skip the guards.
         */
        $problems = $strategy->validate();

        if ($problems !== []) {
            throw new ResetRefused($problems);
        }

        if (($options['dry-run'] ?? false) === true) {
            return $this->plan($strategy);
        }

        $lock = $this->lock();

        if ($lock instanceof Lock && ! $lock->get()) {
            throw ResetInProgress::make();
        }

        try {
            return $this->execute($strategy, $options, $write);
        } finally {
            $lock?->release();
        }
    }

    /**
     * The lock that stops two resets running at once.
     *
     * Not bypassable by --force: two concurrent migrate:fresh runs against one
     * database is how a demo ends up with half a schema and no way to tell which
     * half. A store with no lock support (the array store, in tests) returns
     * null, and the caller treats that as "nothing to contend with" rather than
     * as a refusal.
     */
    private function lock(): ?Lock
    {
        $store = $this->cache->store()->getStore();

        if (! $store instanceof LockProvider) {
            return null;
        }

        return $store->lock(CacheKeys::LOCK, $this->config->integer('reset.lock_ttl', 1800));
    }

    /**
     * @param  array<string, mixed>  $options
     * @param  Closure(string): void  $output
     */
    private function execute(ResetStrategy $strategy, array $options, Closure $output): ResetReport
    {
        $startedAt = CarbonImmutable::now();
        $steps = [];
        $rotated = [];
        $wasDown = $this->maintenance->active();
        $maintain = (bool) ($options['maintenance'] ?? $this->config->boolean('reset.maintenance', true));

        $this->events->dispatch(new ResetStarting($strategy->describe()));

        try {
            if ($maintain && ! $wasDown) {
                $this->maintenance->activate(['retry' => 60]);
                $steps[] = 'Entered maintenance mode';
            }

            /*
             * Generated before the strategy so the seeder can hash the password
             * that is about to be published, and written after the cleaners so
             * the cache store is not emptied on top of it. See Manager::stage().
             */
            $rotated = $this->credentials->stage();

            $output('Rebuilding the demonstration data');

            DestructiveCommands::permitting(fn () => $strategy->run($this->context($options, $output)));

            $steps[] = $strategy->describe();

            $cleaned = $this->cleaners->run($output);
            $steps = [...$steps, ...$cleaned];

            $this->credentials->publish();

            if ($rotated !== []) {
                $steps[] = sprintf('Rotated %d published password(s)', count($rotated));
            }
        } catch (Throwable $e) {
            $this->restore($maintain, $wasDown);

            $this->events->dispatch(new ResetFailed($e, $strategy->describe()));

            throw $e;
        }

        $this->restore($maintain, $wasDown);

        $this->recordCompletion();

        $report = new ResetReport(
            strategy: $strategy->describe(),
            steps: $steps,
            cleaners: $this->cleaners->descriptions(),
            credentialsRotated: count($rotated),
            startedAt: $startedAt,
            finishedAt: CarbonImmutable::now(),
        );

        $this->events->dispatch(new ResetCompleted($report));

        return $report;
    }

    /**
     * Bringing the application back up is the one step that must not be skipped,
     * so it never throws. A demo left in maintenance mode because the thing that
     * lifts it failed is worse than whatever went wrong before it.
     */
    private function restore(bool $maintain, bool $wasAlreadyDown): void
    {
        if (! $maintain || $wasAlreadyDown) {
            return;
        }

        try {
            $this->maintenance->deactivate();
        } catch (Throwable) {
            //
        }
    }

    /**
     * @param  array<string, mixed>  $options
     * @param  Closure(string): void  $output
     */
    private function context(array $options, Closure $output): ResetContext
    {
        return new ResetContext(
            app: $this->app,
            artisan: $this->artisan,
            connection: $this->config->nullableString('reset.connection'),
            options: $options,
            output: $output,
        );
    }

    /**
     * What would happen, without anything happening.
     */
    private function plan(ResetStrategy $strategy): ResetReport
    {
        $now = CarbonImmutable::now();
        $cleaners = $this->cleaners->descriptions();

        return new ResetReport(
            strategy: $strategy->describe(),
            steps: [
                $this->config->boolean('reset.maintenance', true) ? 'Enter maintenance mode' : 'Stay online',
                $strategy->describe(),
                ...$cleaners,
                $this->credentials->publishes() ? 'Rotate the published password(s)' : 'Publish nothing',
                'Leave maintenance mode',
            ],
            cleaners: $cleaners,
            credentialsRotated: 0,
            startedAt: $now,
            finishedAt: $now,
            dryRun: true,
        );
    }

    /**
     * Written to the cache rather than a table, because the table was just
     * dropped. FlushCache's default 'except' keeps the key.
     */
    private function recordCompletion(): void
    {
        try {
            $this->cache->store()->forever(CacheKeys::LAST_RESET, CarbonImmutable::now()->toIso8601String());
        } catch (Throwable) {
            //
        }
    }
}
