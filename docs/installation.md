# Installation

## Requirements

| | |
|---|---|
| PHP | 8.4 or 8.5 |
| Laravel | 13.0 and later |

## Install

```bash
composer require lauroguedes/laravel-demo-mode
php artisan demo:install
```

`demo:install` publishes `config/demo.php`, writes `database/seeders/DemoSeeder.php`
and appends the `DEMO_` keys to `.env.example`.

Everything it does is additive. It does not edit `.env`, and it does not set
`DEMO_MODE=true`. Turning an installation into a public playground is something
you do to a deployment on purpose.

## Nothing is a demo yet

With `DEMO_MODE=false` — the default — the package registers nothing. No
middleware, no listener, no route, no schedule, and not even the `demo:reset`
command. The cost of having it installed and switched off is one boolean.

That is also the fail-safe: a copy of this application that never declared itself
a demo has no command that can drop its tables.

## What to do next

- [Quickstart](quickstart.md) — a working demo in five minutes
- [Security](security.md) — read this one before deploying anything
