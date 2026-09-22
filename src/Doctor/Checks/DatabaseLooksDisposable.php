<?php

declare(strict_types=1);

namespace LauroGuedes\DemoMode\Doctor\Checks;

use Illuminate\Contracts\Config\Repository;
use Illuminate\Support\Str;
use LauroGuedes\DemoMode\Configuration;
use LauroGuedes\DemoMode\Contracts\RunsOnDemosOnly;
use LauroGuedes\DemoMode\Doctor\Finding;

/**
 * A guess at whether the database about to be dropped is the right one.
 *
 * Openly a heuristic — it reads a name and looks for a word — and it is a
 * warning for that reason. It earns its place anyway, because the mistake it is
 * looking for is the one this whole package exists to prevent, and it is the only
 * check that looks at what is actually going to be dropped rather than at what
 * the configuration says about it.
 *
 * A demo database called "demo", "staging", "sandbox", "playground" or "test"
 * says nothing. One called after the product says something worth reading twice.
 */
final readonly class DatabaseLooksDisposable implements RunsOnDemosOnly
{
    private const array DISPOSABLE = ['demo', 'staging', 'sandbox', 'playground', 'test', 'preview'];

    public function __construct(
        private Configuration $config,
        private Repository $appConfig,
    ) {}

    public function run(): array
    {
        $connection = $this->config->nullableString('reset.connection')
            ?? $this->appConfig->get('database.default');

        if (! is_string($connection)) {
            return [];
        }

        $database = $this->appConfig->get('database.connections.'.$connection.'.database');

        if (! is_string($database) || $database === '' || $database === ':memory:') {
            return [];
        }

        if (Str::contains(basename($database), self::DISPOSABLE, ignoreCase: true)) {
            return [];
        }

        return [Finding::warning(
            'database-name',
            sprintf(
                'The database about to be dropped is called [%s], which does not look like a throwaway. '
                    .'Every reset deletes all of it.',
                $database,
            ),
            'If this is the right database, ignore this. If it is not, fix DB_DATABASE before the next scheduled reset.',
        )];
    }
}
