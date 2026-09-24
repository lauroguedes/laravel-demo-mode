# Changelog

All notable changes to `laravel-demo-mode` will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## 1.2.0 - 2026-09-24

### Added

- **Every visible part of the notice reads from the environment** —
  `DEMO_BANNER`, `DEMO_BANNER_STYLE`, `_VARIANT`, `_LABEL`, `_POSITION`,
  `_DISMISSIBLE`, `_RESET_BUTTON`, `_CTA_LABEL` and `_CTA_URL` — so one image can
  serve staging and a public playground with different values. The allowed
  options for each are written beside the key in `config/demo.php`, and
  `demo:install` appends them to `.env.example` commented out.

  A configuration published before this reads literals, so nothing changes until
  you republish or copy the `env()` calls across.

### Changed

- **Dismissing the notice now lasts for that page and nothing longer.** It was
  remembered in `sessionStorage`, keyed to the next reset — which survives a
  reload and is not cleared with the browser cache, so a visitor who hid it had
  no obvious way to bring it back. The sentence saying the data is temporary is
  the one thing on a demo that should be hard to lose. Both styles changed.

### Fixed

- The pill's call to action is an `<a>`, and the rounding was only on `button`,
  so it sat square inside a rounded bar.

### Documentation

- `docs/sandbox.md` says what the scoped driver does not do: the seeded baseline
  is shared, and a visitor who edits or deletes one of those rows changes it for
  everybody until the next reset. There is no copy-on-write. Now covered by a
  test rather than left implied.

## 1.1.1 - 2026-09-24

No change to the shipped code. `demo:install`'s test published a config into the
test application and never removed it, so a stale copy from before the floating
bar existed shadowed the package's own — which made `BannerTest` pass locally
while failing in CI. The test cleans up after itself now.

## 1.1.0 - 2026-09-24

### Added

- **A floating bar the package styles itself** — `banner.style => 'pill'`. A
  badge, a ticking countdown, an optional rebuild button, an optional link and a
  dismiss control, rendered by a `<demo-mode-bar>` custom element inside a shadow
  root. It looks the same in Blade, Livewire and Inertia without any of them
  passing a class, which is what the bare banner could never do: the notice had
  to be restyled per application, and in a Vue front end reimplemented outright.

  Opt-in for an existing installation, whose published config has no `style` key
  and keeps the bare banner. A fresh `demo:install` gets the pill.

- `banner.label`, `banner.cta` and `banner.reset_button` for what the bar shows,
  and `banner.asset_route` for where its script is served from.
- A route serving that script, registered only for the pill. It is a route rather
  than a published file so it cannot fall a version behind the payload it reads,
  and rather than an inline block so `script-src 'self'` is enough for a strict
  Content-Security-Policy.

### Changed

- The pill says less than the banner — "Resets in 20m" against a full sentence —
  because a pill full of prose is a pill the width of the screen. Both come from
  the translation files.
- `BannerState` owns the bar's projection as well as the Inertia one, rather than
  a third shape being assembled in the view component.

## 1.0.2 - 2026-09-23

### Fixed

- **A demo whose protected account took more than one insert could not rebuild.**
  The protected-record guard skipped creates, so a seeder that inserts the
  account once was fine — but a seeder that creates it and then saves it again to
  verify the address or assign a role was writing to a record that by then
  existed, and the guard refused it. The reset reported the seeding step as done
  and then failed. Any application that creates users through a service class
  rather than a factory does this, which is most of them.

  The reset now stands that guard down for its own duration, the way it already
  did for the connection guard.

### Changed

- Both guards read one `Support\ResetWindow` rather than carrying a static flag
  each, so there is one answer to "is the reset the thing writing" instead of two
  that could drift apart. Laravel's destructive-command prohibition keeps its own
  narrower window on purpose, and `ResetWindow` says why.
- `docs/on-demand-reset.md` explains why an inline reset on a coroutine-based
  Octane worker is a second reason to keep the on-demand reset queued.

## 1.0.1 - 2026-09-23

Everything here came from installing 1.0.0 into a real project rather than a
fresh `laravel/laravel`.

### Fixed

- `demo:install` reported that `.env.example` already had the `DEMO_` keys when
  it only had a similarly named one. The check was a substring search, and
  `SSO_DEMO_MODE` contains `DEMO_MODE`, so the first project to install this got
  no keys and was told it already had them.
- `ShareDemoState` shared the payload into Inertia through a `class_exists()`
  branch — an undeclared dependency on a package this one does not require, on a
  path no test could reach, and a second writer of `demo` for any application
  following the documented `HandleInertiaRequests::share()` line. Removed, which
  is what both `docs/frontend.md` and the class's own docblock already described.
  `README.md` was the one place telling Inertia users otherwise; it now agrees.

### Changed

- The "does any account rotate" question has one owner, `Configuration`, rather
  than the same predicate written out in two doctor checks.

## 1.0.0 - 2026-09-23

First release. Everything below is new. Requires PHP 8.3 and Laravel 13.

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
