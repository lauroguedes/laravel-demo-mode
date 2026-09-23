<?php

declare(strict_types=1);

namespace LauroGuedes\DemoMode\Reset\Strategies;

use LauroGuedes\DemoMode\Contracts\ResetStrategy;
use LauroGuedes\DemoMode\Exceptions\DemoModeException;
use LauroGuedes\DemoMode\Reset\ResetContext;

/**
 * The escape hatch: the application decides what "back to the start" means.
 *
 * Three strategies cover most demos, and the fourth exists because the package
 * has no business insisting. A demo whose baseline lives in an external service,
 * or which needs three steps in a particular order, or which restores object
 * storage alongside its database, gets to write that rather than work around
 * this package's idea of a reset.
 *
 * Everything the other strategies get, this gets too: it runs inside the one
 * window where destructive Artisan commands are permitted, with the application
 * in maintenance mode, and its failure unwinds the same way.
 *
 * Write it as a callable string or a [Class::class, 'method'] pair rather than a
 * Closure. A Closure in config/demo.php makes 'php artisan config:cache' fail
 * outright — Laravel refuses to serialise it and names this very key — which
 * rules it out on every deployment that caches config, which is every demo
 * server worth having. A string is also the form validate() can check before the
 * reset rather than after it.
 */
final readonly class Callback implements ResetStrategy
{
    /**
     * @param  array<string, mixed>  $options
     */
    public function __construct(private array $options = []) {}

    public function run(ResetContext $context): void
    {
        $callback = $this->options['using'] ?? null;

        if (! is_callable($callback)) {
            /*
             * Not a silent no-op. Falling through would have the Runner record a
             * completed reset, publish credentials and dispatch ResetCompleted,
             * while the demo went on accumulating everything visitors left —
             * a reset that never happened, reported as success.
             */
            throw new DemoModeException(
                'The callback strategy has no callable to run. Set demo.reset.strategies.callback.using.',
            );
        }

        $callback($context);
    }

    public function describe(): string
    {
        return 'Run the configured reset callback';
    }

    /**
     * The package cannot know, so it assumes the worse of the two: a callback
     * that does re-seed will get a warning it can turn off, which is better than
     * one that does not and is never told.
     */
    public function seedsCredentials(): bool
    {
        return (bool) ($this->options['seeds_credentials'] ?? false);
    }

    public function validate(): array
    {
        $callback = $this->options['using'] ?? null;

        if ($callback === null) {
            return ['The callback strategy has no callback. Set demo.reset.strategies.callback.using.'];
        }

        if (! is_callable($callback)) {
            return [sprintf(
                'demo.reset.strategies.callback.using should be callable, got %s. Prefer a callable string or [Class::class, \'method\'] — a Closure cannot survive config:cache.',
                get_debug_type($callback),
            )];
        }

        return [];
    }
}
