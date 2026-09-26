---
name: demo-mode-development
description: Set up and work with lauroguedes/laravel-demo-mode — reset strategies, per-visitor isolation, cleaners, restrictions, write guards, the on-demand reset button and the frontend payload.
---

# Laravel Demo Mode development

## When to use this skill

Use it when configuring `config/demo.php` beyond the flag, choosing or writing a
reset strategy, isolating visitors from each other, wiring the notice into a
frontend, or diagnosing a demo that is not behaving.

Always finish by running `php artisan demo:doctor`. It is the only thing that
catches this package's silent misconfigurations, and it exits non-zero on
anything that would destroy data or publish a secret.

## Rebuilding the data

Four strategies, selected by `DEMO_RESET_STRATEGY`:

| Strategy | When |
|---|---|
| `migrate-fresh-seed` | The default. Nothing to set up. Slow in proportion to the data. |
| `snapshot` | Restores a `spatie/laravel-db-snapshots` baseline. Needs that package. |
| `sql-dump` | Restores a dump through `mysql` or `psql`. Fastest on large data. |
| `callback` | Your own closure, for anything the other three cannot express. |

Each has its own block under `demo.reset.strategies`, so switching is one env var.
The `seeder` key lives in the `migrate-fresh-seed` block and names
`Database\Seeders\DemoSeeder` by default. If the project seeds its demo from an
existing seeder, repoint that key rather than duplicating the seeder — and check
the seeder reads `Demo::passwordFor()`.

## Per-visitor isolation

`demo.sandbox.driver` is `shared` by default: everybody sees the same data. The
`scoped` driver gives each visitor the seeded baseline plus their own rows.

**It is not multi-tenancy and has not been audited as a security boundary.** It
keeps ordinary visitors out of each other's way. Never suggest it for separating
data that would matter if the wrong person saw it.

It needs three things, and a model that is missing any of them leaks rows between
visitors while the rest of the demo looks isolated — the one way this feature
fails silently:

1. The table: `php artisan vendor:publish --tag=demo-migrations && php artisan migrate`
2. A `demo_sandbox_id` nullable indexed string column on every marked table
3. The `BelongsToSandbox` trait on those models, and the models listed in
   `demo.sandbox.models` so `demo:doctor` can check each one actually carries it

```php
use LauroGuedes\DemoMode\Sandbox\BelongsToSandbox;

class Post extends Model
{
    use BelongsToSandbox;
}
```

Rows the seeder creates carry no sandbox id, so they belong to everybody — that is
what makes a scoped demo look populated rather than empty. `Post::withoutSandbox()`
reads across every visitor and should be a deliberate act.

Append the `demo.sandbox` middleware to the web group so a sandbox expires an hour
after its visitor stops rather than an hour after it was created. The alias is
registered on every installation, demo or not, so the line is safe to leave there
permanently.

Two consequences of sandboxing an authentication model: `unique:users,email` is
query-builder validation and sees every sandbox, so two visitors cannot register
the same address; and logging out does not end a sandbox.

## Letting visitors reset it

`demo.on_demand.enabled` is off by default — it puts a `migrate:fresh` behind an
HTTP request. When on, `demo.on_demand.scope` decides what the button does:
`auto` (the default) clears the visitor's own rows on a scoped demo and rebuilds
everything on a shared one. Do not set `scope` to `sandbox` on a shared demo; the
button would delete nothing and report success. `demo:doctor` errors on that.

Throttle, cooldown, queue and host checks all layer in front of it. The cooldown
is the one that holds, because it counts resets rather than requesters.

## Cleaners and restrictions

Resetting the database is not resetting the application: sessions, cache, uploaded
files and queued jobs all outlive it. Cleaners run after the strategy, in config
order. `FlushStorage` and `FlushQueue` do nothing until you name disks and queues.
Keep `demo-mode:*` in `FlushCache`'s `except` list or the reset erases the password
it just published.

Restrictions apply during boot while the flag is on: mail and notifications
disabled, privileged accounts blocked from signing in, config keys pinned.

Write guards stop one visitor locking the next one out. Put the published account
in `demo.guards.protected` so nobody can change its email or password.

## The frontend

One payload for every stack:

```php
Demo::toArray();   // Blade, Livewire and Inertia
```

For Inertia, return it from `HandleInertiaRequests::share()`. For Blade and
Livewire, the optional `ShareDemoState` middleware puts it in every view.

The `pill` style renders inside a shadow root and styles itself; `bare` is
semantic markup wearing the class names from `banner.classes`. Everything visible
about the notice reads from the environment (`DEMO_BANNER_*`), so a demo can be
dressed without a deployment.

## Diagnosing

- `php artisan demo:doctor` — configuration audit, non-zero on anything dangerous
- `php artisan demo:status` — what this installation currently is, including
  `Last reset`, which is the only number that proves a reset actually happened
- `php artisan demo:reset --dry-run` — prints the plan without touching anything
