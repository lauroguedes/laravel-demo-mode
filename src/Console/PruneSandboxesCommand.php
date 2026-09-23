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
 * Deletes only what has expired. The rows a visitor created keep their sandbox id
 * and are removed by the next reset along with everything else — this command
 * does not go looking for them, because it has no way to know which tables an
 * application marked and a command that guessed would be a command that deleted
 * the wrong thing.
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
