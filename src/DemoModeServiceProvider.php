<?php

declare(strict_types=1);

namespace LauroGuedes\DemoMode;

use Illuminate\Console\Scheduling\Schedule as Scheduler;
use Illuminate\Contracts\Cache\Factory as CacheFactory;
use Illuminate\Contracts\Container\Container;
use Illuminate\Support\ServiceProvider;
use Illuminate\View\Compilers\BladeCompiler;
use LauroGuedes\DemoMode\Console\CredentialsCommand;
use LauroGuedes\DemoMode\Console\DoctorCommand;
use LauroGuedes\DemoMode\Console\InstallCommand;
use LauroGuedes\DemoMode\Console\ResetCommand;
use LauroGuedes\DemoMode\Console\StatusCommand;
use LauroGuedes\DemoMode\Contracts\CredentialStore;
use LauroGuedes\DemoMode\Credentials\Manager as Credentials;
use LauroGuedes\DemoMode\Credentials\StoreFactory;
use LauroGuedes\DemoMode\Reset\GuardChain;
use LauroGuedes\DemoMode\Reset\Guards\DemoModeIsEnabled;
use LauroGuedes\DemoMode\Reset\Guards\EnvironmentIsAllowed;
use LauroGuedes\DemoMode\Reset\Guards\HostIsAllowed;
use LauroGuedes\DemoMode\Reset\Guards\NotProduction;
use LauroGuedes\DemoMode\Restrictions\Pipeline as Restrictions;

/**
 * Registers the package, and mostly does not.
 *
 * The shape here is the "zero cost when disabled" principle made literal. On an
 * installation that is not a demo, boot() registers no middleware, no listener,
 * no route, no schedule and — deliberately — not the reset command either. That
 * last one is defence rather than economy: on a server that never said it was a
 * demo, 'php artisan demo:reset' should not be a command that exists, let alone
 * one that exists and argues.
 *
 * "Zero cost" is meant literally enough to be worth two specific notes, because
 * both are easy to breach by writing the obvious thing:
 *
 * The gate asks Configuration rather than DemoMode. Resolving DemoMode would
 * instantiate the cache manager to answer a single boolean, on every request of
 * every application that has this package installed and switched off.
 *
 * The Blade conditional is registered through callAfterResolving rather than the
 * facade. Blade::if() resolves the compiler eagerly, which builds a BladeCompiler
 * — and reads three view config keys — on every request, including in an API-only
 * application that never renders a view.
 *
 * What is always available is the part that cannot hurt anything: the facade, the
 * Blade conditionals (@notdemo has to work precisely when the flag is off), and
 * the four commands for setting a demo up and inspecting it. A doctor that only
 * ran on a correctly configured demo would be useless for finding out why a demo
 * is not correctly configured.
 */
class DemoModeServiceProvider extends ServiceProvider
{
    /** @var list<class-string> */
    private const array SETUP_COMMANDS = [
        InstallCommand::class,
        StatusCommand::class,
        DoctorCommand::class,
        CredentialsCommand::class,
    ];

    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/demo.php', 'demo');

        $this->app->singleton(Configuration::class);

        $this->app->singleton(DemoMode::class, static fn (Container $app): DemoMode => new DemoMode(
            $app->make(Configuration::class),
            $app,
            $app->make(CacheFactory::class),
        ));

        $this->app->singleton(CredentialStore::class, static fn (Container $app): CredentialStore => $app
            ->make(StoreFactory::class)
            ->make());

        $this->app->singleton(Credentials::class);

        $this->app->bind(GuardChain::class, static fn (Container $app): GuardChain => new GuardChain([
            $app->make(DemoModeIsEnabled::class),
            $app->make(EnvironmentIsAllowed::class),
            $app->make(NotProduction::class),
            $app->make(HostIsAllowed::class),
        ]));
    }

    public function boot(): void
    {
        $this->registerPublishing();
        $this->registerBladeConditionals();
        $this->registerCommands(self::SETUP_COMMANDS);

        if (! $this->app->make(Configuration::class)->enabled()) {
            return;
        }

        $this->app->make(Restrictions::class)->apply();

        $this->registerCommands([ResetCommand::class]);
        $this->registerSchedule();
    }

    /**
     * Publishing is always available: you publish the config in order to turn
     * the demo on, so gating it behind the demo being on would be a loop.
     */
    private function registerPublishing(): void
    {
        $this->loadTranslationsFrom(__DIR__.'/../resources/lang', 'demo');
        $this->loadViewsFrom(__DIR__.'/../resources/views', 'demo');

        if (! $this->app->runningInConsole()) {
            return;
        }

        $this->publishes([
            __DIR__.'/../config/demo.php' => $this->app->configPath('demo.php'),
        ], 'demo-config');

        $this->publishes([
            __DIR__.'/../resources/views' => $this->app->resourcePath('views/vendor/demo'),
        ], 'demo-views');

        $this->publishes([
            __DIR__.'/../resources/lang' => $this->app->langPath('vendor/demo'),
        ], 'demo-translations');
    }

    /**
     * Registered whether or not this is a demo, because @notdemo is how an
     * application hides the demo's affordances everywhere else, and a directive
     * that is not registered is a compile error rather than a false condition.
     *
     * Blade::if() would give @demo, @unlessdemo and @enddemo. @notdemo is
     * registered explicitly alongside them because it is the one that reads as
     * English at a call site, and a pair that only half exists is worse than
     * either.
     */
    private function registerBladeConditionals(): void
    {
        $this->callAfterResolving('blade.compiler', static function (BladeCompiler $blade): void {
            $blade->if('demo', static fn (): bool => app(DemoMode::class)->enabled());

            $blade->directive('notdemo', static fn (): string => '<?php if (! app(\LauroGuedes\DemoMode\DemoMode::class)->enabled()): ?>');
            $blade->directive('endnotdemo', static fn (): string => '<?php endif; ?>');
        });
    }

    /**
     * @param  list<class-string>  $commands
     */
    private function registerCommands(array $commands): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands($commands);
        }
    }

    /**
     * Registered by the package rather than left to the application's
     * routes/console.php, so a demo cannot be running with its flag on and its
     * schedule forgotten — which is a demo that never resets and whose banner
     * keeps promising that it will.
     *
     * Deferred until something resolves the scheduler, which only
     * 'schedule:run' and 'schedule:list' do. Registering eagerly would parse the
     * cron expression and build the console kernel, a Schedule with its mutexes,
     * an Event and an instance of ResetCommand — on every HTTP request of a demo,
     * to produce something no HTTP request can ever consume.
     *
     * runInBackground() makes the run a separate process; onOneServer() keeps a
     * multi-server deployment from starting several. Both matter more than usual
     * here, because the work in question drops every table.
     */
    private function registerSchedule(): void
    {
        $this->callAfterResolving(Scheduler::class, static function (Scheduler $schedule, Container $app): void {
            $expression = $app->make(DemoMode::class)->schedule();

            if ($expression === null) {
                return;
            }

            $schedule->command(ResetCommand::class, ['--force'])
                ->cron($expression->expression)
                ->withoutOverlapping()
                ->runInBackground()
                ->onOneServer();
        });
    }
}
