# Restrictions

What a public demonstration is not allowed to do. Applied at boot, only while the
flag is on.

## What ships

| Restriction | What it does |
|---|---|
| `DisableMail` | Sends everything to the `array` transport. |
| `DisableNotifications` | Refuses the channels that cost money or reach strangers. |
| `ForceConfig` | Pins config keys so a visitor cannot change them back. |
| `BlockPrivilegedAccounts` | Stops named roles or addresses signing in. |

## `DisableMail`

Every address an application sends to is an address somebody typed, and on a
public demo that somebody is anonymous. Without this, a demo is a free relay:
anyone can trigger a password reset, an invitation or a notification addressed to
any third party, sent from a domain carrying your project's reputation.

The `array` transport rather than `log`, because these messages have no reader
and the log has a size — a demo running for months writing every rendered email
to disk is a slow way to fill a volume.

## `ForceConfig` — the subtle one

Setting a value at boot is not the same as pinning it.

An application with a settings screen writes to config at request time, so a
visitor can undo at 10:01 what the boot set at 10:00. And the settings that
matter on a demo are exactly the ones whose wrong value locks the next visitor
out — re-enable mandatory email verification on a demo where mail goes to the
array transport, and nobody can complete a sign-up ever again. One visitor, using
the product exactly as designed, closing the demo to everyone after them.

```php
Restrictions\ForceConfig::class => [
    'pin' => [
        'fortify.features'                   => [],
        'settings.require_email_verification' => false,
    ],
],
```

The write still appears to succeed, which is the right trade for a demo: the
alternative is an error page on a feature a visitor was invited to explore.
Refusals are logged once per key per request, so a developer looking for why a
setting will not stick finds the reason rather than a mystery.

## `BlockPrivilegedAccounts`

```php
Restrictions\BlockPrivilegedAccounts::class => [
    'roles'   => ['super-admin'],
    'emails'  => ['owner@example.com'],
    'message' => null,
],
```

Role membership is asked for through whatever your application already uses —
`hasAnyRole()`, `hasRole()`, or nothing at all — so this works without adding a
permissions package as a dependency.

It throws a `ValidationException` against the `email` field, so your login form
renders it the way it renders a wrong password: no special-casing in the view,
and no leak about which part failed.

## A restriction that fails is not caught

Unlike a [cleaner](cleaners.md), which runs after the data is already rebuilt, a
restriction that did not apply means the demo is serving without a protection it
was configured to have. Booting anyway would hide that.

## Writing your own

The package knows to stop mail. It has no idea whether your application also
needs to stop charging cards, calling a partner API, or writing to an audit log
somebody pays per row for.

```php
namespace App\Demo;

use LauroGuedes\DemoMode\Contracts\Restriction;

final class StopChargingCards implements Restriction
{
    public function apply(array $options): void
    {
        $this->container->instance(PaymentGateway::class, new NullGateway);
    }

    public function describe(): string
    {
        return 'Payments go nowhere';
    }
}
```

Register it with options through the config:

```php
'restrictions' => [
    // …
    App\Demo\StopChargingCards::class => [],
],
```

Or without, from a service provider:

```php
use LauroGuedes\DemoMode\Restrictions\Pipeline;

Pipeline::use(App\Demo\StopChargingCards::class);
```
