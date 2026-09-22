<?php

declare(strict_types=1);

namespace LauroGuedes\DemoMode\Doctor\Checks;

use LauroGuedes\DemoMode\Contracts\RunsOnDemosOnly;
use LauroGuedes\DemoMode\Doctor\Finding;
use LauroGuedes\DemoMode\Reset\StrategyFactory;
use Throwable;

/**
 * Asks the configured strategy whether it could actually run.
 *
 * The expensive misconfiguration this catches: a seeder class named in config
 * that does not exist. By the time db:seed fails, migrate:fresh has already run,
 * so the demo is left with an empty database and nothing to refill it. The
 * failure arrives after the damage, which is exactly the shape of problem a
 * pre-flight command is for.
 */
final readonly class StrategyIsUsable implements RunsOnDemosOnly
{
    public function __construct(
        private StrategyFactory $strategies,
    ) {}

    public function run(): array
    {
        try {
            $strategy = $this->strategies->make();
        } catch (Throwable $e) {
            return [Finding::error('reset-strategy', $e->getMessage())];
        }

        return array_map(
            static fn (string $problem): Finding => Finding::error('reset-strategy', $problem),
            $strategy->validate(),
        );
    }
}
