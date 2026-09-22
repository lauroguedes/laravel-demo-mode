# Quickstart

From nothing to a working public demo. Five steps.

## 1. Install

```bash
composer require lauroguedes/laravel-demo-mode
php artisan demo:install
```

## 2. Write the demonstration data

`database/seeders/DemoSeeder.php` runs on every reset, against an empty database.

```php
namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use LauroGuedes\DemoMode\Facades\Demo;

class DemoSeeder extends Seeder
{
    public function run(): void
    {
        User::factory()->create([
            'email'    => 'admin@demo.test',
            'name'     => 'Demo Administrator',
            'password' => bcrypt(Demo::credentials()['password']),
        ]);

        Post::factory()->count(25)->create();
    }
}
```

Two things worth deciding here.

**How much.** A demo with three rows demonstrates nothing; a demo with a hundred
thousand takes long enough to rebuild that visitors watch a maintenance page.
Seed the amount that makes the product look like itself.

**What.** Everything in this seeder is public. Faker output is fine. A dump of
production with the email addresses changed is not, and it is the most common way
a demo leaks something real.

Read the password from `Demo::credentials()` rather than hardcoding one. The
reset generates it just before the seeder runs, which is what keeps the login
page and the database in agreement without either side knowing the value.

## 3. Declare the deployment a demo

```dotenv
DEMO_MODE=true
DEMO_RESET_SCHEDULE="0 */6 * * *"
```

```php
// config/demo.php
'environments'  => ['demo'],
'allowed_hosts' => ['demo.example.com'],

'guards' => [
    'protected' => [
        \App\Models\User::class => ['email' => 'admin@demo.test'],
    ],
],
```

`allowed_hosts` is the guard that survives an `.env` being copied somewhere it
should not be. `guards.protected` is what stops the first visitor changing the
published account's password and locking everybody else out until the next reset.

## 4. Check it

```bash
php artisan demo:doctor
```

Fix every error. Errors are things that destroy data or publish secrets.

```bash
php artisan demo:reset --dry-run
```

Prints the plan — guard verdicts, strategy, cleaners — without touching anything.

## 5. Show it

```blade
{{-- Your layout --}}
<x-demo-banner />

{{-- Your login page --}}
<x-demo-credentials />
```

Neither needs an `@demo` wrapper: both render nothing at all when the
installation is not a demo.

The banner's countdown is derived from `DEMO_RESET_SCHEDULE`, so it cannot
disagree with what the scheduler will actually do.

## Confirm the schedule is running

The package registers the reset itself, so there is nothing to add to
`routes/console.php`. Confirm your scheduler runs at all:

```bash
php artisan schedule:list
```

A demo whose cron is not running is a demo that never resets while its banner
keeps promising that it will.
