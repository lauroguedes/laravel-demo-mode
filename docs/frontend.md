# Blade, Livewire and Inertia

One payload, three front ends. The package does not know or care which one it is
talking to.

## Blade

```blade
{{-- Your layout --}}
<x-demo-banner />

{{-- Your login page --}}
<x-demo-credentials />
```

Both render **nothing at all** when the installation is not a demo, so they are
safe in a layout unconditionally. That matters more than it sounds: if they
rendered anything, every application would have to wrap them in `@demo`, and the
one that forgot would tell its real users their data was temporary.

### Conditionals

```blade
@demo
    <p>Sign in with the credentials below.</p>
@enddemo

@notdemo
    <a href="{{ route('oauth.google') }}">Sign in with Google</a>
@endnotdemo
```

`@notdemo` has to work precisely when the flag is off, so both are registered on
every installation. An unregistered directive is a compile error, not a false
condition.

## Livewire

Nothing special. Read the facade:

```php
class Layout extends Component
{
    public function render(): View
    {
        return view('layout', ['demo' => Demo::toArray()]);
    }
}
```

Or use the components directly in a Livewire view — they are ordinary Blade
components.

## Inertia

The package does not know the word Inertia, and deliberately: every Inertia
application already has a `HandleInertiaRequests::share()`, which is the seam
where ordering and partial reloads are controlled. One line there beats a
`class_exists()` branch inside a package that does not require Inertia.

```php
// app/Http/Middleware/HandleInertiaRequests.php
public function share(Request $request): array
{
    return [
        ...parent::share($request),
        'demo' => fn () => Demo::toArray(),
    ];
}
```

A closure, so a partial reload that does not ask for `demo` computes nothing.

## The payload

```ts
const { demo } = usePage().props

// {
//   enabled:       true,
//   next_reset_at: '2026-09-22T18:00:00+00:00',
//   resets_in:     '2 hours',
//   last_reset_at: '2026-09-22T12:00:00+00:00',
//   credentials:   { email: 'admin@demo.test', password: 'Xk93mPq2Lr8v', label: 'Administrator' },
//   accounts:      [ /* every published account, same shape */ ],
//   banner:        { message: '…', variant: 'warning', class: 'alert alert-warning',
//                    dismissible: true, position: 'top', next_reset_at: '…' },
//   scripted:      true,
// }
```

On an installation that is not a demo the payload is `{ enabled: false }` and
nothing else, so `v-if="demo.enabled"` is the outer gate. Two things inside it are
also nullable and worth guarding: `banner` is `null` when `banner.enabled` is
false, and `credentials` is `null` when `expose_in_payload` is off.

`credentials` is the one account a form should prefill; `accounts` is all of them,
which is what `<x-demo-credentials />` lists. Only the `accounts` entries carry a
`primary` flag — the singular object is already the primary one, so it does not
repeat it. Both come from the same resolved
state the Blade components use — `banner.class` is the variant already resolved
against `banner.classes`, so there is no lookup rule to reimplement on the client.

`scripted` says whether the package's own script is on the page. If you render
your own dismiss or copy control, gate it on that: a control with nothing behind
it is a control that lies.

## ShareDemoState

For Blade and Livewire, the optional middleware makes `$demo` available to every
view:

```php
// bootstrap/app.php
->withMiddleware(function (Middleware $middleware): void {
    $middleware->web(append: [
        \LauroGuedes\DemoMode\Http\Middleware\ShareDemoState::class,
    ]);
})
```

Shared as a closure, so a response that never reads it computes nothing. It is not
registered by the package: where it belongs in the stack depends on your own
session and auth ordering.

### Keeping the password out of the payload

An Inertia payload is in the page source of every response it decorates,
including ones served to a crawler.

```php
'credentials' => ['expose_in_payload' => false],
```

`Demo::credentials()` still works server-side, so a Blade login page can prefill
the form. Only the shared payload loses it.

## Two styles, and why there are two

`<x-demo-banner />` renders one of two things, chosen by `banner.style`.

### `pill` — the default

A floating bar the package styles itself: a badge, the countdown, an optional
rebuild button, an optional link, and a dismiss control.

```php
'banner' => [
    'style' => 'pill',
    'label' => 'Demo',
    'cta'   => ['label' => 'Deploy your own', 'url' => 'https://github.com/…'],
],
```

It renders as a `<demo-mode-bar>` custom element that builds its interface inside
a **shadow root**, which is the entire reason it can look the same in Blade,
Livewire and Inertia without any of them passing a class. Nothing your stylesheet
says about `div` or `button` reaches inside it.

Two things a shadow root does *not* stop, both of which bit this component before
it shipped, and both of which it now handles — worth knowing if you write your
own:

- **Inherited properties still cross.** `font-weight`, `letter-spacing`,
  `text-transform` and the rest come in from the host element. Putting
  `class="font-black"` on `<x-demo-banner />` rendered the bar at weight 900.
  Don't pass classes to the pill; they cannot help it and can distort it.
- **`:host` rules lose to the outer document.** Any rule out there matching the
  element beats a `:host` rule of any specificity — Tailwind's preflight carries
  `*, ::before, ::after { margin: 0; padding: 0 }`, and it silently removed the
  bar's offset from the screen edge. All of its layout therefore lives on an
  element *inside* the shadow root.

**Inertia applications put it in the root Blade view**, next to `@inertia`, not
in a page component:

```blade
<body>
    <x-inertia::app />
    <x-demo-banner />
</body>
```

Outside the Vue or React tree on purpose: it survives a client-side visit, and no
page component has to know it exists.

The pill needs `script` to be on — a shadow root cannot be expressed as markup.
With `script => false` the style falls back to `bare` rather than rendering
nothing. Its one script is served from `/demo-mode/bar.js` (configurable as
`banner.asset_route`) rather than inlined, so `script-src 'self'` is enough for a
strict Content-Security-Policy. The URL carries a hash of the file and the
response is immutable, so it is fetched once.

### `bare` — no CSS at all

Semantic markup with no styling, wearing the class names you supply. For a demo
that wants the notice to look like the rest of the application, or one whose
policy forbids the script.

A package cannot know whether it is inside Tailwind, daisyUI, Bootstrap or
someone's own stylesheet, and a component that guesses is one every application
rewrites — which is what this style is for, and what the pill sidesteps rather
than solves.

What you get is semantic markup and hooks:

```html
<div role="status" aria-live="polite" data-demo-banner
     data-demo-variant="warning" data-demo-position="top"
     data-demo-reset-at="2026-09-22T18:00:00+00:00">
    <p data-demo-banner-message>This is a demonstration. Everything you change
       here is deleted in <time datetime="…" data-demo-countdown>2 hours</time>.</p>
    <button type="button" data-demo-dismiss aria-label="Dismiss">…</button>
</div>
```

The common case is one config line:

```php
'banner' => [
    'style'   => 'bare',
    'classes' => [
        'warning' => 'alert alert-warning',
        'danger'  => 'alert alert-danger',
    ],
],
```

`classes` is read by this style only. A class name means nothing to the pill.

Anything more, publish the views:

```bash
php artisan vendor:publish --tag=demo-views
```

### Tailwind

Tailwind scans your own source, not `vendor/`, so class names coming from
`config/demo.php` are in a file Tailwind reads and need no extra configuration.
If you publish the views, add `resources/views/vendor/demo` to your content paths.

## The script

The package emits one small inline script, once per response, on a demo only: the
ticking countdown, the dismiss button, and copy-to-clipboard on the credentials
component. No dependencies, no build step.

```php
'script' => false,
```

Set that under a strict Content-Security-Policy. Everything still renders and
every value is still on the page — the countdown stops ticking and the buttons
stop doing anything. Publish the views and move the script into your own bundle
if you want them back.

Dismissal lasts for the page it happened on and nothing longer. A reload or a
link brings the notice back, on purpose: it used to be remembered in
`sessionStorage`, which survives a reload and is not cleared with the cache, so a
visitor who hid it had no obvious way to get it back — and the sentence saying
the data is temporary is the one thing on a demo that should be hard to lose.

With `script => false` the dismiss and copy buttons are **not rendered at all**.
They would do nothing when clicked, and a control that lies is worse than one
that is absent.

## Translations

English and Brazilian Portuguese ship with the package.

```bash
php artisan vendor:publish --tag=demo-translations
```

A translated sentence keeps `:time` wherever its word order needs it. Translation
files are escaped as data, so one cannot inject markup into every page of your
application.
