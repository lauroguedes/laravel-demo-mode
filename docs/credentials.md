# Published credentials

A demo is only a demo if a stranger can get in. This is the one place in a Laravel
application where a password is deliberately recoverable, so it is worth
understanding exactly how far that goes.

## How it works

Each reset generates a new password for every account marked `rotate`, hashes it
into the database through your seeder, and publishes it to a store the login page
reads.

```php
Demo::credentials();     // ['email' => …, 'password' => …, 'label' => …]
Demo::allCredentials();  // every published account, each with a 'primary' flag
Demo::passwordFor($email);  // just the password, or null — what a seeder wants
Demo::rotate();          // new passwords now, without rebuilding the data
```

```blade
<x-demo-credentials />
```

## Reading it from your seeder

```php
User::factory()->create([
    'email'    => 'admin@demo.test',
    'password' => bcrypt(Demo::passwordFor('admin@demo.test') ?? 'password'),
]);
```

The reset stages the password just before the seeder runs and writes it to the
store just after the cleaners, so `Demo::passwordFor()` inside a seeder returns
the value that is about to be published.

That split — staged before the seeder, written after the cleaners — is not
incidental. Generating after the cleaners would mean the seeder hashed the
*previous* password, and the login page would show credentials that do not open
the account they name. Generating and publishing before them would mean the cache
cleaner erased what was just written. Both failures are completely silent, which
is why the package does it in two steps and `demo:doctor` checks the second one.

**Keep the fallback.** Outside a reset there is nothing staged and the method
answers `null` — and `php artisan db:seed` on its own is the first thing you will
run, long before the first reset exists. Without it the seeder fails the first
time you use it.

## The two bounds that make this safe enough

**Nothing is read back unless the installation still says it is a demo.** Turn
`DEMO_MODE` off and a leftover credentials file reads as nothing at all. This is
what makes "the demo server got promoted" a survivable mistake rather than a
published administrator password.

**Rotation retires the password.** A published password that never changes is a
permanent fact of the internet: whatever a visitor wrote down keeps working for
as long as the demo exists. `demo:doctor` warns when no account rotates.

## Choosing a store

| Store | When |
|---|---|
| `file` | The default. A JSON file on a private disk. Survives the cache flush that is part of every reset, so cleaner ordering stops being something you have to get right. |
| `cache` | Simpler, no disk to misconfigure — but `FlushCache` must keep `demo-mode:*`, which the default config already does. `demo:doctor` errors if you remove it. |
| `null` | Publish nothing. For a demo whose password is documented in its README. That password is permanent, so the account it opens should be able to do less. |

**The file store's disk must not be web-reachable.** Use `local`. `demo:doctor`
treats a disk with a public URL, public visibility, or a root inside `public/` as
an **error**, because the failure is a working administrator password served at a
guessable URL — and it keeps being served after `DEMO_MODE` goes off, since the
flag governs what this package reads, not what your web server hands out.

## Where the password must never appear

It is public on a login page and nowhere else.

`Credential::__debugInfo()` and its string cast both redact, so a `dd()`, a stack
trace, or an error reporter does not put a working credential into a screenshot
or a log aggregator. `ResetReport` carries `credentials_rotated` as a count.
`CredentialsRotated` carries the addresses, not the passwords. An architecture
test asserts that nothing under `src/Credentials/` hands the value to a logger.

If you write your own listener, use `$credential->redacted()`.

## Several accounts

```php
'accounts' => [
    ['email' => 'admin@demo.test',  'label' => 'Administrator', 'rotate' => true, 'primary' => true],
    ['email' => 'viewer@demo.test', 'label' => 'Read-only',     'rotate' => true],
],
```

`primary` is the one a login form prefills. With a single account — which is most
demos — you can leave it out.

## Protect the account

Publishing the credentials is half the job. If a visitor can change that
account's email or password, the next visitor cannot get in:

```php
'guards' => [
    'protected' => [
        \App\Models\User::class => ['email' => 'admin@demo.test'],
    ],
],
```

`demo:doctor` warns when this is empty. See [write-guards.md](write-guards.md) for
what the guard does and does not catch — it hooks Eloquent events, so it does not
see a bulk `Model::where(…)->update()` or a raw `DB::table()` write.
