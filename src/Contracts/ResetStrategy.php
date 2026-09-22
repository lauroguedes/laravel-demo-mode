<?php

declare(strict_types=1);

namespace LauroGuedes\DemoMode\Contracts;

use LauroGuedes\DemoMode\Reset\ResetContext;

/**
 * How a demo gets back to its starting state.
 *
 * An application can write its own, which is the point of the contract being
 * public. What to seed is a decision this package has no business making.
 *
 * A strategy is called with destructive Artisan commands temporarily permitted
 * and the application in maintenance mode, and it is the only step in a reset
 * that runs that way. Anything a strategy does outside its own run() method sees
 * the prohibition back on.
 */
interface ResetStrategy
{
    /**
     * Take the application back to its starting state.
     */
    public function run(ResetContext $context): void;

    /**
     * One line for 'demo:status' and the dry-run plan.
     */
    public function describe(): string;

    /**
     * Whether running this leaves the published accounts re-hashed.
     *
     * Rotation only means anything if something writes the new password into the
     * database. A seeder does; restoring a snapshot or a dump does not — those
     * bring back whatever hash the baseline froze, so a demo that rotates on top
     * of one publishes a password that opens nothing, and keeps the baseline's
     * password working forever. Both failures are silent, which is why the
     * package asks rather than assuming.
     */
    public function seedsCredentials(): bool;

    /**
     * Anything that would stop run() from working, for 'demo:doctor'.
     *
     * Checked without running: a missing suggested dependency, a seeder class
     * that does not exist, a dump file that is not there, a client binary that
     * is not installed. Returning problems here is how a demo finds out before
     * its first scheduled reset rather than after it, with the tables already
     * dropped.
     *
     * The Runner calls this too, immediately after the guards and before it
     * takes the lock, so the promise does not depend on anybody having run
     * 'demo:doctor'. Anything answerable without side effects belongs here.
     *
     * @return list<string>
     */
    public function validate(): array;
}
