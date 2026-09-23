# Recipe: a starter kit demo

The case this package was extracted from twice. You have a starter kit or a
boilerplate, and you want a browsable demo so people can see it before they clone
it.

## What makes this case easy

Nothing in the demo is real. The data is whatever your seeder invents, the
accounts are yours, and if a visitor breaks something the next reset fixes it.

So: **share everything, rebuild often, guard one account.**

```php
// config/demo.php
'environments'  => ['demo'],
'allowed_hosts' => ['demo.example.com'],

'reset' => ['strategy' => 'migrate-fresh-seed'],

'guards' => [
    'protected' => [
        \App\Models\User::class => ['email' => 'admin@demo.test'],
    ],
],

'sandbox' => ['driver' => 'shared'],
```

```dotenv
DEMO_MODE=true
DEMO_RESET_SCHEDULE=hourly
```

Hourly, because a starter kit demo is small enough to rebuild in seconds and the
shorter the cycle the less a visitor has to look at somebody else's mess.

## The one thing you must not skip

`guards.protected` on the published account. A visitor signs in with the
credentials on the login page, opens the profile screen, and changes the email —
an ordinary feature working correctly. From then until the next reset nobody else
can get in.

On an hourly cycle that is an hour of a broken demo. `demo:doctor` warns when this
is empty.

## Showing the credentials

```blade
{{-- Your login page --}}
<x-demo-credentials />
```

Renders nothing when this is not a demo, so it can live in the real login view.

Prefilling the form is nicer still:

```blade
@demo
    <input name="email" value="{{ Demo::credentials()['email'] }}">
    <input name="password" type="password" value="{{ Demo::credentials()['password'] }}">
@enddemo
```

## Hiding what a demo cannot do

```blade
@notdemo
    <a href="{{ route('oauth.github') }}">Sign in with GitHub</a>
@endnotdemo
```

Social sign-in, billing, anything that needs a real third party. `@notdemo`
renders when the flag is off, so this is also the production path.

## The banner

```blade
{{-- Your layout --}}
<x-demo-banner />
```

Counts down from the same cron expression the scheduler runs, so it cannot promise
something the server will not do.

```php
'banner' => [
    'classes' => ['warning' => 'alert alert-warning'],
],
```

One line beats publishing the view. See [../frontend.md](../frontend.md).

## What to leave alone

**Read-only.** A starter kit demo exists to be clicked. Leave
`guards.read_only.enabled` off.

**The connection guard.** You do not need it, and it needs every framework table
on an exception list before the demo can serve a page.

**Sandboxes.** `shared` is right here. A starter kit demo is usually one person at
a time, and per-visitor isolation costs a column on every model you mark.

## Deploying it

See [deployment.md](deployment.md) — the short version is that the scheduler has
to actually run, and `demo:doctor` belongs in the pipeline before the first reset.
