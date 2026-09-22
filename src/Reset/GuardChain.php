<?php

declare(strict_types=1);

namespace LauroGuedes\DemoMode\Reset;

use Illuminate\Support\Arr;
use LauroGuedes\DemoMode\Contracts\ResetGuard;
use LauroGuedes\DemoMode\Exceptions\ResetRefused;

/**
 * Every barrier in front of a reset, asked together.
 *
 * Asks all of them even after one refuses, so someone setting up a demo learns
 * everything that is wrong in one run. A chain that stopped at the first failure
 * would turn a five-minute configuration into five commands.
 *
 * Nothing here has side effects, which is what makes it safe for --dry-run to
 * call and safe for the guard matrix test to call thousands of times.
 */
final readonly class GuardChain
{
    /**
     * @param  list<ResetGuard>  $guards
     */
    public function __construct(private array $guards = []) {}

    /**
     * Every guard's verdict, keyed by name, null meaning satisfied.
     *
     * @return array<string, string|null>
     */
    public function verdicts(): array
    {
        $verdicts = [];

        foreach ($this->guards as $guard) {
            $verdicts[$guard->name()] = $guard->check();
        }

        return $verdicts;
    }

    /**
     * @return list<string>
     */
    public function refusals(): array
    {
        return array_values(Arr::whereNotNull($this->verdicts()));
    }

    /**
     * @throws ResetRefused
     */
    public function enforce(): void
    {
        $refusals = $this->refusals();

        if ($refusals !== []) {
            throw new ResetRefused($refusals);
        }
    }
}
