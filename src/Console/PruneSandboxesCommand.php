<?php

declare(strict_types=1);

namespace LauroGuedes\DemoMode\Console;

use Illuminate\Console\Command;
use LauroGuedes\DemoMode\Configuration;
use LauroGuedes\DemoMode\Sandbox\Sandbox;

/**
 * Removes the sandboxes nobody came back to.
 *
 * A scoped demo accumulates one per visitor, and most visitors look once. Without
 * this the table grows for as long as the demo is up, and so does every scoped
 * query's index.
 *
 * Deletes only what has expired, and the rows that belonged to it. It used to
 * leave those behind, on the reasoning that it had no way to know which tables
 * an application had marked — which stopped being true when demo.sandbox.models
 * arrived. The rows it left were reachable by nobody and carried by every index
 * until the next full reset. Set sandbox.prune_rows false to go back to that.
 *
 * The deleting itself is Laravel's model:prune, so it chunks. What this adds is
 * the one thing that command cannot know: that a demo sharing its data has no
 * sandboxes at all, and saying so is more useful than pruning nothing.
 */
final class PruneSandboxesCommand extends Command
{
    protected $signature = 'demo:sandbox:prune';

    protected $description = 'Remove the demo sandboxes that have expired';

    public function handle(Configuration $config): int
    {
        if (! $config->scoped()) {
            $this->components->info('This demo shares its data, so there are no sandboxes to prune.');

            return self::SUCCESS;
        }

        return $this->call('model:prune', ['--model' => [Sandbox::class]]);
    }
}
