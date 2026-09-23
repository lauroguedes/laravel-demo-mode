# Recipe: deploying a demo

Five things that have to be true, and one command that checks most of them.

## 1. `demo:doctor` in the pipeline, before the first reset

```yaml
- run: php artisan demo:doctor
```

Non-zero exit on anything that would destroy data or publish a secret. This is
the whole reason the command exists — running it by hand is running it once.

Put it **after** `config:cache` if you cache config, so it audits what will
actually be loaded.

## 2. The scheduler has to run

```bash
php artisan schedule:list
```

The package registers the reset itself, so there is nothing to add to
`routes/console.php`. What it cannot do is make your cron exist.

```cron
* * * * * cd /srv/demo && php artisan schedule:run >> /dev/null 2>&1
```

A demo whose cron is not running is a demo that never resets while its banner
keeps promising that it will. That is the failure this package was partly written
to fix, so it is worth checking rather than assuming.

## 3. A cache store with real locks

```dotenv
CACHE_STORE=redis        # or file, database, memcached
```

The lock that stops two resets overlapping lives in the cache. `null` grants every
lock to everybody, and `array` keeps them inside one PHP process — so either
leaves two rebuilds free to run against one database, and the on-demand cooldown
never holds either. `demo:doctor` errors on the first and warns on the second.

## 4. A worker, if you turned the reset route on

```dotenv
QUEUE_CONNECTION=redis
DEMO_ON_DEMAND=true
```

A rebuild takes as long as it takes. Queued, the route answers immediately and a
worker does the work; with `QUEUE_CONNECTION=sync` it runs inside the request and
the visitor waits for all of it. `demo:doctor` warns about that combination.

## 5. `config:cache` and the callback strategy

```php
'callback' => ['using' => [App\Demo\RestoreBaseline::class, 'handle']],
```

A Closure in `config/demo.php` makes `php artisan config:cache` fail outright:

```
Your configuration files could not be serialized because the value at
"demo.reset.strategies.callback.using" is non-serializable.
```

Use a callable string or a `[Class::class, 'method']` pair. Laravel names the
offending key, so if you hit this the message tells you exactly which one.

## Containers

**The reset runs in a separate process.** The scheduler uses `runInBackground()`,
so whatever runs `schedule:run` needs the same database, cache and storage as the
web container. A demo where the scheduler cannot see the web container's storage
disk resets the database and leaves the uploads.

**`onOneServer()` needs a shared cache.** Scaled to more than one replica, the
reset relies on the cache lock to pick one — which is point 3 again.

**Maintenance mode is a file.** On more than one replica, `php artisan down` in the
reset's container does not take the others offline. Either run the demo on one
replica, or set `reset.maintenance => false` and accept the window.

## Where the credentials live

```php
'credentials' => ['stores' => ['file' => ['disk' => 'local']]],
```

A private disk. `local` is not web-reachable by Laravel's own convention;
`public` is. `demo:doctor` treats a disk with a URL, public visibility, or a root
inside `public/` as an **error**, because the failure is a working administrator
password at a guessable address — and it stays served after `DEMO_MODE` goes off,
since the flag governs what this package reads, not what your web server hands out.

On an ephemeral filesystem the file disappears with the container. That is fine:
the next reset publishes a new one. If your demo restarts more often than it
resets, use the `cache` store instead and keep `demo-mode:*` in `FlushCache`'s
`except` list.

## A reasonable set of variables

```dotenv
APP_ENV=demo
APP_URL=https://demo.example.com
APP_DEBUG=false

DB_DATABASE=acme_demo

CACHE_STORE=redis
QUEUE_CONNECTION=redis
SESSION_DRIVER=redis

DEMO_MODE=true
DEMO_RESET_SCHEDULE="0 */6 * * *"
DEMO_CREDENTIALS_STORE=file
DEMO_EMAIL=admin@demo.test
```

`APP_DEBUG=false` matters more here than usual: a debug page on a demo shows a
stranger your configuration, including the values this package went to some
trouble to keep out of exception messages.
