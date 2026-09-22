<?php

declare(strict_types=1);

namespace LauroGuedes\DemoMode\Reset;

use Illuminate\Contracts\Container\Container;
use LauroGuedes\DemoMode\Configuration;
use LauroGuedes\DemoMode\Contracts\ResetStrategy;
use LauroGuedes\DemoMode\Exceptions\InvalidConfiguration;

/**
 * Builds the configured strategy, with its own options folded in.
 *
 * Each strategy has a named block in config rather than a flat list of keys, so
 * that 'seeder' belongs to migrate-fresh-seed and 'path' belongs to sql-dump and
 * neither has to be prefixed to stay out of the other's way. Switching strategy
 * is then one env var, with the other strategy's settings still sitting there
 * ready.
 *
 * Command-line options win over config, which is what makes
 * 'demo:reset --strategy=sql-dump' useful for trying one before committing to it.
 */
final readonly class StrategyFactory
{
    public function __construct(
        private Configuration $config,
        private Container $container,
    ) {}

    /**
     * @param  array<string, mixed>  $overrides
     */
    public function make(?string $name = null, array $overrides = []): ResetStrategy
    {
        $name ??= $this->config->string('reset.strategy', 'migrate-fresh-seed');

        $strategies = $this->config->array('reset.strategies');
        $definition = $strategies[$name] ?? null;

        if (! is_array($definition)) {
            throw InvalidConfiguration::unknownStrategy($name, array_map(strval(...), array_keys($strategies)));
        }

        $driver = $definition['driver'] ?? null;

        if (! is_string($driver) || ! class_exists($driver)) {
            throw InvalidConfiguration::expected(
                sprintf('demo.reset.strategies.%s.driver', $name),
                'a class name',
                $driver,
            );
        }

        unset($definition['driver']);

        /*
         * The reset connection is folded in so that validate() can ask about the
         * database it would actually touch. A strategy is handed options, not a
         * ResetContext, and until it runs there is nothing else to ask.
         */
        /** @var array<string, mixed> $options */
        $options = [
            'connection' => $this->config->nullableString('reset.connection'),
            ...$definition,
            ...array_filter(
                $overrides,
                static fn (mixed $value): bool => $value !== null && $value !== false,
            ),
        ];

        $strategy = $this->container->make($driver, ['options' => $options]);

        if (! $strategy instanceof ResetStrategy) {
            throw InvalidConfiguration::expected(
                sprintf('demo.reset.strategies.%s.driver', $name),
                'a ResetStrategy implementation',
                $strategy,
            );
        }

        return $strategy;
    }
}
