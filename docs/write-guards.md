# Write guards

Three independent layers, all opt-in, in the order you should reach for them.

| Layer | What it stops | Default |
|---|---|---|
| `guards.protected` | A visitor changing specific records | empty — **fill this in** |
| `guards.read_only` | Any write over HTTP | off |
| `guards.connection` | Any write at all | off, and dangerous |

## Protected records — the one that matters

A visitor signs in with the published credentials, opens the profile page, and
changes the email. Both are ordinary features working correctly. From that
moment until the next reset the demo's login page shows credentials that do not
work and nobody else can get in — on a six-hour cycle, the demo is down for up
to six hours because one person did something entirely reasonable.

```php
'guards' => [
    'protected' => [
        \App\Models\User::class => ['email' => 'admin@demo.test'],
    ],
],
```

`demo:doctor` warns when this is empty. Neither of the two projects this package
was extracted from covered it, which is why it gets a warning rather than a
mention.

### What it checks

Every attribute in the map has to match. Both the **stored** values and the
**incoming** ones are checked, because guarding only the stored row would let a
visitor rename the published account to something else and then own a record
that no longer matches.

Creation is not guarded. A record that does not exist yet is not the protected
record — it is your seeder making one, and a guard that blocked that would fail
on the demo's own first reset. The remaining gap is a visitor creating a *second*
row claiming the same identity; a unique index on the matched attribute closes
it, and any application publishing credentials by email already has one.

### What it does not catch

This is Eloquent model events, so it sees writes that go through a model
instance and nothing else:

| Not caught | Why |
|---|---|
| `User::where(…)->update([...])` | `Eloquent\Builder::update()` calls straight through to the query builder — no per-model events |
| `DB::table('users')->update([...])` | never touches Eloquent |
| `$user->updateQuietly([...])`, `saveQuietly()` | events suppressed by definition |
| `Model::withoutEvents(fn () => …)` | likewise |

None of these is a path a visitor can *choose* — a visitor can only do what your
controllers do. The gap matters exactly when your own code writes to the
protected record one of those ways, which is worth checking once. The
[connection guard](#the-connection-guard) is the only layer nothing routes
around.

### A closure, when attributes are not enough

```php
'protected' => [
    \App\Models\User::class => fn (User $user): bool => $user->hasRole('admin'),
],
```

### Declaring it on the model instead

```php
use LauroGuedes\DemoMode\Guards\PreventsDemoWrites;

class User extends Authenticatable
{
    use PreventsDemoWrites;
}
```

Same guard, same matcher, same exception — only the place it is written down
differs. The model still needs an entry in `guards.protected` to say *which* of
its records are protected; without one the trait does nothing, which is the safe
way round. A trait that guessed would be one that froze your whole users table.

## Read-only

```php
'read_only' => [
    'enabled'  => env('DEMO_READ_ONLY', false),
    'except'   => ['login', 'logout', 'register', 'password.request'],
    'methods'  => ['POST', 'PUT', 'PATCH', 'DELETE'],
    'redirect' => null,
],
```

```php
// bootstrap/app.php
$middleware->web(append: ['demo.readonly']);
```

The middleware is registered as an alias, not pushed into the web group: where it
belongs depends on your own session and auth ordering.

Off by default. A playground exists to be written to, and a read-only demo
demonstrates less. It earns its place on a demo whose data is expensive to
rebuild, or one showing something there is no safe way to let a stranger change.

**`except` is by route name**, because a URL is not a stable thing to write in a
config file. That also means a route with no name cannot be excepted and **will
be blocked**. Fail-closed is right for a guard, but it means turning this on can
break a POST somebody forgot to name.

Signing in has to stay on the list, or the demo is a screenshot.

### A message instead of an error page

```php
'redirect' => 'back',   // or a path
```

A visitor who clicked "save" and got a 403 learns that the demo is broken; one
who lands back on the form with a flashed `error` message learns that it is a
demo. JSON requests still get the 403 either way.

## The connection guard

```php
'connection' => [
    'enabled'       => true,
    'except_tables' => ['sessions', 'cache', 'jobs'],
],
```

Rejects anything that is not a read at the connection itself, which is the only
place a write cannot be routed around — no controller, no job, no console
command, no raw `DB::statement` gets past it.

> **Off by default and genuinely dangerous.** The false positives are not edge
> cases, they are the framework working normally. Database-backed sessions write
> on every request. So do the cache, the queue, job batches and failed jobs.
> Every one has to be on the exception list before the demo can serve a page, and
> the way it fails — 403 on the first request — reads like the guard working
> rather than the guard misconfigured.

`demo:doctor` checks this against what your application actually uses, and errors
when a table it writes to on ordinary requests is missing from the list.

**The reset is exempt.** A rebuild drops and recreates every application table,
none of which is on the exception list — so without lifting the guard for the
duration of a reset, the demo could never rebuild itself again, and the scheduler
would fail quietly every six hours. The Runner lifts it for exactly as long as it
lifts Laravel's own destructive-command prohibition.

A statement whose verb or table this guard cannot parse is **blocked**, not
allowed. The worst an unrecognised statement can do is refuse a write.

Use the other two first. This is for the case where they are not enough and you
know exactly which tables move.

## What happens on a block

Every layer dispatches `WriteBlocked`:

```php
Event::listen(function (WriteBlocked $event): void {
    // $event->layer   'model' | 'http' | 'connection'
    // $event->subject the model class, route name or table
    // $event->reason
});
```

The event always fires. The log line is behind `demo.log.blocked_writes`, because
a misconfigured connection guard blocks every request and a package that filled
your log aggregator by default would be teaching you to turn the whole thing off.

`DemoWriteProhibited` renders as **403**, not 500 — this is the application
refusing, not failing, and an error page saying "server error" sends people
looking for a bug that is not there.
