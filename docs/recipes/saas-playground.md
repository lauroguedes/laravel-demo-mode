# Recipe: a SaaS playground

Harder than a starter kit demo, because the product has real customers and the
demo runs the same code. Everything below exists to keep those two apart.

## Start from the assumption that the demo will be wrong once

Not as pessimism — as the reason the defaults are shaped the way they are. The
question is not whether somebody eventually points a demo `.env` at something it
should not touch, it is what happens when they do.

```php
// config/demo.php
'environments'  => ['demo'],
'allowed_hosts' => ['demo.example.com'],
```

**A separate database, and one whose name says so.** `acme_demo`, not `acme`.
`demo:doctor` warns on a name without `demo`, `staging` or `test` in it, and that
warning is the last thing standing between a copied `.env` and your customers.

**A separate deployment.** Same image, different environment. Nothing about this
package makes it safe to run a demo and production out of one process.

## Seeded data, not a copy of production

The single most likely way a demo leaks something real is a seeder built from a
dump with the email addresses changed. Names, addresses, invoice lines, message
bodies and uploaded files are all public the moment they are seeded.

Use factories. If you need realistic volume, generate it.

```php
'reset' => [
    'strategy' => 'snapshot',
    'schedule' => '0 */6 * * *',
],
```

A SaaS demo usually has enough data that `migrate-fresh-seed` is a visible outage
every cycle. Take the baseline once with `demo:snapshot` and the reset becomes an
import. See [../reset-strategies.md](../reset-strategies.md).

> `snapshot` and `sql-dump` run no seeder, so a rotating password is never
> re-hashed — the login page would show one that opens nothing. Either set
> `rotate => false` and document a fixed password, or re-hash the staged one from
> a `ResetCompleted` listener. `demo:doctor` treats the combination as an error.

## Restrictions are where a SaaS demo differs

A starter kit has nothing to stop. A product does.

```php
'restrictions' => [
    Restrictions\DisableMail::class => ['transport' => 'array'],
    Restrictions\DisableNotifications::class => ['channels' => ['vonage', 'slack']],

    Restrictions\ForceConfig::class => ['pin' => [
        'services.stripe.key' => null,
        'features.exports'    => false,
    ]],

    Restrictions\BlockPrivilegedAccounts::class => [
        'roles' => ['super-admin'],
    ],
],

// app/Providers/AppServiceProvider.php
Restrictions\Pipeline::use(App\Demo\StopChargingCards::class);
```

**Write your own for anything that costs money or leaves the building.** The
package knows to stop mail; it has no idea whether your application also charges
cards, calls a partner API, sends webhooks, or writes to an audit log somebody
bills you per row for. `Restriction` is a public contract for exactly this — see
[../restrictions.md](../restrictions.md).

**`ForceConfig` pins rather than sets.** If your product has a settings screen, a
visitor can otherwise undo at 10:01 what the boot set at 10:00 — and the settings
worth pinning are the ones whose wrong value locks the next visitor out.

## Isolating visitors

A product demo is often several people at once, poking at the same list.

```php
'sandbox' => [
    'driver' => 'scoped',
    'models' => [\App\Models\Project::class, \App\Models\Task::class],
],
```

Each visitor gets the seeded baseline plus what they created. Read
[../sandbox.md](../sandbox.md) first — it needs a column on every marked model, it
is not a security boundary, and a model you forget to mark leaks rows while
appearing to work.

If your demo is mostly read-only screens, `shared` is still the honest answer.

## What not to do

**Do not run the demo against a read replica of production.** No configuration of
this package makes that safe; the reset drops tables.

**Do not reuse production credentials, keys or webhook secrets.** A demo publishes
a password on a web page. Assume everything reachable from inside it is known.

**Do not turn on the connection guard as a substitute for thinking.** It blocks
every write including the framework's own, and every table has to be excepted
before the demo serves a page. `guards.protected` and `read_only` first.

## The checklist before it goes public

```bash
php artisan demo:doctor
```

Non-zero exit means stop. Then, by hand:

- [ ] separate database, with `demo` in the name
- [ ] separate deployment, its own `.env`
- [ ] `allowed_hosts` set to the demo's hostname
- [ ] seeded data, not a copy of anything real
- [ ] the published account in `guards.protected`
- [ ] mail contained, and every paid integration restricted
- [ ] keys and secrets distinct from production's
- [ ] the scheduler actually running — `php artisan schedule:list`
