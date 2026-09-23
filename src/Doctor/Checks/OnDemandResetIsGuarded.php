<?php

declare(strict_types=1);

namespace LauroGuedes\DemoMode\Doctor\Checks;

use Illuminate\Contracts\Config\Repository;
use LauroGuedes\DemoMode\Cleaners\FlushCache;
use LauroGuedes\DemoMode\Configuration;
use LauroGuedes\DemoMode\Contracts\RunsOnDemosOnly;
use LauroGuedes\DemoMode\Doctor\Finding;
use LauroGuedes\DemoMode\Support\CacheKeys;
use LauroGuedes\DemoMode\Support\Options;

/**
 * The on-demand route hands an anonymous visitor a migrate:fresh.
 *
 * Off by default, so none of this fires on a demo that never asked for it. Once
 * it is on, these are the settings whose wrong value turns a convenience into a
 * way to keep somebody's database rebuilding.
 */
final readonly class OnDemandResetIsGuarded implements RunsOnDemosOnly
{
    public function __construct(
        private Configuration $config,
        private Repository $appConfig,
        private CacheKeys $keys,
    ) {}

    /**
     * @return list<Finding>
     */
    public function run(): array
    {
        if (! $this->config->boolean('on_demand.enabled')) {
            return [];
        }

        return [
            ...$this->hasCsrf(),
            ...$this->hasACooldown(),
            ...$this->cooldownSurvivesTheReset(),
            ...$this->isOffTheRequest(),
            ...$this->readOnlyLetsItThrough(),
        ];
    }

    /**
     * Without the web group there is no CSRF token, and without that any page
     * anywhere can rebuild this demo with a form post the visitor never saw.
     *
     * @return list<Finding>
     */
    private function hasCsrf(): array
    {
        $middleware = $this->config->strings('on_demand.middleware');

        foreach ($middleware as $entry) {
            if ($entry === 'web' || str_contains($entry, 'VerifyCsrfToken')) {
                return [];
            }
        }

        return [Finding::error(
            'on-demand-reset',
            'The on-demand reset route has no CSRF protection: demo.on_demand.middleware does not include "web". '
                .'Any page on the internet could rebuild this demo with a form post.',
            'Put "web" back in demo.on_demand.middleware.',
        )];
    }

    /**
     * The throttle stops one visitor pressing repeatedly; the cooldown stops
     * fifty visitors each pressing once. Neither substitutes for the other.
     *
     * @return list<Finding>
     */
    private function hasACooldown(): array
    {
        if ($this->config->integer('on_demand.cooldown', 900) > 0) {
            return [];
        }

        return [Finding::warning(
            'on-demand-reset',
            'The on-demand reset has no cooldown, so the throttle is the only limit — and a throttle counts per '
                .'visitor, so enough visitors are enough rebuilds.',
            'Set demo.on_demand.cooldown to the shortest gap you would accept between rebuilds.',
        )];
    }

    /**
     * Read-only mode blocks POSTs by route name, and the reset route's name is a
     * config key of its own.
     *
     * Rename either without the other and the reset button 403s — a config pair
     * the package already cross-checks in two other places, so it may as well
     * check this one too.
     *
     * @return list<Finding>
     */
    private function readOnlyLetsItThrough(): array
    {
        if (! $this->config->boolean('guards.read_only.enabled')) {
            return [];
        }

        $name = $this->config->string('on_demand.name', 'demo.reset');

        if (in_array($name, $this->config->strings('guards.read_only.except'), true)) {
            return [];
        }

        return [Finding::error(
            'on-demand-reset',
            sprintf(
                'Read-only mode is on and the reset route [%s] is not in guards.read_only.except, so the reset '
                    .'button answers 403 to everybody who presses it.',
                $name,
            ),
            sprintf('Add "%s" to demo.guards.read_only.except.', $name),
        )];
    }

    /**
     * The cooldown is measured from the recorded reset, which lives in the cache
     * — the same cache the reset flushes.
     *
     * With FlushCache's 'except' list not keeping it, every rebuild erases the
     * timestamp the next cooldown would have been measured against. The cooldown
     * then reads "never reset" and permits everything, leaving the throttle as
     * the only limit, and nothing anywhere says so.
     *
     * @return list<Finding>
     */
    private function cooldownSurvivesTheReset(): array
    {
        if ($this->config->integer('on_demand.cooldown', 900) <= 0) {
            return [];
        }

        $cleaners = $this->config->classMap('cleaners');
        $options = $cleaners[FlushCache::class] ?? null;

        if ($options === null) {
            return [];
        }

        $except = Options::strings($options['except'] ?? []);

        /*
         * Both keys, because the cleaner runs in the middle of a reset and the
         * lock is in the same store as the timestamp. Losing the timestamp
         * disables the cooldown; losing the lock lets a second reset start while
         * the first is still going.
         */
        $lost = array_values(array_filter(
            [CacheKeys::LAST_RESET, CacheKeys::LOCK],
            fn (string $key): bool => ! $this->keys->isPreserved($key, $except),
        ));

        if ($lost === []) {
            return [];
        }

        return [Finding::error(
            'on-demand-reset',
            sprintf(
                'FlushCache will erase %s, which the reset itself depends on: its "except" list does not keep them. '
                    .'The cooldown would read "never reset" after every rebuild, and the lock that stops two resets '
                    .'overlapping would be dropped halfway through one.',
                implode(' and ', $lost),
            ),
            'Add "demo-mode:*" to the FlushCache cleaner\'s "except" list.',
        )];
    }

    /**
     * A rebuild inside the request means a visitor watching a spinner until the
     * proxy gives up, at which point the reset carries on invisibly and they
     * press the button again.
     *
     * @return list<Finding>
     */
    private function isOffTheRequest(): array
    {
        if (! $this->config->boolean('on_demand.queue', true)) {
            return [Finding::warning(
                'on-demand-reset',
                'The on-demand reset runs inside the web request. A rebuild takes as long as it takes, and the '
                    .'visitor waits for all of it.',
                'Set demo.on_demand.queue to true and run a worker.',
            )];
        }

        if (Options::string($this->appConfig->get('queue.default'), 'sync') !== 'sync') {
            return [];
        }

        return [Finding::warning(
            'on-demand-reset',
            'The on-demand reset is queued, but QUEUE_CONNECTION is "sync", so it runs inside the request anyway.',
            'Use a real queue connection and run a worker, or accept the wait.',
        )];
    }
}
