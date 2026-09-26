# Installation

## Requirements

| | |
|---|---|
| PHP | 8.3, 8.4 or 8.5 |
| Laravel | 13.0 and later |

## Install

```bash
composer require lauroguedes/laravel-demo-mode
php artisan demo:install
```

`demo:install` publishes `config/demo.php`, appends the `DEMO_` keys to
`.env.example`, and offers to write `database/seeders/DemoSeeder.php`.

Everything it does is additive. It does not edit `.env`, and it does not set
`DEMO_MODE=true`. Turning an installation into a public playground is something
you do to a deployment on purpose.

### The two questions it asks

```
 ┌ Should every visitor see the same data? ──────────────────────┐
 │ › ● Shared — one dataset, everybody pokes at the same thing   │
 │   ○ Scoped — each visitor gets the baseline plus their own    │
 └───────────────────────────────────────────────────────────────┘

 ┌ Start a DemoSeeder for the demonstration data? ───────────────┐
 │ Yes, write me a stub / No, I already have a seeder for this   │
 └───────────────────────────────────────────────────────────────┘
```

Everything else about a demo has a sensible default or can be changed with one
env var. These two cannot.

**Isolation.** `scoped` needs a table, a column on every model you mark and a
trait on each of them, so it is asked while you are paying attention rather than
left in a config comment. Answer **shared** if you are not sure — it is the
default and it costs nothing. See [Per-visitor isolation](sandbox.md) for what
the other one buys. Choosing `scoped` publishes the `demo_sandboxes` migration
and ends on a longer checklist.

**The seeder.** Plenty of projects already have a seeder shaped like a
demonstration — the demo is often a dressed-up version of the local fixtures — and
do not want a second empty one. Say no and the installer writes nothing, but the
config it publishes still names `Database\Seeders\DemoSeeder`, so the closing
checklist tells you which key to repoint and why the published password has to be
read from `Demo::passwordFor()` rather than hardcoded. The question is skipped
entirely when a `DemoSeeder.php` is already there.

`demo:doctor` errors on every step of either checklist you have not done,
including a seeder class named in config that does not exist.

```bash
# For a script, or any run with nobody at the keyboard.
php artisan demo:install --sandbox=scoped --without-seeder
```

A run with `--no-interaction` takes `shared` and does write the seeder — what this
command did before it asked anything. A deploy script that blocks on a prompt is a
broken deploy script.

## Nothing is a demo yet

With `DEMO_MODE=false` — the default — the package registers nothing. No
middleware, no listener, no route, no schedule, and not even the `demo:reset`
command. The cost of having it installed and switched off is one boolean.

That is also the fail-safe: a copy of this application that never declared itself
a demo has no command that can drop its tables.

## What to do next

- [Quickstart](quickstart.md) — a working demo in five minutes
- [Security](security.md) — read this one before deploying anything
