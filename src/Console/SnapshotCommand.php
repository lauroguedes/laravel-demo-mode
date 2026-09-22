<?php

declare(strict_types=1);

namespace LauroGuedes\DemoMode\Console;

use Illuminate\Console\Command;
use Illuminate\Database\DatabaseManager;
use LauroGuedes\DemoMode\Configuration;
use LauroGuedes\DemoMode\Console\Concerns\ConfirmsDestruction;
use LauroGuedes\DemoMode\Exceptions\ResetRefused;
use LauroGuedes\DemoMode\Reset\GuardChain;
use LauroGuedes\DemoMode\Reset\Strategies\Snapshot;
use Spatie\DbSnapshots\Commands\Create;

/**
 * Take the baseline the snapshot strategy restores.
 *
 * Run once, against a database in the state you want every visitor to land in —
 * usually right after a migrate:fresh and your seeder. From then on the reset is
 * an import rather than a rebuild.
 *
 * This runs the same guard chain as demo:reset, and the reason is worth stating
 * because the command looks harmless. demo:reset destroys data; this one
 * *publishes* it. It reads every row in the database into a file that every
 * later reset restores to a server strangers sign in to. On the exact scenario
 * demo.environments exists for — a demo .env copied onto a production box —
 * refusing to drop the tables while happily copying them all into the demo's
 * baseline would be the wrong half to guard.
 *
 * So: same barriers, and --force means only "do not ask me to confirm", the same
 * as everywhere else in this package.
 */
final class SnapshotCommand extends Command
{
    use ConfirmsDestruction;

    protected $signature = 'demo:snapshot
        {name? : The snapshot name, defaulting to the configured one}
        {--force : Take it without asking}';

    protected $description = 'Take the baseline snapshot the demo resets to';

    public function handle(Configuration $config, DatabaseManager $database, GuardChain $guards): int
    {
        if (! class_exists(Create::class)) {
            $this->components->error('This needs spatie/laravel-db-snapshots.');
            $this->components->bulletList(['composer require spatie/laravel-db-snapshots']);

            return self::FAILURE;
        }

        try {
            $guards->enforce();
        } catch (ResetRefused $e) {
            $this->components->error('Taking a snapshot here was refused.');

            foreach ($e->reasons as $reason) {
                $this->components->bulletList([$reason]);
            }

            return self::FAILURE;
        }

        $name = $this->stringArgument('name')
            ?? $config->string('reset.strategies.snapshot.name', Snapshot::DEFAULT_NAME);

        $connection = $config->nullableString('reset.connection');

        $this->components->twoColumnDetail('Snapshot', $name);
        $this->components->twoColumnDetail('Reading from', $database->connection($connection)->getDatabaseName());

        if (! $this->confirmed('Every row in this database becomes the demonstration data. Continue?')) {
            $this->components->info('Nothing was taken.');

            return self::FAILURE;
        }

        $this->call('snapshot:create', array_filter([
            'name' => $name,
            '--connection' => $connection,
        ], static fn (mixed $value): bool => $value !== null));

        $this->newLine();
        $this->components->info(sprintf('Every reset will now restore [%s].', $name));
        $this->components->bulletList([
            'Set DEMO_RESET_STRATEGY=snapshot to use it.',
            'Keep the snapshots disk private — it holds every row a visitor will see.',
            'Run demo:doctor to confirm the strategy is usable.',
        ]);

        return self::SUCCESS;
    }
}
