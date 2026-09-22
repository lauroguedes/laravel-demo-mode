<?php

declare(strict_types=1);

namespace LauroGuedes\DemoMode\Cleaners;

use Illuminate\Contracts\Console\Kernel as Artisan;
use Laravel\Telescope\Telescope;
use LauroGuedes\DemoMode\Contracts\Cleaner;
use Throwable;

/**
 * Empty Telescope, if it is installed.
 *
 * Telescope records requests, and on a public demo that includes every request a
 * stranger made — with their input, their headers, and the queries those
 * produced. Keeping that across a reset means the demo accumulates a log of what
 * visitors typed, on a server whose whole premise is that anyone can sign in and
 * look around.
 *
 * Not in the default cleaner list: an application that runs Telescope on its demo
 * may be doing so precisely to watch it, and this package should not decide that.
 */
final readonly class FlushTelescope implements Cleaner
{
    public function __construct(private Artisan $artisan) {}

    public function clean(array $options): void
    {
        if (! class_exists(Telescope::class)) {
            return;
        }

        try {
            $this->artisan->call('telescope:clear');
        } catch (Throwable) {
            // Installed but not migrated is a fair state to be in.
        }
    }

    public function describe(): string
    {
        return 'Empty the Telescope entries';
    }
}
