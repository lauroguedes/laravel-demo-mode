<?php

declare(strict_types=1);

namespace LauroGuedes\DemoMode\Support;

use Illuminate\Database\Console\Migrations\FreshCommand;
use Illuminate\Database\Console\Migrations\RefreshCommand;
use Illuminate\Database\Console\Migrations\ResetCommand;
use Illuminate\Database\Console\Migrations\RollbackCommand;
use Illuminate\Database\Console\WipeCommand;

/**
 * Lowers Laravel's destructive-command prohibition for exactly one call.
 *
 * DB::prohibitDestructiveCommands() sets a static boolean on five command
 * classes. There is no per-call scope and no stack: whoever sets it last wins,
 * for the whole process. A package that needs migrate:fresh has to work inside
 * that, and the honest way is to open the smallest possible window and put
 * closing it in a finally.
 *
 * This exists because of the shape the alternative takes. The usual fix is for an
 * application to disable the prohibition globally whenever demo mode is on —
 *
 *     ProhibitDestructiveCommands::class => ! config('app.demo.enabled'),
 *
 * — which means the demo server, the one strangers can reach, is the one server
 * where migrate:fresh is permanently available. Inverted from what anybody wanted.
 * With this, the prohibition can stay on all the time and still be no obstacle to
 * a scheduled reset.
 *
 * There is nothing to read back: Laravel exposes no getter for the flag, so the
 * prior state is inferred by re-prohibiting afterwards. That is correct for the
 * only case where it matters — an application that prohibits, which is the one
 * worth protecting — and harmless for an application that never did, because it
 * ends up prohibited and can lower it again whenever it likes.
 */
final class DestructiveCommands
{
    /**
     * Run the callback with migrate:fresh and friends permitted.
     *
     * @template TReturn
     *
     * @param  callable(): TReturn  $callback
     * @return TReturn
     */
    public static function permitting(callable $callback): mixed
    {
        self::prohibit(false);

        try {
            return $callback();
        } finally {
            self::prohibit(true);
        }
    }

    /**
     * Calls each command class directly rather than going through the DB facade,
     * so that the set is visible here and a framework release that adds one to
     * the facade's list shows up as a test failure rather than as a command this
     * package quietly stopped permitting.
     */
    public static function prohibit(bool $prohibit): void
    {
        FreshCommand::prohibit($prohibit);
        RefreshCommand::prohibit($prohibit);
        ResetCommand::prohibit($prohibit);
        RollbackCommand::prohibit($prohibit);
        WipeCommand::prohibit($prohibit);
    }
}
