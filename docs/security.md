# Security

**This is a destructive package. A wrong configuration erases your database.
Never install it in an application that holds anything you want to keep.**

That is the whole warning, and it is not softened anywhere else in these docs.
What follows is how the package tries to make the wrong configuration hard to
reach, and — just as important — what it does not protect you from.

## The six barriers in front of a reset

A reset runs `migrate:fresh`. Every one of these must be satisfied first.

| # | Barrier | What gets past it |
|---|---|---|
| 1 | `DEMO_MODE=true` | Nothing. Not `--force`. On an installation where this is off, `demo:reset` is not even a registered command. |
| 2 | `APP_ENV` ∈ `demo.environments` | Nothing. Editing the list, which is a deliberate act. |
| 3 | Not production | Adding `'production'` to `demo.environments`, spelled out in full. |
| 4 | `APP_URL` host ∈ `demo.allowed_hosts` | Setting `allowed_hosts` to `null`, which disables the check. |
| 5 | Interactive confirmation | `--force`. This is the only barrier `--force` touches. A non-interactive run without `--force` refuses. |
| 6 | The reset lock | Nothing. Two concurrent rebuilds of one database is how you get half a schema. |

### The on-demand route

`demo.on_demand.enabled` puts a `migrate:fresh` behind an HTTP request. Off by
default. When it is on, `demo:doctor` errors if the middleware list has no `web`
in it — without CSRF, any page on the internet can rebuild the demo with a form
post — and warns when there is no cooldown or when a "queued" rebuild would run
inline anyway. See [on-demand-reset.md](on-demand-reset.md).

The host is checked against the request, not only `APP_URL`, and in middleware
ahead of the throttle — otherwise a request with a forged `Host` header spends a
rate-limit slot on its way to the 404, which with `per => 'global'` takes the
reset button away from every real visitor. A refusal says nothing about why.

**The reset lock is only as real as your cache store.** `CACHE_STORE=null` grants
every lock to everybody and `array` keeps them inside one process, so either
leaves two rebuilds free to overlap. `demo:doctor` reports both.

### What `--force` means

It skips the confirmation prompt. That is the entire list.

It is worth stating plainly because the obvious shorthand is wrong in a way that
is easy to ship:

```php
// Do not do this. It is a working `migrate:fresh` on any installation.
if (! config('app.demo.enabled') && ! $this->option('force')) {
    return self::FAILURE;
}
```

The flag is not a convenience to be overridden. It is the statement that makes
the reset legal, and barriers 1, 2 and 6 have no bypass at all.

## Run `demo:doctor` before the first reset

```bash
php artisan demo:doctor
```

It exits non-zero on anything that would destroy data or publish a secret, which
makes it a deploy-pipeline gate rather than something you remember to run:

```yaml
- run: php artisan demo:doctor
```

Errors — these stop the pipeline:

- the reset would be refused, so the demo will never rebuild
- the credential store's disk is web-reachable
- the configured seeder does not exist (a reset would drop everything and then
  have nothing to seed)
- credentials live in the cache and the cache cleaner would erase them
- the reset schedule cannot be parsed

Warnings — these do not:

- mail can still leave the server
- nothing rotates its password
- no account is protected from writes
- the database name does not look disposable

## The published password

A demo publishes a working password on a login page. That is the point, and it
is also the largest thing that can go wrong.

**The store must not be web-reachable.** Use a private disk — `local` is, by
Laravel's convention, not served. `demo:doctor` treats a public disk as an error,
not a warning, because the failure is an administrator password at a guessable
URL, and it stays served after `DEMO_MODE` goes off: the flag governs what this
package reads, not what your web server hands out.

**Rotation is the control.** A password that never changes is a permanent fact of
the internet — whatever a visitor wrote down keeps working for as long as the
demo exists. A new one on every reset means it stops working on the next cycle.

**The password never reaches a log.** `Credential::__debugInfo()` and its string
cast both redact, so a `dd()`, a stack trace or an error reporter does not put a
working credential into a screenshot or a log aggregator. `ResetReport` carries
`credentials_rotated` as a count. An architecture test asserts that nothing in
`src/Credentials/` hands the value to a logger.

**Turning the flag off makes leftovers inert.** Every read is gated on
`Demo::enabled()`, so a credentials file left behind by an installation that has
since become something else reads back as nothing. This is what makes "the demo
box got promoted to production" survivable rather than a published admin password.

## What demo mode does not protect you from

It is not authentication, not authorisation, not rate limiting, not a WAF, and
not a guarantee that anything is safe to show strangers. In particular:

**Your seeder is your problem.** The package rebuilds the data; it does not
generate it. A seeder built from a dump of production with the email addresses
changed is the most common way a demo leaks something real. Names, addresses,
message bodies, invoice lines and uploaded files are all public the moment they
are seeded.

**A demo is a server strangers execute code paths on.** Every feature reachable
after sign-in is reachable by anyone. If a feature can send mail, enqueue
expensive work, call a paid API, write to a shared bucket or reach an internal
network, demo mode does not stop it unless you write a `Restriction` that does.
`DisableMail` and `DisableNotifications` cover the two most common cases and
nothing else.

**Write guards are opt-in, and one of them is not optional in practice.** Set
`guards.protected` for the published account, or a visitor can change its email
or password and lock every later visitor out until the next reset. See
[write-guards.md](write-guards.md). Everything else a signed-in visitor can
reach, they can change — that is what a playground is.

The model guard hooks Eloquent events, so it does not see `Model::where(…)
->update()`, `DB::table()->update()`, or anything wrapped in `withoutEvents()`.
A visitor cannot choose those paths, but your own code can; the list is in
[write-guards.md](write-guards.md).

**Isolation is not multi-tenancy.** The `scoped` sandbox driver keeps ordinary
visitors out of each other's rows. It is not a security boundary, it has not been
audited as one, and a model you forget to mark leaks rows while appearing to work
— which is why `demo:doctor` checks every model you list. See
[sandbox.md](sandbox.md).

What it does get right is that a visitor cannot assert their own identity. The
sandbox id lives in the session and is a lookup key rather than a claim, so a
forged one mints a new empty sandbox instead of selecting somebody else's rows —
and on the write side whatever `demo_sandbox_id` a request carries is overwritten
with the visitor's own, so a form post cannot plant a row in another sandbox or
publish one to everybody.

Two limits worth knowing: the scope is Eloquent-only, so a `DB::table()` query
against a marked table reads across sandboxes with no warning; and a database
error while resolving a sandbox fails the request rather than serving unscoped.

**This is not password-protecting a work in progress.** That is
`php artisan down --secret`, which ships with Laravel.

**This is not backup or restore.** That is `spatie/laravel-backup`.

## Reporting a vulnerability

Email laurowguedes@gmail.com rather than opening a public issue.
