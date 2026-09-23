<?php

declare(strict_types=1);

namespace LauroGuedes\DemoMode\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use LauroGuedes\DemoMode\Reset\Runner;

/**
 * A reset, off the request.
 *
 * A rebuild takes as long as it takes, and doing it inside the web request that
 * asked for it means a visitor watching a spinner until their browser or the
 * proxy in front of the application gives up — at which point the reset carries
 * on invisibly and they press the button again.
 *
 * Unique, so two visitors pressing the button at the same moment queue one job
 * rather than two. The Runner's lock would refuse the second anyway; this stops
 * it being dispatched at all, which keeps a failed-job table from filling up
 * with refusals on a busy demo.
 *
 * The guards run inside the Runner, as they do for every other path. Nothing
 * about arriving here by HTTP relaxes them.
 */
class ResetTheDemo implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    /**
     * One at a time, and long enough that a crashed worker cannot block the next
     * one for the rest of the day.
     */
    public int $uniqueFor = 1800;

    public function uniqueId(): string
    {
        return 'demo-mode:reset';
    }

    public function handle(Runner $runner): void
    {
        /*
         * No options. There is no 'force' to pass: the Runner has no flag that
         * skips a guard, and the confirmation --force skips belongs to the
         * command, where there is somebody to confirm.
         */
        $runner->run();
    }
}
