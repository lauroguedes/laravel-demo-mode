<?php

declare(strict_types=1);

namespace LauroGuedes\DemoMode\Guards;

use Illuminate\Database\Connection;
use Illuminate\Database\DatabaseManager;
use LauroGuedes\DemoMode\Configuration;
use LauroGuedes\DemoMode\Exceptions\DemoWriteProhibited;
use LauroGuedes\DemoMode\Support\ResetWindow;

/**
 * The last layer, and the one to reach for last.
 *
 * Rejects anything that is not a read at the connection itself, which is the
 * only place a write cannot be routed around — no controller, no job, no console
 * command, no raw DB::statement gets past it.
 *
 * It is off by default and documented as dangerous because the false positives
 * are not edge cases, they are the framework working normally. Database-backed
 * sessions write on every request. So do the cache, the queue, job batches,
 * failed jobs, and anything else an application keeps in a table because that is
 * where Laravel puts it by default. Every one of those has to be on the
 * exception list before the demo can serve a page, and a demo that half-works
 * because one of them is missing is worse than a demo with no connection guard.
 *
 * Use the read-only middleware and the model guard first. This is for the case
 * where those are not enough and you know exactly which tables move.
 *
 * One thing it must not do is stop the demo rebuilding itself. A reset drops and
 * recreates every application table, none of which is ever on the exception list
 * — so with this guard on and nothing to lift it, the first statement of every
 * reset was refused and the demo could never rebuild again. Nothing announced
 * that: the scheduler failed quietly every six hours. It stands down inside
 * Support\ResetWindow, which the Runner opens for the whole rebuild.
 */
final readonly class ConnectionGuard
{
    /**
     * Statements that read. Everything else is treated as a write, which is the
     * fail-closed reading: a verb this list has not been taught is a verb
     * nothing here can vouch for.
     */
    private const array READS = ['select', 'show', 'describe', 'desc', 'explain', 'pragma', 'set'];

    /**
     * A CTE is not automatically a read.
     *
     * PostgreSQL lets one write inside a common table expression and return its
     * rows — "with x as (delete from users returning *) select * from x" begins
     * with the word 'with' and deletes the table. The same problem arrives by a
     * second door: a multi-statement query whose first verb is a read, as
     * Connection::unprepared() permits. Both are handled by looking past the
     * leading verb for any of these.
     */
    private const array WRITE_KEYWORDS = ['insert', 'update', 'delete', 'merge', 'truncate', 'replace', 'drop', 'alter', 'create'];

    public function __construct(
        private Configuration $config,
        private Blocker $blocker,
    ) {}

    public function register(DatabaseManager $database): void
    {
        if (! $this->config->boolean('guards.connection.enabled')) {
            return;
        }

        $except = $this->config->strings('guards.connection.except_tables');

        $database->connection()->beforeExecuting(
            function (string $query, array $bindings, Connection $connection) use ($except): void {
                $this->inspect($query, $except);
            },
        );
    }

    /**
     * @param  list<string>  $except
     */
    public function inspect(string $query, array $except): void
    {
        if (ResetWindow::isOpen()) {
            return;
        }

        $verb = mb_strtolower((string) strtok(mb_ltrim($query), " \t\n\r("));

        /*
         * A leading read verb is not enough on its own. A common table
         * expression can write, and a multi-statement query can hide a write
         * behind a leading select, so anything that could carry one is read
         * through rather than trusted at its first word.
         */
        if (in_array($verb, self::READS, true) || $verb === 'with') {
            if (! $this->mightWriteLater($verb, $query)) {
                return;
            }
        }

        $table = $this->tableIn($query);

        if ($table !== null && in_array($table, $except, true)) {
            return;
        }

        $subject = $table ?? $verb;

        $this->blocker->blocked('connection', $subject, 'the demonstration is read-only at the connection');

        throw DemoWriteProhibited::readOnly();
    }

    /**
     * Whether a statement that begins like a read might still write.
     *
     * Only asked of the two shapes that can: a common table expression, and a
     * query carrying more than one statement. An ordinary select is not scanned,
     * because it very often contains the word 'update' — a column named
     * updated_at, a subquery aliased 'updates' — and blocking every one of those
     * would make the guard useless rather than strict.
     *
     * Deliberately blunt where it does apply: any write keyword anywhere blocks
     * the statement. Refusing a read is the direction this guard is allowed to be
     * wrong in.
     */
    private function mightWriteLater(string $verb, string $query): bool
    {
        if ($verb !== 'with' && ! str_contains($query, ';')) {
            return false;
        }

        return preg_match('/\b(?:'.implode('|', self::WRITE_KEYWORDS).')\s/i', $query) === 1;
    }

    /**
     * The table a write is aimed at, as far as a regular expression can tell.
     *
     * Deliberately crude, and safe in the direction that matters: a statement
     * whose table cannot be read is blocked rather than allowed, so the worst a
     * quoting style this does not recognise can do is refuse a write, never
     * permit one.
     */
    private function tableIn(string $query): ?string
    {
        $matched = preg_match(
            '/^\s*(?:insert\s+(?:ignore\s+)?into|update|delete\s+from|truncate(?:\s+table)?|replace\s+into)\s+["`\[]?([A-Za-z0-9_.]+)["`\]]?/i',
            $query,
            $matches,
        );

        if ($matched !== 1) {
            return null;
        }

        $table = $matches[1];

        /* Strip a schema or database prefix: "public.sessions" is "sessions". */
        $parts = explode('.', $table);

        return end($parts) ?: null;
    }
}
