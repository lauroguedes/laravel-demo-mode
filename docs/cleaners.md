# Cleaners

Resetting the database is not resetting the application.

A session store outlives the users table, so a visitor stays signed in as an id
that now belongs to somebody else. A cache outlives the settings row it came
from, so the server keeps wearing whatever the last visitor configured. Uploaded
files outlive the records that referenced them and accumulate until a disk fills
— and a file one visitor uploaded is still served by URL to the next.

Cleaners run after the strategy, in the order the config lists them, with the
application still in maintenance mode and destructive commands already
prohibited again.

## What ships

| Cleaner | Default | What it does |
|---|---|---|
| `FlushSessions` | on | Signs every visitor out. Handles file, database and cache-backed stores the way Laravel's own `SessionManager` resolves them. |
| `FlushCache` | on | Empties the cache, keeping the keys named in `except`. |
| `FlushStorage` | on, empty | Deletes named directories per disk and recreates them. |
| `FlushQueue` | on, empty | Clears named queues. |
| `FlushTelescope` | off, and not in the published config | Empties Telescope entries. |

## Turning on `FlushTelescope`

It is the one cleaner the published `config/demo.php` does not list, so adding it
is the whole of switching it on:

```php
'cleaners' => [
    // ...
    Cleaners\FlushTelescope::class => [],
],
```

Left out rather than shipped-and-disabled because an application running
Telescope on its demo may be doing so precisely to watch the demo, and deleting
that on every reset is not a decision this package should make for you.

Worth making yourself, though, if nobody is watching: Telescope records every
request a stranger made, with their input, their headers and the queries those
produced. Keeping it across a reset means the demo quietly accumulates a log of
what visitors typed. The cleaner does nothing when Telescope is not installed,
and nothing when it is installed but not migrated.

## Why two of them are empty by default

`FlushStorage` and `FlushQueue` do nothing until you name things. Deleting a
disk's whole root would take your seeded fixtures with it, and clearing a queue
the application shares with something that is not the demo would be the package
overstepping.

```php
Cleaners\FlushStorage::class => [
    'disks' => [
        'public' => ['avatars', 'attachments'],
        'local'  => ['livewire-tmp'],
    ],
],

Cleaners\FlushQueue::class => ['queues' => ['default']],
```

## The `except` list is not optional

```php
Cleaners\FlushCache::class => ['tags' => [], 'except' => ['demo-mode:*']],
```

The package keeps two keys in the cache: the published credentials (when the
cache store is selected) and the timestamp of the last reset. Remove `demo-mode:*`
with the cache credential store in use and every reset publishes a password and
then erases it — the demo comes back up, the login page renders, and the
prefilled password does not work, with nothing in any log to say why.
`demo:doctor` treats that combination as an error.

Preservation works by reading the keys back before the flush and writing them
after it. A store's `flush()` is the only operation every driver implements, and
a demo holds few enough of these keys that the round trip is free.

## A cleaner that fails does not stop the reset

By the time cleaners run, the database is already rebuilt. Refusing to bring the
application back up because a queue connection was unreachable would trade a
small mess — some stale jobs — for an outage on a server whose entire purpose is
being reachable.

The failure is never silent: it is logged, and it appears in the report as the
step that did not happen.

This is the opposite of how a [restriction](restrictions.md) behaves, and
deliberately so.

## Writing your own

```php
namespace App\Demo;

use LauroGuedes\DemoMode\Contracts\Cleaner;

final class FlushSearchIndex implements Cleaner
{
    public function clean(array $options): void
    {
        Artisan::call('scout:flush', ['model' => Post::class]);
    }

    public function describe(): string
    {
        return 'Rebuild the search index';
    }
}
```

```php
'cleaners' => [
    // …
    App\Demo\FlushSearchIndex::class => [],
],
```

Order matters — they run top to bottom.

## Redis and sessions

A cache-backed session store is emptied with that store's own `flush()`, and
Redis implements `flush()` as `FLUSHDB`. If sessions and cache share a
connection, this empties both. On a demo that is usually what you wanted anyway,
but set `session.connection` to a separate database if anything else lives there.
