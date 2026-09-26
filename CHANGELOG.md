# Changelog

All notable changes to `laravel-demo-mode` will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## 1.6.1 - 2026-09-26

### Fixed

- **`demo:credentials --rotate` said it retired a leaked password. It does not.**
  Rotating generates a password and writes it to the store the login page reads,
  and stops there — nothing in this package hashes a password into an account,
  because it cannot know which model or column that is. Your seeder does it,
  reading `Demo::passwordFor()`, and your seeder runs during a reset.

  So rotating on its own left every account signing in with the password it was
  seeded with while the login page advertised a different one, and the leaked
  password it was reached for kept working — it just stopped being displayed.
  Three places said otherwise: the command's docblock, the line it prints after
  rotating, and `Demo::rotate()`'s one-line description in the docs. All three
  now say what happens, the command warns every time it rotates, and
  `RotateDoesNotTouchTheAccountTest` asserts both halves.

- **`demo:credentials` offered `--rotate` as the fix for "nothing is published
  yet".** Following that advice produced a login page showing a password that
  opened nothing. It names `demo:reset` alone now.

### Internal

- `freshSchema()` in the test suite lifted no prohibition and asserted nothing, so
  it did nothing at all in any file that ran after one that performed a reset —
  `DestructiveCommands::permitting()` ends on `prohibit(true)` whatever it started
  from, and that is a static on Illuminate's command classes, which outlives the
  application Testbench rebuilds between tests. The first sign was a "no such
  table" in a file that had done nothing wrong.

- `ResetConfirmationTest` covers what a skipped confirmation means: a run with
  nobody to answer and no `--force` refuses, exits non-zero and changes nothing.
  The behaviour was already right and already deliberate; nothing asserted it.

## 1.6.0 - 2026-09-26

### Added

- **AI guidelines and an agent skill for [Laravel Boost](https://laravel.com/docs/13.x/boost).**
  A project that has Boost installed picks both up from this package with no
  configuration — `boost:install` discovers them.

  The guideline is deliberately short, because Boost concatenates every
  guideline into one block that is loaded upfront on every request an agent
  makes. It carries only what an agent gets wrong otherwise: that this package
  drops tables and `DEMO_MODE=true` is not a thing to set on somebody's behalf,
  that `Demo::enabled()` is the single source of truth, that the view components
  already decide for themselves whether to render so wrapping them in `@demo` is
  wrong, that a seeder must read the published password from
  `Demo::passwordFor()` — the mistake that fails silently after the first
  rotation — and that a countdown is arithmetic on a cron expression rather than
  evidence anything runs it.

  The `demo-mode-development` skill carries the rest, loaded only when it is
  relevant: reset strategies, per-visitor isolation and the three things it needs
  to not leak rows, cleaners, restrictions, write guards, the on-demand reset and
  the frontend payload.

- **A `funding` entry in `composer.json`**, so the Packagist page carries the
  link.

## 1.5.1 - 2026-09-26

A review of the whole package and its documentation ahead of publishing. No
behaviour changed; everything here is something that said one thing while the
code did another, or that could not be acted on as written.

### Fixed

- **The README described an installer that no longer exists.** It claimed the
  command "writes a `DemoSeeder` stub" unconditionally and then, two paragraphs
  later, that it asks whether to — and the sentence introducing the questions had
  lost its noun in an earlier edit. Both command tables still described
  `demo:install` as publishing "the config and the seeder stub".
- **The quickstart's way of confirming the schedule runs did not confirm it.**
  `schedule:list` proves the reset is registered; "Next Due: 33 minutes from now"
  is arithmetic on the cron expression and reads identically on a server with no
  cron at all — as does the banner's countdown. It now points at `demo:status`
  and says to compare `Last reset` against the schedule, which is the one number
  that cannot be produced without a reset having happened.
- **`UPGRADE.md` had no section for 1.4 or 1.5**, which is where `demo:install`
  started asking questions — an unattended run that does not pass
  `--no-interaction` now waits for an answer. Its sections were also ordered 1.0,
  1.3, 1.1; they run newest-first now.
- **`FlushTelescope` was documented with no way to switch it on.** It is the one
  cleaner the published config does not list, deliberately, and nothing said so
  or showed the line to add.
- **`PLAN.md` still said "plan approved, nothing implemented"** on a package that
  had been shipping for three days. Marked historical, pointing at the README and
  the changelog for what is actually true.

### Added

- `support` links in `composer.json` — issues, source, docs and the security
  policy — so the Packagist page carries them.
## 1.5.0 - 2026-09-26

### Added

- **`demo:install` asks whether to start a `DemoSeeder`.** It always wrote one,
  and in a project whose existing seeder already builds something demonstrable
  that file is dead weight — it sat unused in the first project to adopt this,
  because the config there pointed at `DatabaseSeeder` instead.

  Saying no writes nothing and ends on a different closing line: which config key
  to repoint, and why the published account's password has to be read from
  `Demo::passwordFor()` rather than hardcoded. That second one is what the stub's
  own comments would have taught, and getting it wrong is silent — the login page
  shows one password and the database holds another from the first rotation
  onwards.

  The question is skipped when a `DemoSeeder.php` is already there. The only
  answer that would change anything is "replace it", `--force` is how you say
  that, and a file that may hold real seed data is not something to be nudged
  into replacing by a prompt. `--without-seeder` answers the question for a
  script, and a run with nobody at the keyboard still writes the stub — which is
  what this command has always done, and what the config it publishes expects.

  What the closing line says is decided by what is on disk when the run ends,
  not by whether this command wrote it. Two booleans could spell a state that
  cannot happen, and one spelling of it did: `--force` plus declining the stub
  reported "not written" and closed by telling you to repoint `demo.reset` away
  from a seeder that was sitting right there and working.

## 1.4.1 - 2026-09-25

### Fixed

- **The spinner orbited instead of turning.** The fix in 1.3.1 replaced the glyph
  and left the real fault in place. `display: inline-flex` and the centring that
  goes with it were on `button`, and the spinner is a `<span class="icon">` — so
  the 15px glyph sat in the top-left corner of the 28px box while the rotation
  pivot stayed at the box's centre, 6.5px away on each axis. It swung around a
  circle of radius 9px, which is what "spinning strangely" was all along.

  The centring moved onto `.icon` itself, and the animation moved onto the `svg`
  rather than the wrapper: an SVG's own centre is its centre whatever the box
  around it does, so the pivot can no longer be wrong. Measured in a browser —
  the glyph's centre now drifts 0px across a full rotation.

## 1.4.0 - 2026-09-25

### Added

- **`demo:install` asks whether visitors share one set of data or get their own.**
  The only decision about a demo that cannot be inferred later and is expensive to
  change afterwards: `scoped` needs a table, a column on every model you mark and a
  trait on each of them. Answering it publishes the `demo_sandboxes` migration and
  ends on that checklist instead of the shared one, so the three things
  `demo:doctor` will error on are in front of you at the moment you are paying
  attention rather than in a config comment.
- **`--sandbox=shared|scoped`** answers the question for a script. A run with
  nobody at the keyboard takes `shared` — a deploy step that blocks on a prompt is
  a broken deploy step — and an unrecognised value fails before anything is
  written rather than being guessed at.
- **`.env.example` gains `DEMO_SANDBOX`**, live when `scoped` was chosen and
  commented when it was not. The checklist still says to set it in the environment
  that serves the demo: `.env.example` is a template, and this installer does not
  touch `.env`.

### Fixed

- **Both middleware aliases are registered on every installation, not only on a
  demo.** `demo.readonly` and `demo.sandbox` are documented as a permanent line in
  `bootstrap/app.php`, and a line there is on every deployment of that application
  — including every one that is not a demo, which is all of them by default and
  every developer's checkout. Laravel resolves an alias it does not know as a class
  name, so following the documentation and setting `DEMO_MODE=false` threw
  `Target class [demo.sandbox] does not exist` on every request. Both middleware
  already did nothing off a demo; only the name was conditional.
- **`demo:doctor` warns when models are marked for isolation nobody switched on.**
  Every scoped check returned early on a driver that was not `scoped`, so
  publishing the migration, adding the column, marking the models and never
  setting `DEMO_SANDBOX=scoped` produced a clean report on a demo where every
  visitor shared everything. A warning rather than an error, because carrying the
  trait and the list and switching isolation on per deployment is the intended
  shape.
- A second `demo:install --sandbox=scoped` no longer publishes a second sandboxes
  migration. The name carries a timestamp, so forcing could never overwrite the
  first one — only add a second migration creating the same table, and the next
  `migrate` would fail on a project whose only mistake was running the installer
  twice.

## 1.3.1 - 2026-09-24

Three things found by watching the bar rather than reading it.

### Fixed

- **The spinner was the reset icon turning.** That icon is a circular arrow with
  a head and a gap, so rotating it reads as a shape tumbling rather than
  something loading. It is a ring and a moving arc now.
- **A countdown that reached zero sat on "0s"** — a clock that had plainly
  stopped. It now says "Rebuilding the demonstration…" and waits for the demo to
  come back, asking for the page until it stops answering 503 and then reloading.
  It gives up after a couple of minutes and reloads anyway.

  Waiting rather than reloading on a timer, because a rebuild that outlasts the
  guess would drop the visitor on the maintenance page, which carries none of
  this and no way to try again — worse than the stuck clock. The first wait is
  jittered, because every visitor's countdown reaches zero on the same second.

  Both styles. The bare banner has its own copy of the countdown, and the first
  pass of this fix reached only the floating bar.

### Changed

- **The cooldown refusal no longer says how long to wait.** "Try again later"
  rather than "try again in 11 minutes": the cooldown is a limit rather than a
  schedule, and a countdown to the next allowed attempt reads as an invitation to
  come back and spend it. `Retry-After` keeps the number, because that header is
  for clients rather than for people.

## 1.3.0 - 2026-09-24

### Added

- **On a scoped demo, the reset button clears the visitor's own rows** rather
  than rebuilding the installation. The scheduler already rebuilds everything on
  a cycle, and a stranger pressing a button should not take everybody else's
  session with it. Instant: no lock, no maintenance mode, no queue and no
  cooldown, because none of those are about a visitor tidying up after
  themselves. The button says "Clear what you created" rather than "Rebuild the
  demonstration", because those are different promises.

  `on_demand.scope` decides — `auto` (the driver picks), `sandbox` or
  `everything`. `on_demand.sandbox_throttle` is the looser limit for it, and
  `SandboxCleared` fires with the id and the row count.

- `demo:doctor` errors when `on_demand.scope` is `sandbox` on a demo that is not
  scoped, because the button then deletes nothing and tells the visitor it
  worked. Not rescued at runtime by falling back: the other meaning of that
  button rebuilds the whole installation, and quietly promoting a typo into that
  would be the worst thing this package could do.

### Changed

- **Pruning an expired sandbox now deletes the rows that belonged to it.** It
  used to delete the sandbox and leave them: carrying an id no live sandbox
  matched, unreachable by every visitor, carried by every scoped query's index,
  and alive until the next full reset. The reason given for that — nothing could
  know which tables an application had marked — stopped being true when
  `sandbox.models` arrived. Set `sandbox.prune_rows` false to go back to it.

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
