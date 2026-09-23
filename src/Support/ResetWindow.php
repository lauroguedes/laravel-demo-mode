<?php

declare(strict_types=1);

namespace LauroGuedes\DemoMode\Support;

/**
 * The stretch of time in which the reset is the one writing.
 *
 * Two guards have to stand down for it, for the same reason and over exactly the
 * same stretch, so there is one window rather than one flag each:
 *
 *   - The connection guard, because a rebuild drops and recreates every table
 *     and then the cleaners write to sessions, cache and queues — none of which
 *     its exception list covers, since that list is about what a visitor's
 *     request legitimately touches.
 *   - The protected-record guard, because the reset is what creates the account
 *     that guard keeps a visitor from editing.
 *
 * Laravel's own destructive-command prohibition is deliberately *not* here. Its
 * window is narrower — just the strategy call — so that "migrate:fresh" is not
 * left permitted while the cleaners run. See Reset\Runner.
 *
 * ## What this is not
 *
 * Process-global, and not request-scoped. Under PHP-FPM or an artisan command
 * that is the same thing, and the "finally" closes the window even when the
 * reset throws.
 *
 * It is not the same thing on a coroutine-based Octane worker running a
 * *synchronous* on-demand reset: the rebuild's many database round-trips yield,
 * and another request served by that worker is inside the window while it does.
 * Queue the on-demand reset — which is the default, and which "demo:doctor"
 * already warns about when it is turned off — and the reset runs in a worker
 * process that serves nothing else.
 */
final class ResetWindow
{
    private static bool $open = false;

    /**
     * Run the callback with both reset guards stood down.
     *
     * @template TReturn
     *
     * @param  callable(): TReturn  $callback
     * @return TReturn
     */
    public static function during(callable $callback): mixed
    {
        self::$open = true;

        try {
            return $callback();
        } finally {
            self::$open = false;
        }
    }

    public static function isOpen(): bool
    {
        return self::$open;
    }
}
