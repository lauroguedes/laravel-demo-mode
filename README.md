# Laravel Demo Mode

[![Tests](https://github.com/lauroguedes/laravel-demo-mode/actions/workflows/tests.yml/badge.svg)](https://github.com/lauroguedes/laravel-demo-mode/actions/workflows/tests.yml)
[![Latest Version](https://img.shields.io/packagist/v/lauroguedes/laravel-demo-mode.svg)](https://packagist.org/packages/lauroguedes/laravel-demo-mode)
[![License](https://img.shields.io/packagist/l/lauroguedes/laravel-demo-mode.svg)](LICENSE.md)

Turn a Laravel installation into a public playground: seeded data, a scheduled
reset, rotating credentials published on the login page, and a belt of
restrictions that keeps a stranger from abusing the server.

Publishing a starter kit, a boilerplate or a SaaS with a browsable demo needs the
same five pieces every time — a flag, a rebuild on a cycle, credentials a stranger
can use that do not become a permanent fact of the internet, a list of things the
demo must not do, and a visible notice that the data is temporary. This is those
five pieces, extracted from two demos that have been running in public.

> **This package drops tables.** Read [docs/security.md](docs/security.md) before
> you install it anywhere. A wrong configuration erases your database.

## Installation

```bash
composer require lauroguedes/laravel-demo-mode
php artisan demo:install
```

`demo:install` is additive: it publishes the config, writes a `DemoSeeder` stub,
and appends the `DEMO_` keys to `.env.example`. It does not touch `.env` and it
does not turn the demo on — that is an act you perform on the deployment you
meant.

## Making an installation a demo

```dotenv
DEMO_MODE=true
DEMO_RESET_SCHEDULE="0 */6 * * *"
```

```php
// config/demo.php
'environments'  => ['demo'],
'allowed_hosts' => ['demo.example.com'],
```

Write the demonstration data into `database/seeders/DemoSeeder.php`, then check
your work:

```bash
php artisan demo:doctor
```

It exits non-zero on anything that would destroy data or publish a secret, so it
belongs in your deploy pipeline ahead of the first reset.

## Using it

```php
use LauroGuedes\DemoMode\Facades\Demo;

Demo::enabled();        // the single point of truth
Demo::nextResetAt();    // derived from the cron, so a countdown cannot lie
Demo::credentials();    // what the login form should prefill
Demo::toArray();        // one payload for Blade, Livewire and Inertia
```

```blade
{{-- Both render nothing when this is not a demo, so no wrapper is needed --}}
<x-demo-banner />   {{-- a floating bar the package styles itself --}}
<x-demo-credentials />

@notdemo
    <a href="{{ route('oauth.google') }}">Sign in with Google</a>
@endnotdemo
```

For Inertia, return `Demo::toArray()` from your own
`HandleInertiaRequests::share()`. For Blade and Livewire, the optional
`ShareDemoState` middleware puts the same payload in every view. See
[docs/frontend.md](docs/frontend.md).

## Letting visitors reset it

```php
'on_demand' => ['enabled' => true],
```

Off by default — it puts a `migrate:fresh` behind an HTTP request. Throttled,
cooled down, queued, CSRF-protected and host-checked; see
[docs/on-demand-reset.md](docs/on-demand-reset.md).

## Isolating visitors from each other

```php
'sandbox' => ['driver' => 'scoped'],
```

Each visitor gets the seeded baseline plus what they created. Not multi-tenancy
and not a security boundary; see [docs/sandbox.md](docs/sandbox.md).

## Commands

| Command | What it does |
|---|---|
| `demo:install` | Publish the config and the seeder stub |
| `demo:reset` | Rebuild the demonstration data. `--dry-run` prints the plan |
| `demo:status` | What this installation currently is |
| `demo:doctor` | Audit the configuration. Non-zero exit on anything dangerous |
| `demo:credentials` | Show, or `--rotate`, the published passwords |
| `demo:snapshot` | Capture the baseline the snapshot strategy restores |
| `demo:sandbox:prune` | Remove the sandboxes nobody came back to |

## What it is not

- **Password-protecting a work in progress.** That is `php artisan down --secret`.
- **Backup and restore.** That is `spatie/laravel-backup`.
- **A demo data generator.** The seeder is yours; the package runs it.
- **Multi-tenancy.** Visitor isolation is deliberately ephemeral and disposable.

## Documentation

Full documentation is in [`docs/`](docs/README.md). Start with
[security.md](docs/security.md) — it is the one that is not optional.

Already have a hand-rolled demo mode? [UPGRADE.md](UPGRADE.md) is mostly a list of
things to delete.

## Testing

```bash
composer test
```

## Credits

Extracted from [lauroguedes/laravel-sso](https://github.com/lauroguedes/laravel-sso)
and [lauroguedes/mary-ui-starter-kit](https://github.com/lauroguedes/mary-ui-starter-kit),
which had each solved this badly in their own way first.

## License

The MIT License. See [LICENSE.md](LICENSE.md).
