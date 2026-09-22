# laravel-demo-mode — Development Plan

Derived from `laravel-demo-mode — Especificação de Desenvolvimento` (2026-09-20), which
stays the source of truth for *what* the package does. This document records what was
decided since, where it departs from the spec and why, the security architecture, and the
order the work happens in.

Written in English because the repository is — README, docs, code comments and commit
messages all are. The spec stays in pt-BR in Obsidian.

**Status:** plan approved, nothing implemented.
**Date:** 2026-09-22

---

## 1. Decisions locked

| | Spec said | Now | Why |
|---|---|---|---|
| Laravel | `^11 \|\| ^12 \|\| ^13` | **`^13.0`** | One CI axis instead of three. Both dogfooding projects already run 13.26. No version-guarded branches in the scheduler or maintenance-mode paths. The spec sized the honest market at ~800 downloads/month — this is built for its author first, and reaching back two majors buys adoption that was never going to arrive. |
| PHP | `^8.2` | **`^8.4`** | Follows from Laravel 13. Property hooks and asymmetric visibility are genuinely useful for the config-backed value objects (`ResetReport`, `ResetContext`, `Sandbox`). |
| Skeleton | — | Spatie layout, **no `spatie/laravel-package-tools`** | Zero runtime dependencies beyond `illuminate/*`. A package whose entire pitch is "safe to install on a server you care about" should not ask you to trust a second vendor. Also makes the "zero cost when disabled" early-return trivial to express — package-tools wants to register everything before you can opt out. |
| Name | open | **`lauroguedes/laravel-demo-mode`** | `spatie/laravel-demo-mode` is archived and marked replaced by `laravel/framework`. The name is free and it is what people search for. `laravel-playground` describes less and would have to earn its own discovery. |
| Namespace | `LauroGuedes\DemoMode` | unchanged | |
| Facade | `Demo` | unchanged | |

**Known friction:** `laravel-sso` declares `"php": "^8.3"`. Installing this package bumps its
floor to 8.4. It runs 8.5 locally, so this is a one-line change in its `composer.json`,
not a migration.

---

## 2. What changes from the spec

Three substantive departures. Everything else in the spec stands as written.

### 2.1 Guards ship with the first destructive line of code, not in 0.2

The spec's 0.1 is "core + reset (`MigrateFreshSeed` only)" and 0.2 is "reset security: all
six guards, lock, maintenance, doctor". That ordering means there is a tagged, installable
`0.1.0` that can run `migrate:fresh` with one guard in front of it — and `0.1` releases get
installed.

**0.1 and 0.2 merge.** The first tag that contains a code path capable of dropping a table
contains all six guards, the lock, maintenance mode and `demo:doctor`. Nothing destructive
is written before the guard it needs exists. In practice this means the `Runner` is built
guards-first: the guard chain, its tests, and a `ResetStrategy` test double land *before*
`MigrateFreshSeed` does.

### 2.2 `AllowDestructiveCommands` wraps the strategy call only, not the run

Reading the framework: `DB::prohibitDestructiveCommands()` sets a **static flag on five
command classes** (`FreshCommand`, `RefreshCommand`, `ResetCommand`, `RollbackCommand`,
`WipeCommand` — `Illuminate\Support\Facades\DB:138`). It is process-global state with no
per-call scope.

Two consequences the spec's step 5 implies but should state:

1. The window must wrap the `$strategy->run()` call and nothing else — not steps 3–10.
   Cleaners, credential rotation and the maintenance-mode exit all run with the
   prohibition back on. A `finally` immediately around the strategy call restores the
   prior value, whatever it was.
2. Because the scheduled reset uses `runInBackground()`, it is a **separate process** that
   boots the app fresh. It cannot inherit an unprohibited state from anywhere; it has to
   flip the flag itself. This is what makes it correct to remove
   `ProhibitDestructiveCommands => ! demo` from `mary-ui-starter-kit`'s
   `config/essentials.php` — the app-level prohibition can stay on permanently, and the
   only thing that ever lowers it is the Runner, for the duration of one method call.

### 2.3 `ForceConfig` swaps the config repository

The spec left this open ("does it really need to prevent runtime overwrite, or is setting
it at boot enough?"). Answer: setting it at boot is not enough for the `laravel-sso` case,
because that app has a settings UI that writes to `config()` at request time, and a
visitor reaching that UI could re-enable email verification and lock the next visitor out.

Mechanism: bind a `PinnedConfigRepository extends Illuminate\Config\Repository` over
`config` when `ForceConfig` applies, whose `set()` is a no-op for pinned keys (logged once
per key per request when `log.blocked_writes`). Registered in `register()`, before anything
reads config. This generalises: it is the same class whether the app pins one key or
twenty, and it costs nothing when the restriction is not applied.

---

## 3. Security architecture

The spec's six reset guards and `demo:doctor` are the backbone and are adopted verbatim.
What follows is what a read of the spec against the two existing implementations turned up
as missing.

### 3.1 The regression to never reproduce

`mary-ui-starter-kit`'s current command:

```php
if (! config('app.demo.enabled') && ! $this->option('force')) { return self::FAILURE; }
```

`--force` bypasses the "is this a demo" check entirely. `php artisan demo:reset --force` on
any installation with the package present drops every table. The spec already says `--force`
does not bypass guards 1, 2 and 6; this is why, and it gets a named test —
`test_force_does_not_bypass_the_enabled_guard` — that fails loudly if anyone ever
"simplifies" the flag handling.

`--force` means exactly one thing: skip the interactive confirmation. Nothing else.

### 3.2 Credentials are a deliberate secret leak and must be bounded

The package publishes a working password on a login page. That is the point, and it is also
the single largest thing that can go wrong.

- **Store location is a doctor error, not a warning.** If the `file` store resolves to a
  disk whose root is inside `public/`, or to a disk with a public URL,
  `demo:doctor` exits non-zero. `laravel-sso` gets this right by using `local`; the check
  exists so the next app does too.
- **Never in logs, never in exceptions.** The password never appears in an exception
  message, a `ResetReport`, a log line, or a `context()` array. `ResetReport` carries
  `credentials_rotated: int`, not the credentials. Enforced by an arch test asserting no
  `Log::`/`report()`/`throw` call site in `src/Credentials/` receives the password value.
- **Opt-in in the shared payload.** `Demo::toArray()` includes `credentials` only when
  `credentials.expose_in_payload` is true (default **true**, because that is the use case,
  but it is a key you can see and turn off — an Inertia payload is in the page source of
  every response, including ones served to a crawler).
- **Reads are gated on the flag, always.** Carried over from `laravel-sso`'s `DemoMode`:
  turning `DEMO_MODE=false` makes a leftover `demo-credentials.json` inert. This is what
  makes the failure mode of "demo server got promoted to production" survivable.
- **Rotation is the actual control.** A published password that never changes is a
  permanent fact of the internet. `demo:doctor` warns when no account has `rotate => true`.

### 3.3 The on-demand reset route is a denial-of-service primitive

`POST /demo/reset` hands an anonymous visitor a `migrate:fresh` on request. The spec's
throttle, cooldown and lock are necessary and not sufficient. Also required, and `off` by
default:

- **POST only, CSRF enforced** (the `web` middleware default carries this — the config must
  not let you drop it silently; `demo:doctor` errors if the route's middleware stack
  excludes `web` or `VerifyCsrfToken`).
- **`Retry-After` on cooldown, `202` on queued** — as specced. Never a synchronous
  `migrate:fresh` inside a web request when `queue => false` is chosen; that config gets a
  doctor warning naming the request-timeout risk.
- **Optional `auth` requirement** — `on_demand.middleware` defaults to `['web']` but the
  recipe docs lead with `['web', 'auth']`, because "I broke the demo, let me start over"
  is a thing a signed-in visitor asks for.
- **Host check at request time.** Guard 4 validates `APP_URL`'s host against
  `allowed_hosts` at command time; the route additionally validates the **incoming
  request's** host. An app reachable on two hostnames should not be resettable from the one
  that was never meant to be a demo.

### 3.4 Sandbox identity must not be client-assertable

`scoped` puts a `demo_sandbox_id` on rows and scopes queries by a value that arrives in a
cookie. If a visitor can set that cookie, the isolation is theatre and worse than none,
because the global scope is now an access-control decision made from user input.

- The sandbox id lives in the **session**, not a bare cookie. Laravel's session cookie is
  already signed and encrypted; reusing it means no new trust boundary.
- `AttachSandbox` resolves the id from the session, verifies a matching **non-expired** row
  exists in `demo_sandboxes`, and mints a fresh sandbox otherwise. An unknown or expired id
  never selects rows — it creates a new sandbox.
- `BelongsToSandbox`'s global scope reads from the resolved `Sandbox` object the middleware
  put in the container, never from the request.
- `demo:doctor` errors when `sandbox.driver = scoped` and any model in `sandbox.models`
  lacks the trait, because a half-scoped model set is a data leak between visitors that
  looks like it works.

### 3.5 Guard the install path

- **No `composer` scripts, no post-install hooks, nothing destructive at discovery time.**
  Installing the package and running `package:discover` must be observably inert. Arch test:
  the service provider's `register()` and `boot()` contain no call into `Reset\`.
- **`demo:install` is additive.** It publishes config, writes the seeder stub, appends
  `DEMO_*` keys to `.env.example`. It never touches `.env`, never sets `DEMO_MODE=true` for
  you, and prints the guard checklist as its closing output. Enabling a demo is a human act.
- **`demo:doctor` is the deploy gate.** Non-zero exit on any error. The docs' deployment
  recipe puts it in the pipeline *before* the first `demo:reset`, and `security.md` says
  plainly: run this or accept that you are guessing.

### 3.6 `security.md` is a release blocker

Verbatim from the spec, and it stays: *this is a destructive package; a wrong configuration
erases your database. Never install it in an app that holds anything you want to keep.*
Plus a "what demo mode does not protect" section — it is not auth, not rate limiting, not
WAF, not a guarantee that your seeder's fake data is safe to show strangers.

---

## 4. Repository scaffold

Spatie's layout and CI shape, Nuno's `composer.json` script ergonomics, no
`laravel-package-tools`.

```
composer.json
phpunit.xml.dist
phpstan.neon.dist              # larastan, level 8 (spec) — level 9 attempted, baseline if it fights
pint.json                      # laravel preset + declare_strict_types
rector.php                     # PHP 8.4 + Laravel sets, dry-run in CI
.editorconfig  .gitattributes  .gitignore
LICENSE.md  README.md  CHANGELOG.md  CONTRIBUTING.md  SECURITY.md
.github/
  workflows/tests.yml          # PHP 8.4/8.5 x prefer-lowest|prefer-stable
  workflows/databases.yml      # reset strategies on sqlite, MySQL 8, PostgreSQL 16
  workflows/static.yml         # phpstan + pint --test + rector --dry-run + type coverage
  workflows/fix-php-code-style-issues.yml
  workflows/update-changelog.yml
  dependabot.yml
src/                           # exactly the tree in the spec, §3
config/demo.php
resources/views/components/{banner,credentials}.blade.php
resources/lang/{en,pt_BR}/demo.php
database/migrations/create_demo_sandboxes_table.php.stub
stubs/DemoSeeder.php.stub
tests/
  Pest.php  TestCase.php  ArchTest.php
  Unit/ ...
  Feature/ ...
  Integration/ResetsAWholeApplicationTest.php
workbench/                     # testbench workbench app for the integration test
docs/                          # tree per spec §9
```

`composer.json` essentials:

```json
"require": { "php": "^8.4", "illuminate/contracts": "^13.0" },
"require-dev": {
  "orchestra/testbench": "^11.2",
  "pestphp/pest": "^5.2",
  "pestphp/pest-plugin-laravel": "^5.0",
  "larastan/larastan": "^3.12",
  "laravel/pint": "^1.32",
  "rector/rector": "^2.6",
  "pestphp/pest-plugin-type-coverage": "^5.0"
},
"suggest": { "spatie/laravel-db-snapshots": "Required for the snapshot reset strategy" },
"scripts": { "test": ["@test:lint", "@test:types", "@test:unit"] }
```

### Code conventions

Match the house style already in `laravel-sso`: `declare(strict_types=1)` everywhere, and
class/method docblocks that explain **why** in prose rather than restating the signature.
`App\Services\DemoMode` and `DemoResetCommand` in that repo are the reference — the comment
that says *"the seeder makes the same check, but reaching it would mean the tables had
already been dropped"* is the kind of thing this package needs throughout, because every
guard has a reason and the reason is the documentation.

---

## 5. Phases

Revised again during the build. The specification's eleven milestones and this
document's first revision to eight both split the reset story across several
releases, which turned out to be wrong for a different reason than §2.1's: the
Runner has to know about cleaners and credential rotation to sequence them
correctly, so building it in three passes would have meant rewriting its core
method three times. They are one phase.

| # | Phase | Content | Done when |
|---|---|---|---|
| **0** | Scaffold | composer.json, Pint, Rector, PHPStan, Pest, CI, repo metadata | `composer test` runs on an empty `src/`. |
| **1** | Core + guarded reset | `DemoMode`, `Configuration`, facade, provider, all six barriers, `Runner`, `MigrateFreshSeed`, cleaners, credentials, restrictions, scheduling, `install`/`status`/`doctor`/`reset`/`credentials` | The guard matrix proves no combination of flags reaches a destructive call. A real `demo:reset` rebuilds a real database. |
| **2** | UI | `<x-demo-banner />`, `<x-demo-credentials />`, `@demo`/`@notdemo`, `ShareDemoState`, en + pt_BR | A countdown derived from the cron, which cannot disagree with the scheduler. |
| **3** | Strategies | `Snapshot`, `SqlDump`, `Callback`, `demo:snapshot` | Database CI matrix green on MySQL 8 and PostgreSQL 16. |
| **4** | Write guards | `ReadOnlyMiddleware`, `ModelGuard`, `ConnectionGuard`, `PreventsDemoWrites`, `WriteBlocked` | The published account cannot have its email or password changed by a visitor. |
| **5** | On-demand reset | `POST /demo/reset` with the §3.3 hardening | Throttled, cooled down, CSRF-enforced, host-checked at request time, queued by default. |
| **6** | Sandbox | `shared` + `scoped`, `BelongsToSandbox`, `AttachSandbox`, `demo:sandbox:prune` | Two visitors cannot see each other's rows, and a forged session id mints a new sandbox rather than selecting someone else's. |
| **7** | Docs + polish | `/docs` complete, `UPGRADE.md`, recipes | Quickstart runs start to finish. |
| **8** | 1.0 | Tag | Both starter kits migrated and running on the release. |

`database` sandbox driver and licence-check integration stay out of 1.0.

## 6. Testing

Stack: Pest 5, Testbench 11, larastan level 8, Pint, Rector, 100% type coverage.

Beyond the spec's per-module coverage, three things carry disproportionate weight:

**The guard matrix.** A data-provider test over the cartesian product of
`{enabled, disabled} × {local, staging, demo, production} × {allowed host, wrong host, no
allowlist} × {--force, interactive} × {lock free, lock held}` asserting, for every cell,
whether the run is permitted — and using a `ResetStrategy` spy so a failure is a wrong
verdict rather than a dropped table. This is the test that makes the package safe to
install, and it is written before `MigrateFreshSeed` exists.

**The `finally` test.** A strategy that throws must still leave the app out of maintenance
mode and destructive commands re-prohibited. Asserted on both the throw path and the
lock-timeout path.

**The real integration test.** The spec's requirement, kept: a full Testbench workbench app
with demo on, running an actual `demo:reset` against sqlite and verifying database,
sessions, storage and credentials afterwards — plus asserting that
`FreshCommand::prohibit()` is back to `true` when it finishes.

Arch tests carry the invariants that are cheap to state and expensive to lose: the
credential value never reaches a logging call site; the provider never references `Reset\`;
nothing outside `DemoMode` reads `config('demo.enabled')`.

---

## 7. Dogfooding

Both starter kits migrate before 1.0, via a path repository during development:

```json
"repositories": [{ "type": "path", "url": "../packages/laravel-demo-mode" }]
```

`laravel-sso` goes first (at 0.2) because its demo logic is already factored into one
service class, so the migration is mostly deletion — `App\Services\DemoMode` disappears
entirely. Its mapping table in the spec (§10) is accurate and needs no revision. It keeps
`SSO_DEMO_MODE` as an alias for one version.

`mary-ui-starter-kit` follows at 0.3 because its demo logic is spread across seven files
and it is the one that exercises the cleaners hardest (Redis session flush, two storage
disks). Its migration is where `ProhibitDestructiveCommands => ! config('app.demo.enabled')`
comes out of `config/essentials.php` and the prohibition goes back to being on all the time.

What the two projects are actually testing, beyond "does it work": that the extension
points are the right shape. If either app needs a `Restriction` or `Cleaner` the package
did not anticipate, that is the signal the contract is wrong, and it is cheaper to learn at
0.3 than after 1.0.

---

## 8. Open questions

Carried from the spec, with a position on each.

| Question | Position |
|---|---|
| `WriteBlocked` as a public event — useful telemetry or noise on a busy demo? | Ship it, but do not log it by default at `info`. `log.blocked_writes` gates the log line; the event always fires so an app can sample it. Revisit if a demo produces thousands a day. |
| Does `ForceConfig` need runtime write prevention? | Yes — answered in §2.3. `laravel-sso`'s settings UI is the generalisable case, not a quirk. |
| Package name | Closed: `laravel-demo-mode` (§1). |
| **New:** should `read_only` default change? | No. Default stays `false`. A playground exists to be written to; an app that wants read-only knows it. But `demo:doctor` should mention when `read_only` is off *and* `guards.protected` is empty, because that combination is the one where the first visitor locks everyone out. |
| **New:** `demo:reset --dry-run` output | Should print the resolved guard verdicts, not just the plan — a dry run that says "all six guards pass, strategy is MigrateFreshSeed, seeder is X, four cleaners will run" is the thing you paste into a PR when you set up a demo. |

---

## 9. Development process

The package is built to 1.0 in this repository, phase by phase, then both starter kits are
migrated onto the released version.

**Per phase:**

1. Implement the phase's modules with their tests.
2. `composer test` green — lint, types, type coverage, unit.
3. Run `/simplify` over the diff and apply what it finds. Every phase, before the commit,
   without exception.
4. Commit. One commit per phase unless the phase is large enough to split along module
   boundaries.

**Phases** are the milestones in §5: 0.1 through 0.8, then 1.0.

**After 1.0:**

1. Install the package into `laravel-sso` and `mary-ui-starter-kit` from Packagist-style
   version constraints (path repository only if a fix is needed mid-migration).
2. Adapt both projects to follow the package faithfully — not a shim layer over the old
   code, but deletion of everything the package now owns. The mapping tables in the spec
   (§10) are the checklist; anything they do not cover gets a decision recorded here.
3. Both projects end on the release version with no leftover demo plumbing.
4. Publish, then tag releases for both projects.
