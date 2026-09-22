<?php

declare(strict_types=1);

namespace LauroGuedes\DemoMode\Reset\Strategies;

use LauroGuedes\DemoMode\Contracts\ResetStrategy;
use LauroGuedes\DemoMode\Exceptions\DemoModeException;
use LauroGuedes\DemoMode\Reset\ResetContext;
use LauroGuedes\DemoMode\Support\Options;
use Spatie\DbSnapshots\Commands\Load;
use Spatie\DbSnapshots\SnapshotRepository;
use Throwable;

/**
 * Restore a dump taken once, instead of rebuilding from scratch.
 *
 * What migrate-fresh-seed costs is proportional to how much data makes the demo
 * look like itself, and on a demo with a realistic amount that is minutes of
 * maintenance page every cycle. Restoring a snapshot is one import: the same
 * result, in roughly the time the database takes to read a file.
 *
 * Delegates to spatie/laravel-db-snapshots, which is a suggested dependency
 * rather than a required one — most demos never need this, and a package that
 * drops tables should ask for as little trust as it can.
 *
 * The snapshot is a file containing every row a visitor will see. Whatever your
 * seeder would not have put in the demo, do not put in the snapshot: this is the
 * strategy where a dump of production data is one careless 'demo:snapshot' away.
 */
final readonly class Snapshot implements ResetStrategy
{
    public const string DEFAULT_NAME = 'demo-baseline';

    /**
     * @param  array<string, mixed>  $options
     */
    public function __construct(private array $options = []) {}

    public function run(ResetContext $context): void
    {
        $context->report('Restoring the baseline snapshot');

        /*
         * '--connection', not '--database': this is spatie's command, not
         * Laravel's, and it spells the flag its own way.
         */
        $exit = $context->artisan->call('snapshot:load', $context->arguments([
            'name' => $this->name(),
            '--force' => true,
            /*
             * Without this the restore lands on top of whatever a visitor left
             * behind, which is not a reset.
             */
            '--drop-tables' => true,
        ], '--connection'));

        if ($exit !== 0) {
            throw new DemoModeException(sprintf('Restoring the snapshot [%s] failed.', $this->name()));
        }
    }

    public function describe(): string
    {
        return sprintf('Restore the snapshot [%s]', $this->name());
    }

    /**
     * Restoring a snapshot brings back the password hash the baseline froze, so
     * whatever the reset just published opens nothing.
     */
    public function seedsCredentials(): bool
    {
        return false;
    }

    public function validate(): array
    {
        if (! class_exists(Load::class)) {
            return ['The snapshot strategy needs spatie/laravel-db-snapshots. Run: composer require spatie/laravel-db-snapshots'];
        }

        if ($this->name() === '') {
            return ['No snapshot name is configured. Set demo.reset.strategies.snapshot.name.'];
        }

        /*
         * The check the promise is actually made of. spatie's Load command
         * returns quietly when the named snapshot is not there, so without this
         * a reset against a missing baseline drops every table and reports
         * success.
         */
        if (! $this->exists()) {
            return [sprintf(
                'The snapshot [%s] does not exist. Take it with "php artisan demo:snapshot" before the first reset.',
                $this->name(),
            )];
        }

        return [];
    }

    public function name(): string
    {
        return Options::string($this->options['name'] ?? null, '');
    }

    private function exists(): bool
    {
        try {
            return app(SnapshotRepository::class)->findByName($this->name()) !== null;
        } catch (Throwable) {
            /*
             * An unconfigured snapshots disk throws here. Treated as "cannot
             * confirm" rather than "missing", because refusing every reset on a
             * disk misconfiguration this class cannot diagnose would be worse
             * than letting spatie report it.
             */
            return true;
        }
    }
}
