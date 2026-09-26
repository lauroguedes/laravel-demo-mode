## Laravel Demo Mode

Turns an installation into a public playground: seeded data, a scheduled reset that
drops and rebuilds the database, credentials rotated and published on the login
page, and restrictions that keep a stranger from abusing the server.

### This package drops tables

`demo:reset` runs `migrate:fresh` by default. Never set `DEMO_MODE=true`, add an
environment to `demo.environments`, or run `demo:reset` on the user's behalf
without being asked to — those are the switches that erase a database. Turning a
demo on is a deliberate act performed on the deployment the user meant.

After changing anything in `config/demo.php`, run `{{ $assist->artisanCommand('demo:doctor') }}`.
It exits non-zero on anything that would destroy data or publish a secret, and it
catches the failures this package has that are otherwise silent.

### One source of truth

`Demo::enabled()` answers whether this installation is a demo. Do not read
`config('demo.enabled')` or `env('DEMO_MODE')` directly, and do not invent a
second flag.

@boostsnippet('Hiding something on a demo', 'blade')
@notdemo
    <a href="{{ route('oauth.google') }}">Sign in with Google</a>
@endnotdemo
@endboostsnippet

`@demo`, `@notdemo` and `@unlessdemo` are registered whether or not this is a
demo, so they are always safe to use.

### The view components render nothing off a demo

@boostsnippet('Correct — no wrapper', 'blade')
<x-demo-banner />
<x-demo-credentials />
@endboostsnippet

Do **not** wrap them in `@demo`. They already decide for themselves, and an
application that wraps them is one that breaks when somebody forgets. Do not pass
CSS classes to `<x-demo-banner />` in the default `pill` style either: it renders
inside a shadow root where a class name means nothing, though inherited
properties such as `font-weight` still cross into it.

### Never hardcode the demo password in a seeder

The published account's password is rotated on every reset. A seeder that hardcodes
one works until the first rotation and then fails **silently** — the login page
shows one password while the database holds another, with nothing in any log.

@boostsnippet('The seeder reads what the reset staged', 'php')
$email = config('demo.credentials.accounts.0.email');

User::factory()->create([
    'email' => $email,
    'password' => bcrypt(Demo::passwordFor($email) ?? 'password'),
]);
@endboostsnippet

The `?? 'password'` fallback matters: outside a reset nothing is staged, and
`db:seed` on its own is the first thing anybody runs.

### A countdown is not a reset

The banner's countdown and `demo:status`'s "Next reset" are both arithmetic on
`DEMO_RESET_SCHEDULE`. They read identically on a server where nothing runs
`schedule:run`. The number that proves a reset happened is `Last reset` in
`{{ $assist->artisanCommand('demo:status') }}` — compare it against the schedule.

### `demo:install` asks questions

It prompts for the sandbox driver and whether to write a `DemoSeeder` stub, so it
blocks in an unattended script. Pass `--no-interaction`, or answer with
`--sandbox=shared|scoped` and `--without-seeder`.

For per-visitor isolation, reset strategies, cleaners, restrictions, the
on-demand reset button and the write guards, use the `demo-mode-development`
skill.
