# Configuration

Every key in `config/demo.php` has a safe default, and `DEMO_MODE=false` turns
everything off. What follows is the reasoning behind the keys where the default
is a decision rather than an obvious value.

## `enabled`

```php
'enabled' => (bool) env('DEMO_MODE', false),
```

The single point of truth. Read it through `Demo::enabled()` rather than
`config('demo.enabled')` — the package enforces that on itself with an
architecture test, for the reason that both implementations it was extracted from
had the key spread across several files and drifted.

## `environments` and `allowed_hosts`

```php
'environments'  => ['local', 'staging', 'demo'],
'allowed_hosts' => null,
```

Two barriers that are independent of the flag, because a demo `.env` is a file
and files get copied.

`environments` refuses everywhere when empty, which is the safe reading of
"nothing was configured" for a command that drops tables.

`allowed_hosts` is `null` — disabled — by default, because requiring it would
make the package unusable on the machine of anyone whose demo is not deployed
yet. **Set it on anything public.** It is the only barrier that checks what the
internet actually resolves rather than a convention, and it is the one that
survives an `.env` reaching a server whose `APP_ENV` happens to match.

## `reset.schedule`

```php
'schedule' => env('DEMO_RESET_SCHEDULE', '0 */6 * * *'),
```

Accepts a cron expression or one of `hourly`, `daily`, `weekly`, `monthly`,
`every-N-hours`. Parsed once; both the scheduler and `Demo::nextResetAt()` read
the result, which is what stops a banner promising a daily reset on a demo that
rebuilds hourly.

An expression that cannot be parsed is a `demo:doctor` error rather than a
silent fallback — falling back to a default would make the banner lie again.

## `reset.maintenance`

```php
'maintenance' => true,
```

On by default. Without it there is a window, however short, where visitors are
served an application whose tables have been dropped and not yet seeded. Turning
it off trades that window for not showing a maintenance page.

Leaving maintenance mode happens in a `finally` and never throws: a demo stuck
down because the thing that lifts it failed is worse than whatever went wrong.

## `cleaners`

Resetting the database is not resetting the application, and the order matters.

`FlushCache` ships with `'except' => ['demo-mode:*']`. Keep it. With credentials
in the cache and no `except`, every reset publishes a password and immediately
erases it — the demo comes back up, the login page renders, the prefilled
password does not work, and nothing in any log says why. `demo:doctor` treats
that combination as an error.

`FlushStorage` and `FlushQueue` are empty by default. Deleting a disk's whole
root would take your seeded fixtures with it, and clearing a queue the
application shares with something that is not the demo would be the package
overstepping. Name the directories and the queues.

A cleaner that throws is logged and skipped rather than aborting the run. By then
the database is already rebuilt, and refusing to bring the application back up
because a queue was unreachable trades a small mess for an outage.

## `credentials`

```php
'store'             => env('DEMO_CREDENTIALS_STORE', 'file'),
'expose_in_payload' => true,
```

`file` is the default because it survives the cache flush that is part of every
reset, so the ordering hazard above stops being something you have to get right.
Use a **private** disk — `demo:doctor` treats a web-reachable one as an error.

`expose_in_payload` controls whether `Demo::toArray()` carries the password. On
for the usual case of prefilling a login form. Turn it off if that payload is
serialised into every page of a server-rendered application you would rather a
crawler did not read.

`password.symbols` is `false` by default: a visitor who retypes the password
rather than trusting the prefilled field should not be fighting their keyboard
layout. Length carries the strength, and the password only has to survive until
the next reset.

## `restrictions`

Applied at boot, only while the flag is on. A restriction that throws is *not*
caught — unlike a cleaner, a restriction that failed to apply means the demo is
serving without a protection it was configured to have, and booting anyway would
hide that.

Register your own:

```php
LauroGuedes\DemoMode\Restrictions\Pipeline::use(StopChargingCards::class);
```

The package knows to stop mail. It has no idea whether your application also
needs to stop calling a partner API or writing to a bucket somebody pays for.

## `guards.protected`

Empty by default, and the emptiest default in this file that you should probably
change:

```php
'protected' => [
    \App\Models\User::class => ['email' => 'admin@demo.test'],
],
```

A visitor signs in with the published credentials, opens the profile page, and
changes the email. Both are ordinary features working correctly. From that moment
until the next reset the demo's login page shows credentials that do not work and
nobody else can get in — on a six-hour cycle, the demo is down for up to six
hours because one visitor did something entirely reasonable.

## `sandbox.driver`

```php
'driver' => env('DEMO_SANDBOX', 'shared'),
```

`shared` — everyone sees the same data — is the default and the only one with no
cost.

## `guards.read_only` and `guards.connection`

Both off by default, and both documented where they can be explained properly:
[write-guards.md](write-guards.md). The short version is that read-only is a
reasonable thing to turn on and the connection guard is not, unless you know
exactly which tables your application writes to on an ordinary request.

## `on_demand`

Off by default. It puts a `migrate:fresh` behind an HTTP request, so every control
around it matters — see [on-demand-reset.md](on-demand-reset.md).

## `script`

```php
'script' => true,
```

The one small inline script: the ticking countdown, the banner's dismiss button,
and copy-to-clipboard on the credentials component. Set it `false` under a strict
Content-Security-Policy; the values stay on the page and the controls that would
not work are not rendered at all.

## `log`

```php
'log' => ['channel' => env('DEMO_LOG_CHANNEL'), 'blocked_writes' => true],
```

Every blocked write dispatches `WriteBlocked` whatever this says. The log line is
separate because a misconfigured connection guard blocks every request, and a
package that filled your log aggregator by default would be teaching you to turn
the whole thing off.

## Every key, in one place

This file is the reference for the keys whose default is a *decision*. The
published `config/demo.php` documents all of them inline, including the ones that
are obvious, and is worth reading once end to end — it is the only place that
covers every key.
