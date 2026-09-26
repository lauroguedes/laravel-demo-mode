<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;

/*
 * --no-interaction stands in for the real thing, which is a command invoked with
 * no TTY on stdin: a deploy step, `ssh host 'php artisan ...'` without -t, a
 * `docker exec` without -it, a CI job. Symfony turns all of those into the same
 * non-interactive input, and Testbench's own ArrayInput is interactive by
 * default, so it has to be said out loud here.
 *
 * What a skipped confirmation means, which is the opposite of what Laravel's own
 * ConfirmableTrait would do.
 *
 * ConfirmableTrait prompts only in production and treats a non-interactive run as
 * consent. Both are the wrong way round for this command: it is for everywhere
 * except production, and silence from a terminal nobody is sitting at is not
 * agreement. So a run with nobody to ask and no --force refuses and changes
 * nothing.
 *
 * Asserted here because the refusal is easy to mistake for success from the
 * outside: the command prints, exits, and the demo carries on serving the data it
 * already had. A deploy step that drops the flag would look like it worked, and
 * the first sign otherwise is a login page with no published password on it —
 * because a reset is what publishes one.
 */
beforeEach(function (): void {
    demo(['demo.reset.strategy' => 'migrate-fresh-seed']);
    freshSchema();
});

it('refuses when there is nobody to answer and no --force', function (): void {
    DB::table('demo_users')->insert(['email' => 'still@here.test', 'password' => 'x']);

    $this->artisan('demo:reset', ['--no-interaction' => true])->assertFailed();

    expect(DB::table('demo_users')->where('email', 'still@here.test')->exists())->toBeTrue();
});

it('says which flag would have meant yes', function (): void {
    $this->artisan('demo:reset', ['--no-interaction' => true])
        ->expectsOutputToContain('--force')
        ->assertFailed();
});

/**
 * The refusal has to be distinguishable from a reset that ran, or a pipeline
 * cannot tell the difference. Non-zero is the contract.
 */
it('exits non-zero so a pipeline stops rather than carrying on', function (): void {
    expect($this->artisan('demo:reset', ['--no-interaction' => true])->run())->not->toBe(0);
});

/*
 * These two are about the gate and not the reset behind it. That a reset with
 * --force actually rebuilds the database is ResetsAWholeApplicationTest's job,
 * and it needs a migration path this file has no reason to set up.
 */

it('does not stop to ask when --force says a person meant it', function (): void {
    $this->artisan('demo:reset', ['--force' => true, '--no-interaction' => true])
        ->doesntExpectOutputToContain('Nothing is attached')
        ->run();
});

/**
 * --dry-run prints the plan and touches nothing, so there is nothing to consent
 * to and asking would be noise in a pipeline that only wanted to look.
 */
it('does not ask before a dry run', function (): void {
    $this->artisan('demo:reset', ['--dry-run' => true, '--no-interaction' => true])
        ->doesntExpectOutputToContain('Nothing is attached')
        ->run();
});
