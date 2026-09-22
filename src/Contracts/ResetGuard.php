<?php

declare(strict_types=1);

namespace LauroGuedes\DemoMode\Contracts;

/**
 * One barrier in front of a destructive reset.
 *
 * A guard answers null when it is satisfied and a sentence when it is not. The
 * sentence is shown to whoever ran the command, so it says what is wrong and
 * what would fix it — a guard that only says "refused" sends someone to read
 * the source.
 *
 * Guards never have side effects. They are asked in any order, all of them are
 * asked even after one has refused, and asking twice costs nothing. That is what
 * lets 'demo:reset --dry-run' print the whole verdict without touching anything.
 */
interface ResetGuard
{
    /**
     * A short identifier, used in the dry-run table and in test assertions.
     */
    public function name(): string;

    /**
     * Null when satisfied; otherwise why not.
     */
    public function check(): ?string;
}
