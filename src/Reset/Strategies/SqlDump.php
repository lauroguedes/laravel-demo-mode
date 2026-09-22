<?php

declare(strict_types=1);

namespace LauroGuedes\DemoMode\Reset\Strategies;

use Illuminate\Database\Connection;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\SQLiteConnection;
use Illuminate\Process\Factory as ProcessFactory;
use Illuminate\Support\Str;
use LauroGuedes\DemoMode\Contracts\ResetStrategy;
use LauroGuedes\DemoMode\Exceptions\DemoModeException;
use LauroGuedes\DemoMode\Reset\ResetContext;
use LauroGuedes\DemoMode\Support\DumpClient;
use LauroGuedes\DemoMode\Support\Options;
use Symfony\Component\Process\ExecutableFinder;

/**
 * Load a .sql file the project keeps in version control.
 *
 * For a demo whose baseline is a dump somebody maintains by hand, or exports
 * from somewhere else. No extra package, no seeder to keep in step with the
 * schema — and the trade is that the dump and the migrations can drift apart
 * silently, because nothing checks that the file still matches what the
 * application expects.
 *
 * SQLite goes through the framework's own SchemaState, which already knows how
 * to tell an in-memory database from a file. Everything else goes through
 * DumpClient, which exists because the framework's version does not — see its
 * docblock for what that buys and what it gives up.
 */
final readonly class SqlDump implements ResetStrategy
{
    /**
     * @param  array<string, mixed>  $options
     */
    public function __construct(
        private DatabaseManager $database,
        private ProcessFactory $process,
        private array $options = [],
    ) {}

    /**
     * Everything that can refuse, refuses before anything is dropped.
     *
     * The order matters more here than in the other strategies. A missing dump
     * or an unrecognised driver discovered after db:wipe leaves the demo with an
     * empty database and no way to refill it — the same failure shape as a
     * seeder that does not exist, which is why demo:doctor checks for that too.
     */
    public function run(ResetContext $context): void
    {
        $path = $this->path();

        if ($path === '' || ! is_readable($path)) {
            throw new DemoModeException(sprintf('The demo baseline dump [%s] cannot be read.', $path));
        }

        $connection = $this->database->connection($context->connection);
        $driver = $connection->getDriverName();
        $client = DumpClient::for($connection, $this->clientBinary());

        if (! $client instanceof DumpClient && $driver !== 'sqlite') {
            throw new DemoModeException(sprintf(
                'The sql-dump strategy does not know how to load a dump into [%s].',
                $driver,
            ));
        }

        /*
         * Checked here as well as in validate(), so the guarantee holds for a
         * programmatic Demo::reset() on an image where the client was never
         * installed, not only for somebody who ran demo:doctor.
         */
        $missing = $driver === 'sqlite' ? [] : $this->missingClient($driver);

        if ($missing !== []) {
            throw new DemoModeException($missing[0]);
        }

        /*
         * Wiping is what makes this a reset. A dump loaded on top of what a
         * visitor left behind restores the seeded rows and keeps theirs.
         */
        $context->report('Dropping every table');
        $context->artisan->call('db:wipe', $context->arguments(['--force' => true]));

        $context->report('Loading the baseline dump');

        $client instanceof DumpClient
            ? $this->load($client, $path)
            : $this->loadThroughTheFramework($connection, $path);
    }

    public function describe(): string
    {
        return sprintf('Load the dump at %s', $this->path() === '' ? '(not configured)' : basename($this->path()));
    }

    /**
     * Whichever half of this fails, it fails before db:wipe.
     *
     * The client check is the one that is easy to leave out and expensive to
     * omit: the usual php:8.4-fpm image has no mysql or psql in it, so without
     * this the first scheduled reset drops every table and then exits 127 with
     * nothing to reload.
     */
    public function validate(): array
    {
        $path = $this->path();

        if ($path === '') {
            return ['No dump path is configured. Set demo.reset.strategies.sql-dump.path.'];
        }

        if (! is_readable($path)) {
            return [sprintf('The demo baseline dump [%s] does not exist or cannot be read.', $path)];
        }

        $connection = $this->database->connection($this->configuredConnection());
        $driver = $connection->getDriverName();

        if ($driver === 'sqlite') {
            return [];
        }

        if (! DumpClient::for($connection, $this->clientBinary()) instanceof DumpClient) {
            return [sprintf('The sql-dump strategy does not know how to load a dump into [%s].', $driver)];
        }

        return $this->missingClient($connection->getDriverName());
    }

    public function seedsCredentials(): bool
    {
        return false;
    }

    /**
     * @return list<string>
     */
    private function missingClient(string $driver): array
    {
        $binary = $this->clientBinary() ?? ($driver === 'pgsql' ? 'psql' : 'mysql');

        if ((new ExecutableFinder)->find($binary) !== null || is_executable($binary)) {
            return [];
        }

        return [sprintf(
            'The database client [%s] is not on the PATH. A reset would drop every table and then fail with nothing to reload.',
            $binary,
        )];
    }

    private function configuredConnection(): ?string
    {
        $connection = $this->options['connection'] ?? null;

        return Options::nullableString($connection);
    }

    private function path(): string
    {
        return Options::string($this->options['path'] ?? null, '');
    }

    private function clientBinary(): ?string
    {
        return Options::nullableString($this->options['client'] ?? null);
    }

    private function load(DumpClient $client, string $path): void
    {
        $dump = fopen($path, 'rb');

        if ($dump === false) {
            throw new DemoModeException(sprintf('The demo baseline dump [%s] could not be opened.', $path));
        }

        try {
            $result = $this->process
                ->newPendingProcess()
                ->env($client->environment)
                ->input($dump)
                ->timeout(Options::integer($this->options['timeout'] ?? null, 900))
                ->run($client->command);
        } finally {
            fclose($dump);
        }

        if ($result->failed()) {
            /*
             * Neither the process exception nor the whole error output. The
             * exception's message prints the command line; the error output is
             * the client quoting the statement that failed, which with
             * ON_ERROR_STOP means psql printing a DETAIL line containing the
             * baseline row that broke — into an exception message, the
             * ResetFailed event, and whatever log aggregator sits behind it.
             *
             * A bounded tail is enough to tell a developer what happened and
             * short enough not to carry a table's worth of anything.
             */
            throw new DemoModeException(sprintf(
                'Loading the baseline dump failed (exit %d): %s',
                $result->exitCode() ?? -1,
                Str::limit(trim($result->errorOutput()), 200) ?: 'the client produced no output',
            ));
        }
    }

    /**
     * SQLite has no client worth depending on, and the framework already knows
     * how to tell an in-memory database from a file on disk. No credentials are
     * involved, so the reason DumpClient exists does not apply here.
     */
    private function loadThroughTheFramework(Connection $connection, string $path): void
    {
        if (! $connection instanceof SQLiteConnection) {
            throw new DemoModeException(sprintf(
                'The sql-dump strategy does not know how to load a dump into [%s].',
                $connection->getDriverName(),
            ));
        }

        /* db:wipe disconnects, and the schema state needs a live handle. */
        $connection->reconnect();

        $connection->getSchemaState()->load($path);
    }
}
