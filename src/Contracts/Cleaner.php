<?php

declare(strict_types=1);

namespace LauroGuedes\DemoMode\Contracts;

/**
 * Something that survives a database reset and should not.
 *
 * Resetting the database is not resetting the application. A session store
 * outlives the users table, so a visitor stays signed in as somebody who no
 * longer exists. A cache outlives the settings row it came from, so the server
 * keeps wearing whatever the last visitor configured. Uploaded files outlive the
 * records that referenced them and accumulate until a disk fills.
 *
 * Cleaners run after the strategy, in configured order, with the application
 * still in maintenance mode and destructive commands prohibited again.
 *
 * A cleaner that throws does not abort the reset. The database is already
 * rebuilt at that point, and refusing to bring the application back up because a
 * queue could not be flushed trades a small mess for an outage.
 */
interface Cleaner
{
    /**
     * @param  array<string, mixed>  $options
     */
    public function clean(array $options): void;

    /**
     * One line for the dry-run plan and the completed report.
     */
    public function describe(): string;
}
