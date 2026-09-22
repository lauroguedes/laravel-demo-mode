# Reset strategies

A strategy is how a demo gets back to its starting state. The package ships four
and you can write your own.

```php
'reset' => ['strategy' => env('DEMO_RESET_STRATEGY', 'migrate-fresh-seed')],
```

Each has its own config block, so `seeder` belongs to `migrate-fresh-seed` and
`path` belongs to `sql-dump` and neither needs a prefix to stay out of the
other's way. Switching is one env var with the other's settings still in place.

## Choosing one

| | When | Cost |
|---|---|---|
| `migrate-fresh-seed` | The default. Nothing to set up. | Slow in proportion to how much data makes the demo look like itself. |
| `snapshot` | A demo with enough data that rebuilding is a visible outage. | One suggested dependency, one baseline to take. |
| `sql-dump` | The baseline is a `.sql` file the project already keeps. | The dump and the migrations can drift apart silently. |
| `callback` | None of the above describes your reset. | Whatever you write. |

Start with the default. Move when a reset takes long enough that visitors watch
the maintenance page.

## Before anything is dropped

Every strategy implements `validate()`, and the Runner calls it **immediately
after the guards and before it takes the lock** — not only `demo:doctor`. A
strategy that says it cannot run stops the reset, and `--force` does not skip it
any more than it skips the guards.

That is the difference between a promise and a barrier. A missing seeder, an
untaken snapshot, a dump that is not there, a database client that is not
installed — each of those, discovered after `migrate:fresh` or `db:wipe`, leaves
the demo with an empty database and nothing to refill it.

## `migrate-fresh-seed`

```php
'migrate-fresh-seed' => [
    'driver'     => Strategies\MigrateFreshSeed::class,
    'seeder'     => \Database\Seeders\DemoSeeder::class,
    'drop_views' => true,
],
```

`migrate:fresh --force` then `db:seed`. Portable everywhere, and the only
strategy that needs no setup at all.

`demo:doctor` errors when the configured seeder does not exist. That check earns
its place: by the time `db:seed` fails, `migrate:fresh` has already run, so the
demo is left with an empty database and nothing to refill it — the failure
arrives after the damage.

## `snapshot`

```php
'snapshot' => [
    'driver' => Strategies\Snapshot::class,
    'name'   => 'demo-baseline',
],
```

```bash
composer require spatie/laravel-db-snapshots

php artisan migrate:fresh --seed   # get the database into the state you want
php artisan demo:snapshot          # capture it
```

The reset becomes one import. A suggested dependency rather than a required one:
most demos never need it, and a package that drops tables should ask for as
little trust as it can. `demo:doctor` errors if you select this strategy without
it, before the first scheduled reset rather than after.

Restores with `--drop-tables`. Without that the snapshot lands on top of what a
visitor left behind, restoring the seeded rows and keeping theirs — which is not
a reset, and is silent.

`validate()` checks the baseline actually exists. spatie's `snapshot:load`
returns quietly when the named snapshot is missing, so without that check a reset
against a missing baseline drops every table and reports success.

**`demo:snapshot` runs the same guard chain as `demo:reset`.** The command looks
harmless and is not: `demo:reset` destroys data, this one *publishes* it. On the
exact scenario `demo.environments` exists for — a demo `.env` copied onto a
production box — refusing to drop the tables while happily copying them all into
the demo's baseline would be the wrong half to guard.

Keep the snapshots disk private. It holds every row a visitor will see.

> **The snapshot is a file containing every row a visitor will see.** Whatever
> your seeder would not have put in the demo, do not put in the snapshot. This is
> the strategy where a dump of production is one careless `demo:snapshot` away.
> The command names the database it is about to read and asks, because the
> mistake is silent and permanent.

## `sql-dump`

```php
'sql-dump' => [
    'driver'  => Strategies\SqlDump::class,
    'path'    => database_path('demo/baseline.sql'),
    'client'  => null,  // 'mysql' or 'psql'; null picks by driver
    'timeout' => 900,
],
```

Wipes the database, then loads the file through the native client, which must be
on the `PATH`. MySQL, MariaDB and PostgreSQL shell out; SQLite is executed
through the driver, because there is no client there worth depending on.

Everything that can refuse, refuses before anything is dropped — a missing dump
or an unrecognised driver discovered after the wipe would leave the demo empty
with nothing to refill it.

Two things about how it shells out:

**The client is invoked with an argument array, never a command string.** Nothing
is interpolated into a shell — not the database name, not the host, not the path.

**The password goes through the environment, not an argument.** Arguments are
world-readable in `ps` for the life of the process, which is why both clients
read `MYSQL_PWD` and `PGPASSWORD` at all.

`psql` is invoked with `--set=ON_ERROR_STOP=1`, without which it reports a failed
statement and still exits zero — a half-loaded dump that looks like success.

Unix sockets and TLS settings are carried across from your connection, so the
dump takes the same route the application does. A connection configured for a
socket that silently became a TCP connection to `127.0.0.1` could be a different
server entirely; one that requires TLS would otherwise send the password and the
whole baseline in the clear.

`validate()` checks the client is on the `PATH`. The usual `php:8.4-fpm` image
has neither `mysql` nor `psql` in it.

Failures report a bounded tail of the client's error output, not the whole of it.
With `ON_ERROR_STOP` the client quotes the statement that failed, which for a
constraint violation means printing the offending row — into an exception
message, the `ResetFailed` event, and whatever log aggregator sits behind it.

## `callback`

```php
'callback' => [
    'driver'            => Strategies\Callback::class,
    'using'             => [App\Demo\RestoreBaseline::class, 'handle'],
    'seeds_credentials' => false,
],
```

The escape hatch. It runs in the same window as the others — destructive Artisan
commands permitted, application in maintenance mode — and its failure unwinds
the same way.

**Write it as a callable string or a `[Class::class, 'method']` pair, not a
Closure.** A Closure in `config/demo.php` makes `php artisan config:cache` fail
outright — *"Your configuration files are not serializable"* — which rules it out
on every deployment that caches config, which is every demo server worth having.
A string is also the form `validate()` can check before the reset rather than
after it.

A callback with nothing configured **throws**. Falling through would have the
Runner record a completed reset, publish credentials and dispatch
`ResetCompleted`, while the demo went on accumulating everything visitors left —
a reset that never happened, reported as success.

## Rotation and the strategy you choose

Rotating passwords only rotate if something re-hashes them, and that something is
your seeder. `snapshot` and `sql-dump` run no seeder, so the account keeps
whatever hash the baseline froze — the login page shows a password that opens
nothing, and the one baked into the baseline keeps working forever. Both silent.

`demo:doctor` treats that combination as an **error**. Either:

```php
'credentials' => ['accounts' => [['email' => '…', 'rotate' => false, 'password' => '…']]],
```

— document the baseline's password and accept that it is permanent — or re-hash
the staged password after the restore from a `ResetCompleted` listener, and set
`seeds_credentials => true` on a callback that does.

## Writing your own

```php
namespace App\Demo;

use LauroGuedes\DemoMode\Contracts\ResetStrategy;
use LauroGuedes\DemoMode\Reset\ResetContext;

final class RestoreFromObjectStorage implements ResetStrategy
{
    public function run(ResetContext $context): void
    {
        $context->report('Restoring the baseline');
        // $context->artisan, $context->connection, $context->option('…')
    }

    public function describe(): string
    {
        return 'Restore the baseline from object storage';
    }

    public function seedsCredentials(): bool
    {
        return false;
    }

    /** @return list<string> */
    public function validate(): array
    {
        return Storage::disk('baselines')->exists('demo.tar')
            ? []
            : ['The baseline archive is missing from the baselines disk.'];
    }
}
```

```php
'strategies' => [
    'object-storage' => ['driver' => App\Demo\RestoreFromObjectStorage::class],
],
```

`validate()` is the part worth writing carefully. The Runner calls it before the
guards release, so it is what turns a reset that fails after dropping the tables
into a refusal before anything is dropped at all. Anything answerable without
side effects belongs there.

`seedsCredentials()` says whether your strategy re-hashes the published password.
Answer `false` unless it runs something that does.

## Trying one before committing to it

```bash
php artisan demo:reset --dry-run
php artisan demo:reset --strategy=sql-dump
```

`--dry-run` prints the plan without touching anything. `--strategy` overrides the
configured one for a single run.
