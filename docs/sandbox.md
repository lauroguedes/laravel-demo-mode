# Per-visitor isolation

Two drivers. The default shares everything; the other gives each visitor the
seeded baseline plus what they created.

```php
'sandbox' => ['driver' => env('DEMO_SANDBOX', 'shared')],
```

| | What a visitor sees | Cost |
|---|---|---|
| `shared` | the same data as everybody else | none — **the default** |
| `scoped` | the seeded baseline plus their own rows | a table, a column per model, session middleware |

## Start with `shared`

Most demos want it. One dataset everyone can poke at is what a demonstration
usually is, and it costs nothing.

`scoped` earns its keep when visitors would trip over each other — a demo whose
main screen is a list everybody adds to, or one where deleting something is the
interesting part.

> **It is not multi-tenancy.** It keeps ordinary visitors out of each other's way.
> It has not been audited as a security boundary, and a model you forget to mark
> leaks rows while appearing to work. Do not put anything in a demo that would
> matter if a visitor saw it.

## Setting up `scoped`

Three things, and `demo:doctor` errors on each if it is missing.

**1. The sandboxes table.**

```bash
php artisan vendor:publish --tag=demo-migrations
php artisan migrate
```

Published rather than loaded, because only this driver needs the table and a
package should not add one to a database that never asked.

**2. A column on every marked table.**

```php
$table->string('demo_sandbox_id')->nullable()->index();
```

**3. The trait, and the models listed.**

```php
use LauroGuedes\DemoMode\Sandbox\BelongsToSandbox;

class Post extends Model
{
    use BelongsToSandbox;
}
```

```php
'sandbox' => [
    'driver' => 'scoped',
    'models' => [\App\Models\Post::class],
],
```

The list is there so `demo:doctor` can check each one actually carries the trait.
That is the only way this feature fails silently: an unmarked model means visitors
see each other's rows in that one table while the rest of the demo looks isolated.

**Session middleware** on the routes that use it — the identifier lives in the
session, so a route without one is unscoped.

```php
$middleware->web(append: ['demo.sandbox']);
```

The middleware is optional: a sandbox is resolved on first use anyway. What it
adds is pushing the expiry out on every request, which is the difference between
a TTL and a deadline.

## What a visitor sees

Rows the seeder created carry no sandbox id, so they belong to everybody — that
is what makes a scoped demo look like a demo rather than an empty application.
Rows a visitor creates carry theirs.

```php
Post::all();                   // the baseline plus this visitor's
Post::withoutSandbox()->get(); // everybody's, said on purpose
```

`withoutSandbox()` is for the code that genuinely has to see everything — a count
in a dashboard, an admin screen the demo itself provides. It has a name because
"show me other people's rows" should be a deliberate act.

## How identity works

The identifier lives in the **session**, not a cookie of its own. Laravel's
session cookie is already signed and encrypted, so there is no new trust boundary
and nothing new to get wrong.

It is treated as **untrusted regardless**. It is a lookup key: the row has to
exist and not have expired, or a fresh empty sandbox is minted. So the worst a
forged, guessed or replayed identifier achieves is a new sandbox of one's own —
never somebody else's rows.

That distinction is the point. A global scope built from user input is an
access-control decision made from user input; this one reads the resolved sandbox,
never the request.

The write side matters just as much. Demo models are usually written with
`$guarded = []`, so a visitor posting `demo_sandbox_id` alongside the rest of a
form could otherwise plant a row in somebody else's sandbox, or publish one to
everybody by sending null. Whatever arrives is **overwritten** with the visitor's
own. When no sandbox is resolved — a seeder, a console command — whatever was set
stands, which is how the shared baseline gets its nulls.

Identifiers are ULIDs, so nothing can enumerate other sandboxes by counting even
though holding one buys nothing.

## Expiry and pruning

```php
'ttl'   => 3600,
'prune' => '*/15 * * * *',
```

The middleware pushes the expiry out on every request, so a sandbox lives as long
as somebody keeps using it. `demo:sandbox:prune` is registered on the schedule by
the package and removes the ones nobody came back to — without it the table grows
for as long as the demo is up, and so does every scoped query's index.

`SandboxExpired` fires for each one pruned, which is the seam for anything kept
per visitor outside the database — an uploaded file, a search index entry.

The rows a visitor created are **not** deleted by pruning. They keep their
sandbox id and go with the next reset, along with everything else. Pruning does
not go looking for them, because it has no way to know which tables an application
marked and a command that guessed would delete the wrong thing.

## What the scope does not cover

It is an Eloquent global scope, so it applies to anything that goes through the
model — `find()`, `findOrFail()`, route-model binding, relationships from an
unmarked parent, eager loading, aggregates, `firstOrCreate()`. All of those are
scoped correctly.

What it does not see is a query that never touches Eloquent:

```php
DB::table('posts')->get();   // every visitor's rows, no warning
```

Nothing detects that — `demo:doctor` can check the trait and the column exist, not
how every query is written. If your demo has reporting or an admin screen built on
the query builder, it reads across sandboxes.

## If the database errors

A transient failure while resolving a sandbox **fails the request**. That is
deliberate: the alternative was answering null, which made the scope add no
constraint at all and showed one visitor everybody's rows for that request. A 500
is the correct outcome.

A sandboxes table that was never migrated is a different thing — a feature that is
not set up rather than a transient error — and serves unscoped, which `demo:doctor`
reports as an error.

## What has no sandbox

Anything without a session: console commands, queued jobs, API routes. They see
everything, unscoped, which is right for a seeder and worth knowing for a job.

A job that acts on one visitor's data sees all of it. Pass the sandbox id into the
job and filter explicitly if that matters.
