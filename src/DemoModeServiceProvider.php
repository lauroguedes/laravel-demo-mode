<?php

declare(strict_types=1);

namespace LauroGuedes\DemoMode;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Console\Scheduling\Schedule as Scheduler;
use Illuminate\Contracts\Container\Container;
use Illuminate\Database\DatabaseManager;
use Illuminate\Http\Request;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\View\Compilers\BladeCompiler;
use LauroGuedes\DemoMode\Console\CredentialsCommand;
use LauroGuedes\DemoMode\Console\DoctorCommand;
use LauroGuedes\DemoMode\Console\InstallCommand;
use LauroGuedes\DemoMode\Console\PruneSandboxesCommand;
use LauroGuedes\DemoMode\Console\ResetCommand;
use LauroGuedes\DemoMode\Console\SnapshotCommand;
use LauroGuedes\DemoMode\Console\StatusCommand;
use LauroGuedes\DemoMode\Contracts\CredentialStore;
use LauroGuedes\DemoMode\Credentials\Manager as Credentials;
use LauroGuedes\DemoMode\Credentials\StoreFactory;
use LauroGuedes\DemoMode\Guards\ConnectionGuard;
use LauroGuedes\DemoMode\Guards\ModelGuard;
use LauroGuedes\DemoMode\Guards\ProtectedRecords;
use LauroGuedes\DemoMode\Http\Controllers\AssetController;
use LauroGuedes\DemoMode\Http\Controllers\ResetController;
use LauroGuedes\DemoMode\Http\Middleware\AttachSandbox;
use LauroGuedes\DemoMode\Http\Middleware\EnsureDemoHost;
use LauroGuedes\DemoMode\Http\Middleware\ReadOnlyMiddleware;
use LauroGuedes\DemoMode\Reset\GuardChain;
use LauroGuedes\DemoMode\Reset\Guards\DemoModeIsEnabled;
use LauroGuedes\DemoMode\Reset\Guards\EnvironmentIsAllowed;
use LauroGuedes\DemoMode\Reset\Guards\HostIsAllowed;
use LauroGuedes\DemoMode\Reset\Guards\NotProduction;
use LauroGuedes\DemoMode\Restrictions\Pipeline as Restrictions;
use LauroGuedes\DemoMode\Sandbox\Manager as SandboxManager;
use LauroGuedes\DemoMode\Sandbox\Purger;
use LauroGuedes\DemoMode\Support\Options;
use LauroGuedes\DemoMode\View\Components\Banner;
use LauroGuedes\DemoMode\View\Components\Credentials as CredentialsComponent;
use LauroGuedes\DemoMode\View\Components\Script as ScriptComponent;

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
        ));

        $this->app->singleton(CredentialStore::class, static fn (Container $app): CredentialStore => $app
            ->make(StoreFactory::class)
            ->make());

        $this->app->singleton(Credentials::class);

        /*
         * Scoped rather than singleton: what it resolves belongs to one request,
         * and under Octane a singleton would carry one visitor's sandbox into the
         * next visitor's request. It keys its own memo to the session as well,
         * because a test makes two requests against one container too.
         */
        $this->app->scoped(SandboxManager::class);

        /*
         * Singleton because pruning resolves it once per expired sandbox, and an
         * unbound class is rebuilt by reflection every time. It holds only the
         * Configuration singleton, so there is nothing per-request in it.
         */
        $this->app->singleton(Purger::class);

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
        $this->registerViewComponents();
        $this->registerCommands(self::SETUP_COMMANDS);
        $this->registerMiddlewareAliases();

        if (! $this->app->make(Configuration::class)->enabled()) {
            return;
        }

        $this->app->make(Restrictions::class)->apply();

        $this->registerWriteGuards();
        $this->registerBarAsset();
        $this->registerOnDemandReset();
        $this->registerSandbox();
        $this->registerCommands([ResetCommand::class, SnapshotCommand::class]);
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

        /*
         * Published rather than loaded, because only the scoped sandbox driver
         * needs this table and a package should not add one to a database that
         * never asked. demo:doctor reports it missing when scoped is on.
         */
        $this->publishes([
            __DIR__.'/../database/migrations/create_demo_sandboxes_table.php.stub' => $this->app->databasePath(
                'migrations/'.date('Y_m_d_His').'_create_demo_sandboxes_table.php',
            ),
        ], 'demo-migrations');
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
     * Registered on every installation, because both components render nothing
     * when this is not a demo and a layout should not have to know which it is.
     * loadViewComponentsAs defers until the compiler resolves, so a request that
     * renders no view pays nothing.
     */
    private function registerViewComponents(): void
    {
        $this->loadViewComponentsAs('demo', [
            'banner' => Banner::class,
            'credentials' => CredentialsComponent::class,
            'script' => ScriptComponent::class,
        ]);
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
     * The two write guards that register themselves.
     *
     * The read-only middleware is not one of them: it gets an alias instead, so an
     * application can put it where its own stack needs it, because a package that
     * pushed itself into the web group would be deciding an ordering that depends
     * on somebody else's session and auth middleware. That alias is registered in
     * registerMiddlewareAliases(), outside the flag.
     */
    private function registerWriteGuards(): void
    {
        foreach ($this->app->make(ProtectedRecords::class)->models() as $model) {
            $model::observe(ModelGuard::class);
        }

        $this->app->make(ConnectionGuard::class)->register($this->app->make(DatabaseManager::class));
    }

    /**
     * Both aliases, on every installation, demo or not.
     *
     * These are documented as a line in bootstrap/app.php:
     *
     *     $middleware->web(append: ['demo.readonly']);
     *
     * A line in bootstrap/app.php is there on every deployment of that
     * application, and most of them are not demos — every developer's checkout is
     * not. Laravel resolves an alias it does not know as a class name, so an alias
     * registered only while the flag is on threw a BindingResolutionException on
     * every request of every installation that had followed the documentation.
     * Both middleware already do nothing off a demo; it was only the name that was
     * conditional.
     *
     * Through callAfterResolving so this stays the "zero cost when disabled" the
     * rest of this class is about: an application that never resolves the Router —
     * a console command, a queue worker — never pays for two array writes.
     */
    private function registerMiddlewareAliases(): void
    {
        $this->callAfterResolving(Router::class, static function (Router $router): void {
            $router->aliasMiddleware('demo.readonly', ReadOnlyMiddleware::class);
            $router->aliasMiddleware('demo.sandbox', AttachSandbox::class);
        });
    }

    /**
     * The route that lets a visitor rebuild the demo.
     *
     * Registered only when an application asked for it, and with the middleware
     * it configured — 'web' by default, because that is where CSRF comes from and
     * without it any page anywhere could rebuild the demo with a form post.
     *
     * The rate limiter is named rather than inline so that 'per' can mean
     * something other than Laravel's default of user-or-IP. Behind a proxy an IP
     * is only as trustworthy as TrustProxies, which is why 'session' is offered:
     * it counts a browser instead.
     */
    private function registerOnDemandReset(): void
    {
        $config = $this->app->make(Configuration::class);

        if (! $config->boolean('on_demand.enabled')) {
            return;
        }

        /*
         * Resolved here and captured, so the closure can stay static. A limiter
         * lives for the life of the application, and one bound to $this would
         * hold the provider with it — the same reason Restrictions\BlockPrivileged-
         * Accounts says so about its listeners.
         */
        $demo = $this->app->make(DemoMode::class);

        RateLimiter::for('demo-mode-reset', static function (Request $request) use ($config, $demo): Limit {
            /*
             * Two different actions behind one route, so two different limits.
             * Rebuilding the server is worth one an hour; clearing your own rows
             * is not, and reusing that limit made the scoped button useless.
             */
            $throttle = $demo->onDemandScope() === 'sandbox'
                ? $config->array('on_demand.sandbox_throttle')
                : $config->array('on_demand.throttle');

            $limit = Limit::perMinutes(
                max(1, Options::integer($throttle['minutes'] ?? null, 60)),
                max(1, Options::integer($throttle['attempts'] ?? null, 1)),
            );

            return match ($config->string('on_demand.per', 'ip')) {
                'global' => $limit->by('demo-mode:global'),
                'session' => $limit->by($request->hasSession() ? $request->session()->getId() : $request->ip() ?? 'unknown'),
                default => $limit->by($request->ip() ?? 'unknown'),
            };
        });

        $this->app->make(Router::class)
            ->post($config->string('on_demand.route', '/demo/reset'), ResetController::class)
            ->middleware([
                /*
                 * The host check comes first, ahead of the throttle: a request
                 * that will be 404'd should not spend a rate-limit slot on the
                 * way there, or one request with a forged Host header takes the
                 * reset button away from every real visitor.
                 */
                EnsureDemoHost::class,
                ...$config->strings('on_demand.middleware'),
                'throttle:demo-mode-reset',
            ])
            ->name($config->string('on_demand.name', 'demo.reset'));
    }

    /**
     * The floating bar's script, on its own route.
     *
     * No middleware at all, not even 'web': it is a static file, and running it
     * through the session middleware would start a session for every asset
     * request and set a cookie on a response that should be cached for a year.
     *
     * Registered only when the bar is the style in use, so an installation on the
     * bare banner adds no route.
     */
    private function registerBarAsset(): void
    {
        $config = $this->app->make(Configuration::class);

        if ($config->string('banner.style', 'bare') !== 'pill' || ! $config->boolean('script', true)) {
            return;
        }

        $this->app->make(Router::class)
            ->get($config->string('banner.asset_route', '/demo-mode/bar.js'), AssetController::class)
            ->name('demo.asset');
    }

    /**
     * The pruning schedule. The middleware alias is registered elsewhere, and
     * unconditionally — see registerSandboxAlias().
     */
    private function registerSandbox(): void
    {
        if (! $this->app->make(Configuration::class)->scoped()) {
            return;
        }

        $this->registerCommands([PruneSandboxesCommand::class]);

        $this->callAfterResolving(Scheduler::class, function (Scheduler $schedule): void {
            $expression = $this->app->make(Configuration::class)->nullableString('sandbox.prune');

            if ($expression === null) {
                return;
            }

            $schedule->command(PruneSandboxesCommand::class)
                ->cron($expression)
                ->withoutOverlapping()
                ->onOneServer();
        });
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
