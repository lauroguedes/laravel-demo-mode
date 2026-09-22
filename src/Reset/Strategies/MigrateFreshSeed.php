<?php

declare(strict_types=1);

namespace LauroGuedes\DemoMode\Reset\Strategies;

use LauroGuedes\DemoMode\Contracts\ResetStrategy;
use LauroGuedes\DemoMode\Reset\ResetContext;

/**
 * Drop everything and seed it again.
 *
 * The default, because it is the only strategy that works everywhere with no
 * setup: no snapshot to take first, no dump to version, no closure to write. It
 * is also the slowest, and on a demo with a lot of seeded data it is slow enough
 * to notice — which is when the snapshot strategy earns its dependency.
 *
 * Runs inside the one window where destructive Artisan commands are permitted.
 * Nothing else in a reset gets that, and this class should stay the only reason
 * the window exists.
 */
final readonly class MigrateFreshSeed implements ResetStrategy
{
    /**
     * @param  array<string, mixed>  $options
     */
    public function __construct(private array $options = []) {}

    public function run(ResetContext $context): void
    {
        $context->artisan->call('migrate:fresh', $context->arguments([
            '--force' => true,
            '--drop-views' => (bool) ($this->options['drop_views'] ?? false),
        ]));

        $seeder = $this->seeder();

        if ($seeder === null) {
            return;
        }

        $context->report('Seeding the demonstration');

        $context->artisan->call('db:seed', $context->arguments([
            '--class' => $seeder,
            '--force' => true,
        ]));
    }

    public function describe(): string
    {
        $seeder = $this->seeder();

        return $seeder === null
            ? 'Drop every table and migrate'
            : sprintf('Drop every table, migrate, and seed with %s', class_basename($seeder));
    }

    /**
     * The seeder runs, so whatever the reset staged is what gets hashed.
     */
    public function seedsCredentials(): bool
    {
        return $this->seeder() !== null;
    }

    /**
     * The check that matters most here, and the one an application is most
     * likely to trip: a seeder named in config that does not exist.
     *
     * Getting this wrong is expensive in a way most misconfigurations are not.
     * migrate:fresh has already run by the time db:seed fails, so the demo is
     * left with an empty database and no data to serve — the tables are gone and
     * the thing that was going to refill them was never there. Failing in
     * 'demo:doctor', before the first scheduled run, is the whole point of the
     * command existing.
     */
    public function validate(): array
    {
        $seeder = $this->options['seeder'] ?? null;

        if ($seeder === null || $seeder === '') {
            return ['No seeder is configured, so a reset leaves an empty database. Set demo.reset.strategies.migrate-fresh-seed.seeder.'];
        }

        if (! is_string($seeder)) {
            return [sprintf('The configured seeder should be a class name, got %s.', get_debug_type($seeder))];
        }

        if (! class_exists($seeder)) {
            return [sprintf(
                'The configured seeder [%s] does not exist. A reset would drop every table and then fail with nothing to seed.',
                $seeder,
            )];
        }

        return [];
    }

    private function seeder(): ?string
    {
        $seeder = $this->options['seeder'] ?? null;

        return is_string($seeder) && $seeder !== '' && class_exists($seeder) ? $seeder : null;
    }
}
