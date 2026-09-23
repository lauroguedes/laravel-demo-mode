<?php

declare(strict_types=1);

use Illuminate\Database\DatabaseManager;
use LauroGuedes\DemoMode\Exceptions\DemoWriteProhibited;
use LauroGuedes\DemoMode\Guards\ConnectionGuard;

beforeEach(function (): void {
    demo();
});

function inspect(string $query, array $except = []): void
{
    app(ConnectionGuard::class)->inspect($query, $except);
}

it('lets a read through', function (string $query): void {
    inspect($query);

    expect(true)->toBeTrue();
})->with([
    'select * from users',
    '  SELECT 1',
    'with recent as (select 1) select * from recent',
    'show tables',
    'explain select * from users',
    'pragma foreign_keys',
    'set names utf8mb4',
]);

it('refuses a write', function (string $query): void {
    expect(fn (): mixed => inspect($query))->toThrow(DemoWriteProhibited::class);
})->with([
    "insert into users (email) values ('a@b.c')",
    'update users set email = ?',
    'delete from users where id = 1',
    'truncate table users',
    'drop table users',
    'alter table users add column x int',
]);

/**
 * Database-backed sessions, cache and queues write on ordinary requests. Every
 * one of them has to be on the list before the demo can serve a page, which is
 * why this guard is off by default.
 */
it('lets the tables the framework writes to on every request through', function (string $table): void {
    inspect("insert into {$table} (id) values (1)", ['sessions', 'cache', 'jobs']);

    expect(true)->toBeTrue();
})->with(['sessions', 'cache', 'jobs']);

it('reads through a schema prefix', function (): void {
    inspect('insert into public.sessions (id) values (1)', ['sessions']);

    expect(true)->toBeTrue();
});

it('reads through quoting', function (string $query): void {
    inspect($query, ['sessions']);

    expect(true)->toBeTrue();
})->with([
    'insert into "sessions" (id) values (1)',
    'insert into `sessions` (id) values (1)',
    'INSERT INTO sessions (id) VALUES (1)',
    'insert ignore into sessions (id) values (1)',
    'replace into sessions (id) values (1)',
]);

/**
 * PostgreSQL lets a common table expression write and return its rows. The
 * statement begins with the word 'with', which was on the read list, so this
 * deleted a table while reading as a select.
 */
it('refuses a write hidden inside a common table expression', function (string $query): void {
    expect(fn (): mixed => inspect($query))->toThrow(DemoWriteProhibited::class);
})->with([
    'with gone as (delete from users returning *) select * from gone',
    'WITH moved AS (UPDATE users SET email = ? RETURNING *) SELECT * FROM moved',
    'with added as (insert into users (email) values (?) returning *) select * from added',
]);

/**
 * A verb this guard has not been taught is a verb nothing can vouch for, and a
 * quoting style it cannot parse gives no table to check. Both refuse, so the
 * worst an unrecognised statement can do is block a write, never permit one.
 */
it('refuses what it cannot read rather than allowing it', function (): void {
    expect(fn (): mixed => inspect('lock tables users write', ['sessions']))
        ->toThrow(DemoWriteProhibited::class);
});

it('registers nothing when it is switched off', function (): void {
    demo(['demo.guards.connection.enabled' => false]);

    app(ConnectionGuard::class)->register(app(DatabaseManager::class));

    /* The proof is that an ordinary write still works. */
    DB::statement('create table guard_probe (id integer)');
    DB::table('guard_probe')->insert(['id' => 1]);

    expect(DB::table('guard_probe')->count())->toBe(1);

    DB::statement('drop table guard_probe');
});

/**
 * The layer only matters if it is actually attached to the connection — the
 * tests above exercise the decision, this one exercises the wiring.
 */
it('blocks a real write once it is registered on the connection', function (): void {
    DB::statement('create table guard_probe (id integer)');

    demo(['demo.guards.connection.enabled' => true, 'demo.guards.connection.except_tables' => ['sessions']]);

    app(ConnectionGuard::class)->register(app(DatabaseManager::class));

    expect(fn (): mixed => DB::table('guard_probe')->insert(['id' => 1]))
        ->toThrow(DemoWriteProhibited::class);

    /* Reads keep working, which is the whole point of read-only. */
    expect(DB::table('guard_probe')->count())->toBe(0);
});

/**
 * Connection::unprepared() runs more than one statement, so a leading select is
 * not a promise about what follows it.
 */
it('refuses a write hidden behind a leading read', function (string $query): void {
    expect(fn (): mixed => inspect($query))->toThrow(DemoWriteProhibited::class);
})->with([
    'select 1; delete from users',
    'select 1;
     update users set email = ?',
]);

/**
 * And an ordinary select is not scanned, because 'updated_at' is everywhere and
 * a guard that blocked every query mentioning it would be useless rather than
 * strict.
 */
it('does not block a read that merely contains a write word', function (string $query): void {
    inspect($query);

    expect(true)->toBeTrue();
})->with([
    'select updated_at from users',
    'select * from users order by updated_at desc',
    'select id, created_at, deleted_at from users',
]);
