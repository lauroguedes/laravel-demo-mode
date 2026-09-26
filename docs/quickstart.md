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

use App\Models\Post;
use App\Models\User;
use Illuminate\Database\Seeder;
use LauroGuedes\DemoMode\Facades\Demo;

class DemoSeeder extends Seeder
{
    public function run(): void
    {
        $email = config('demo.credentials.accounts.0.email');

        User::factory()->create([
            'email'    => $email,
            'name'     => 'Demo Administrator',
            'password' => bcrypt(Demo::passwordFor($email) ?? 'password'),
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

**Read the password from `Demo::passwordFor()`**, not a hardcoded one. The reset
stages it just before the seeder runs, which is what keeps the login page and the
database in agreement without either side knowing the value.

**And keep the `?? 'password'`.** Outside a reset there is nothing staged, and
`php artisan db:seed` on its own is the first thing you will run — long before the
first reset exists. Without the fallback the seeder fails the first time you use
it.

## 3. Declare the deployment a demo

```dotenv
DEMO_MODE=true
DEMO_RESET_SCHEDULE="0 */6 * * *"
```

```php
// config/demo.php
'environments'  => ['demo'],
'allowed_hosts' => ['demo.example.com'],   // the host your demo is served on

'guards' => [
    'protected' => [
        \App\Models\User::class => ['email' => 'admin@demo.test'],
    ],
],
```

`allowed_hosts` has to match the host in your `APP_URL`, because that is the point
of it: it is the guard that survives an `.env` being copied somewhere it should not
be. Building locally, that means `['localhost']` — or leave it `null` until you
deploy, and let `demo:doctor` remind you.

`guards.protected` is what stops the first visitor changing the published
account's password and locking everybody else out until the next reset.

## 4. Check it

```bash
php artisan demo:doctor
```

Fix every error. Errors are things that destroy data or publish secrets — a host
that does not match, a seeder that does not exist, credentials on a disk the web
server would serve.

Warnings are worth reading once and then deciding about. "The database does not
look like a throwaway" fires on any name without `demo`, `staging` or `test` in
it, which includes Laravel's default `database/database.sqlite`.

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
`routes/console.php`.

```bash
php artisan schedule:list
```

That proves the reset is **registered**. It does not prove anything runs it —
"Next Due: 33 minutes from now" is arithmetic on the cron expression, and it
reads exactly the same on a server with no cron at all. So does the banner's
countdown, which comes from the same expression.

What proves it is happening:

```bash
php artisan demo:status
```

```
Resets .............................................. hourly
Next reset ....................... 2026-09-26T10:00:00+00:00
Last reset ....................... 2026-09-25T19:33:06+00:00
```

**Compare the last reset against the schedule.** A day-old timestamp under an
hourly schedule means nothing is calling `schedule:run` — the demo has been
promising a reset every hour and never doing one. `Last reset` is recorded by
whatever actually performed a reset, so it cannot be produced by arithmetic.

Locally, `php artisan schedule:work` is the loop. On a server it is the ordinary
cron line, every minute, with Laravel deciding what is due:

```cron
* * * * * cd /srv/demo && php artisan schedule:run >> /dev/null 2>&1
```

A demo whose cron is not running is a demo that never resets while its banner
keeps promising that it will.
