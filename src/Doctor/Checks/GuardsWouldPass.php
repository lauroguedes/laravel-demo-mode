<?php

declare(strict_types=1);

namespace LauroGuedes\DemoMode\Doctor\Checks;

use LauroGuedes\DemoMode\Configuration;
use LauroGuedes\DemoMode\Contracts\DoctorCheck;
use LauroGuedes\DemoMode\Doctor\Finding;
use LauroGuedes\DemoMode\Reset\GuardChain;

/**
 * Asks the guard chain the same question a reset would.
 *
 * The distinction the reporting turns on: a guard that refuses on an
 * installation which never claimed to be a demo is the system working, and
 * saying "error" there would train people to ignore the command. A guard that
 * refuses on an installation with DEMO_MODE=true is a demo that will never
 * reset, whose banner is promising visitors something that will not happen.
 */
final readonly class GuardsWouldPass implements DoctorCheck
{
    public function __construct(
        private Configuration $config,
        private GuardChain $guards,
    ) {}

    public function run(): array
    {
        if (! $this->config->enabled()) {
            return [Finding::warning(
                'demo-mode',
                'DEMO_MODE is off, so this installation is not a demo and nothing else here applies.',
                'Set DEMO_MODE=true in the environment that serves your demo.',
            )];
        }

        return array_map(
            static fn (string $reason): Finding => Finding::error('reset-guards', $reason),
            $this->guards->refusals(),
        );
    }
}
