# Reset on demand

An HTTP route for "I broke it, let me start over" — a real thing visitors want.

**What it resets depends on the sandbox driver.** On a `scoped` demo it clears
the visitor's own rows and leaves the installation alone, which is cheap and
costs nobody else anything; see
[sandbox.md](sandbox.md#letting-a-visitor-start-over). Everywhere else it
rebuilds the whole demonstration — a route that hands an anonymous stranger a
`migrate:fresh` — so it is off by default and the rest of this page is the
controls that make it survivable.

```php
'on_demand' => ['scope' => 'auto'],   // 'sandbox' or 'everything' to decide it yourself
```

```php
'on_demand' => [
    'enabled'    => env('DEMO_ON_DEMAND', false),
    'route'      => '/demo/reset',
    'name'       => 'demo.reset',
    'middleware' => ['web'],
    'throttle'   => ['attempts' => 1, 'minutes' => 60],
    'per'        => 'ip',
    'cooldown'   => 900,
    'queue'      => true,
    'redirect'   => null,
],
```

```blade
<form method="POST" action="{{ route('demo.reset') }}">
    @csrf
    <button type="submit">Reset this demonstration</button>
</form>
```

## What it resets

**The whole demonstration, not the visitor's own corner of it.** Whatever anybody
else was partway through goes with it.

On a demo more than one person looks at, [per-visitor isolation](sandbox.md) is
the thing that actually wants building. This is for a single-visitor playground
somebody has got stuck in.

## Three limits, stopping three different things

| | Stops |
|---|---|
| the throttle | one visitor pressing the button repeatedly |
| the cooldown | many visitors each pressing it once |
| the Runner's lock | two rebuilds overlapping, whatever asked for them |

None substitutes for another. A throttle counts per visitor, so fifty visitors
with one request each are fifty rebuilds — that is what the cooldown is for. And
the cooldown alone is not enough either, because a throttle is what stops the
cooldown check itself being hammered.

The cooldown reads the **recorded** reset, so a rebuild from the scheduler or the
command line also starts the clock. A visitor pressing the button ten seconds
after the cron ran is told to wait rather than handed a second rebuild.

### `per`

How the throttle counts a visitor: `ip`, `session` or `global`.

Behind a proxy, `ip` is only as trustworthy as your `TrustProxies` configuration
— every visitor may look like the load balancer, in which case one of them
exhausts the limit for all of them. `session` counts a browser instead, which is
easier to discard but harder to accidentally share.

**Neither is a boundary against somebody determined.** A session cookie can be
deleted and an IP can be changed, so the throttle stops accidents and casual
repetition, not a person who wants to keep your demo rebuilding. **The cooldown
is the limit that holds**, because it counts resets rather than requesters. Set
it.

## Keep `web` in the middleware

That is where CSRF comes from. Without it, any page on the internet can rebuild
your demo with a form post the visitor never saw. `demo:doctor` treats a
middleware list without `web` as an **error**.

Consider adding `auth`:

```php
'middleware' => ['web', 'auth'],
```

"I broke the demo" is something a signed-in visitor asks.

## Keep it off the request

A rebuild takes as long as it takes. Inline, that is a visitor watching a spinner
until their browser or the proxy in front of your application gives up — at which
point the reset carries on invisibly and they press the button again.

Queued, the route answers `202` immediately and a worker does the work. The job
is **unique**, so two visitors pressing together queue one rebuild rather than
two.

`demo:doctor` warns when `queue` is true but `QUEUE_CONNECTION` is `sync`, which
runs it inline anyway.

**On Octane there is a second reason to queue it.** A reset stands the connection
and protected-record guards down for its duration, and that window is a flag in
the PHP process rather than something scoped to one request. Under PHP-FPM, or in
a queue worker, the process is doing nothing else and the distinction does not
arise. On a coroutine-based Octane worker running the reset *inline*, the
rebuild's database round-trips yield, and another request that worker picks up
runs inside the open window. Queued — the default — the reset happens in a worker
process that serves no requests at all.

## The lock is only as real as your cache store

The Runner takes a lock so two rebuilds cannot overlap, and it lives in the
cache. Two stores make it decorative:

- **`null`** implements Laravel's lock interface and grants every lock to
  everybody, so the check passes and both rebuilds proceed.
- **`array`** keeps locks inside one PHP process, so a rebuild in a queue worker
  and one in a web request never see each other's lock — and the last-reset
  timestamp the cooldown reads is forgotten between requests.

`demo:doctor` errors on the first and warns on the second. Use file, database,
redis or memcached on anything serving more than one process.

## What it answers

| Status | When |
|---|---|
| `202` | queued |
| `200` | rebuilt inline |
| `429` | inside the cooldown, with `Retry-After` |
| `429` | over the throttle |
| `409` | a rebuild already holds the lock |
| `503` | the guards refused |
| `404` | the request arrived on a host not in `allowed_hosts` |

JSON requests get a JSON body. Browsers get an abort page, or a redirect with a
flashed `status`/`error` when `redirect` is set to `back` or a path.

**The `503` says nothing about why.** Refusal reasons name environments,
hostnames and database settings; they go to the log the Runner writes, not to
whoever pressed the button.

**The `404` is deliberate.** The host check runs against the host that actually
arrived, not only the one in `APP_URL` — an application reachable on two
hostnames should not be resettable from the one that was never meant to be a
demo. It answers "not found" rather than "forbidden", because the route not
existing there is the honest description.
