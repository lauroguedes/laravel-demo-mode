<?php

declare(strict_types=1);

namespace LauroGuedes\DemoMode\Doctor\Checks;

use Illuminate\Contracts\Config\Repository;
use LauroGuedes\DemoMode\Configuration;
use LauroGuedes\DemoMode\Contracts\RunsOnDemosOnly;
use LauroGuedes\DemoMode\Doctor\Finding;
use LauroGuedes\DemoMode\Support\Options;

/**
 * The connection guard blocks every write, including the framework's own.
 *
 * Sessions, cache and queues in the database write on ordinary requests, so a
 * demo with this on and any of them unlisted cannot serve a page — and the way
 * it fails is a 403 on the first request, which reads like the guard working
 * rather than the guard misconfigured.
 *
 * Checked against what this application actually uses rather than against a
 * fixed list, because a demo with file sessions and a redis cache needs neither
 * exception and should not be told otherwise.
 */
final readonly class ConnectionGuardIsSurvivable implements RunsOnDemosOnly
{
    public function __construct(
        private Configuration $config,
        private Repository $appConfig,
    ) {}

    /**
     * @return list<Finding>
     */
    public function run(): array
    {
        if (! $this->config->boolean('guards.connection.enabled')) {
            return [];
        }

        $except = $this->config->strings('guards.connection.except_tables');
        $missing = [];

        foreach ($this->databaseBackedTables() as $what => $table) {
            if (! in_array($table, $except, true)) {
                $missing[] = sprintf('%s (%s)', $table, $what);
            }
        }

        if ($missing === []) {
            return [];
        }

        return [Finding::error(
            'connection-guard',
            sprintf(
                'The connection guard is on and these tables are written to on ordinary requests but are not '
                    .'excepted: %s. The demo would answer 403 to its own framework.',
                implode(', ', $missing),
            ),
            'Add them to demo.guards.connection.except_tables, or leave the connection guard off and use '
                .'demo.guards.read_only with demo.guards.protected instead.',
        )];
    }

    /**
     * @return array<string, string>
     */
    private function databaseBackedTables(): array
    {
        $tables = [];

        if ($this->appConfig->get('session.driver') === 'database') {
            $tables['session driver'] = Options::string($this->appConfig->get('session.table'), 'sessions');
        }

        if ($this->appConfig->get('cache.default') === 'database') {
            $tables['cache store'] = Options::string($this->appConfig->get('cache.stores.database.table'), 'cache');
        }

        if ($this->appConfig->get('queue.default') === 'database') {
            $tables['queue connection'] = Options::string($this->appConfig->get('queue.connections.database.table'), 'jobs');
        }

        return $tables;
    }
}
