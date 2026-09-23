# Upgrading

## To 1.0

First release. Nothing to upgrade from — but if you already have a hand-rolled
demo mode, the rest of this page is for you.

## Migrating off a hand-rolled demo mode

Most projects that want this package already have some of it, written by hand and
spread across a few files. The migration is mostly deletion.

### 1. Find every place that asks "is this a demo"

```bash
grep -rn "demo" config/ app/ routes/ resources/views/ --include="*.php"
```

The answer is usually a config key read directly in several files. That is the
thing to replace first, because everything else follows from it:

```php
// before, in as many files as it took
if (config('app.demo.enabled')) { … }

// after, everywhere
if (Demo::enabled()) { … }
```

```blade
{{-- before --}}
@if (config('app.demo.enabled')) … @endif

{{-- after --}}
@demo … @enddemo
```

One point of truth is the whole reason this is worth doing. A flag read in seven
files is a flag where getting one wrong is invisible until a visitor finds it.

### 2. Map your reset command onto the config

| Yours | Here |
|---|---|
| a `demo:reset`-shaped command | `demo:reset` — delete yours |
| `migrate:fresh` + your seeder | `reset.strategies.migrate-fresh-seed.seeder` |
| a reset interval in hours | `DEMO_RESET_SCHEDULE` (cron, or `hourly`/`daily`/`every-6-hours`) |
| a scheduled entry in `routes/console.php` | registered by the package — delete yours |
| flushing sessions, cache, uploads by hand | `cleaners` |
| `config(['mail.default' => 'array'])` | `Restrictions\DisableMail` |
| pinned settings a visitor must not change | `Restrictions\ForceConfig` |
| a login check blocking an admin account | `Restrictions\BlockPrivilegedAccounts` |
| a password in a JSON file or the cache | `credentials.store` — the shapes match |

`BlockPrivilegedAccounts` listens on `Login` and `Attempting`, so it refuses the
sign-in. If what you have is middleware checking on **every** request, keep it:
yours also closes a session that was opened before the flag went on, which this
does not. Point it at `Demo::enabled()` and leave the restriction unconfigured
rather than running both.

### 3. Delete `ProhibitDestructiveCommands => ! demo`

If you disabled Laravel's destructive-command prohibition so your reset could run:

```php
// config/essentials.php — delete this line
NunoMaduro\Essentials\Configurables\ProhibitDestructiveCommands::class => ! config('app.demo.enabled'),
```

Leave the prohibition **on**, permanently. The package lowers it for the duration
of one method call inside the reset and puts it back in a `finally`.

Worth dwelling on, because it is the inversion this replaces: with that line, the
demo server — the one strangers can reach — is the one server where
`migrate:fresh` is always available.

### 4. Keep your `--force` honest

A hand-rolled reset usually grows this:

```php
if (! config('app.demo.enabled') && ! $this->option('force')) {
    return self::FAILURE;
}
```

Which means `demo:reset --force` drops every table on any installation that has
the command. Here, `--force` skips the confirmation prompt and nothing else; the
flag, the environment, the host and the lock have no bypass. If you were relying
on `--force` to reset somewhere the flag is off, that will now refuse — correctly.

### 5. Protect the published account

Almost certainly missing from what you have, because it is not obvious until it
happens: a visitor changes the published account's email, and nobody can sign in
until the next reset.

```php
'guards' => [
    'protected' => [
        \App\Models\User::class => ['email' => 'admin@demo.test'],
    ],
],
```

### 6. Replace the hardcoded banner

```blade
{{-- before: a sentence that can disagree with the schedule --}}
<p>This data resets every 24 hours.</p>

{{-- after: derived from the cron the scheduler runs --}}
<x-demo-banner />
```

This is worth checking rather than assuming: a hardcoded interval next to a
configurable schedule is the specific bug that prompted this component.

### 7. Run the audit

```bash
php artisan demo:doctor
```

Then delete your old demo classes and run it again. Errors mean stop.

### 8. Move your reset test down a level

If you had a test that ran your old reset command, it will not survive being
pointed at `demo:reset`: this runs `migrate:fresh`, which on SQLite runs `VACUUM`,
which SQLite refuses inside the transaction `RefreshDatabase` holds open. A
command that used `migrate:refresh` had no such problem, which is why the test
passed before.

Test the seeder instead — what your application owns is what the seeder writes:

```php
Demo::rotate();                 // stage a password, as the reset does
$this->seed(DemoSeeder::class);

expect(Hash::check(Demo::credentials()['password'], $admin->password))->toBeTrue();
```

Both projects this package was extracted from had exactly this test, and both
ended up here. See [docs/reset-strategies.md](docs/reset-strategies.md).

### Keeping your old environment variable

If `SSO_DEMO_MODE` or similar is set across deployments you cannot change at once:

```php
// config/demo.php
'enabled' => (bool) env('DEMO_MODE', env('SSO_DEMO_MODE', false)),
```

Keep the alias for one release, then delete it.

## Upgrade policy

Breaking changes land in a major version and get a section on this page saying
exactly what to change. The things most likely to move:

- the `Restriction`, `Cleaner`, `ResetStrategy` and `DoctorCheck` contracts, if a
  method has to be added — `ResetStrategy::seedsCredentials()` was added this way
  before 1.0
- the shape of `Demo::toArray()`, which front ends consume
- `demo:doctor` gaining checks that turn a working configuration into a failing
  audit. New checks are listed here when they could stop a pipeline that used to
  pass.
