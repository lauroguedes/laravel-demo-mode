# Changelog

All notable changes to `laravel-demo-mode` will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

First release. Everything below is new.

### The core

- `Demo::enabled()` as the single point of truth, with `when()`/`unless()`,
  `@demo`/`@notdemo`, and one payload for Blade, Livewire and Inertia
- `demo:reset` behind six independent barriers, of which `--force` lifts exactly
  one: the interactive confirmation
- `demo:doctor`, which exits non-zero on anything that would destroy data or
  publish a secret, and is the reason to install this rather than write it
- `demo:install`, `demo:status`, `demo:credentials`, `demo:snapshot`,
  `demo:sandbox:prune`
- Scheduling registered by the package, from the same cron expression the banner
  counts down from

### Rebuilding

- Four reset strategies: `migrate-fresh-seed`, `snapshot`, `sql-dump`, `callback`
- Five cleaners, because resetting the database is not resetting the application
- Rotating published credentials, on a private disk or in the cache

### Keeping a stranger from breaking it

- Four restrictions, and a contract for your own
- Three write guards: protected records, read-only HTTP, and the connection
- An optional visitor-triggered reset, throttled, cooled down, queued and
  host-checked
- Optional per-visitor isolation

### Zero cost when off

With `DEMO_MODE=false` the package registers no middleware, no listener, no route,
no schedule, and not the reset command either.
