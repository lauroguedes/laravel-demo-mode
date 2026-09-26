# Documentation

Turn a Laravel installation into a public playground.

**Read [security.md](security.md) before you deploy anything.** This package drops
tables; a wrong configuration erases your database.

## Getting there

| | |
|---|---|
| [installation.md](installation.md) | Requirements, and what installing does and does not do |
| [quickstart.md](quickstart.md) | From nothing to a working demo, in five steps |
| [security.md](security.md) | The six barriers, the published password, and what demo mode does **not** protect |

## The pieces

| | |
|---|---|
| [configuration.md](configuration.md) | Every key whose default is a decision rather than an obvious value |
| [reset-strategies.md](reset-strategies.md) | How the data gets rebuilt: migrate-and-seed, snapshot, SQL dump, or your own |
| [cleaners.md](cleaners.md) | Why resetting the database is not resetting the application |
| [credentials.md](credentials.md) | The one place a password is deliberately recoverable |
| [restrictions.md](restrictions.md) | What a public demonstration is not allowed to do |
| [write-guards.md](write-guards.md) | Stopping a visitor locking the next one out |
| [frontend.md](frontend.md) | Blade, Livewire and Inertia from one payload |

## Optional

| | |
|---|---|
| [on-demand-reset.md](on-demand-reset.md) | Letting a visitor rebuild the demo |
| [sandbox.md](sandbox.md) | Giving each visitor their own corner |

## Recipes

| | |
|---|---|
| [recipes/starter-kit.md](recipes/starter-kit.md) | A public demo of a starter kit or boilerplate |
| [recipes/saas-playground.md](recipes/saas-playground.md) | A demo of a product with real customers |
| [recipes/deployment.md](recipes/deployment.md) | Pipelines, workers, cron and containers |

## AI agents

| | |
|---|---|
| `resources/boost/guidelines/core.blade.php` | Loaded upfront by [Laravel Boost](https://laravel.com/docs/13.x/boost) — the handful of things an agent gets wrong otherwise |
| `resources/boost/skills/demo-mode-development/` | Loaded on demand, for configuring and diagnosing a demo |

Nothing to install: `boost:install` discovers both from the package.

## Upgrading

[../UPGRADE.md](../UPGRADE.md) — including migrating off a hand-rolled demo mode.

## The command line

| Command | |
|---|---|
| `demo:install` | Publish the config, and a seeder stub if you want one |
| `demo:doctor` | Audit the configuration. Non-zero exit on anything dangerous |
| `demo:status` | What this installation currently is |
| `demo:reset` | Rebuild the data. `--dry-run` prints the plan |
| `demo:credentials` | Show, or `--rotate`, the published passwords |
| `demo:snapshot` | Capture the baseline the snapshot strategy restores |
| `demo:sandbox:prune` | Remove the sandboxes nobody came back to |
