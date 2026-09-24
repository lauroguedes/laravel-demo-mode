<?php

declare(strict_types=1);

namespace LauroGuedes\DemoMode\View\Components;

use Illuminate\Container\Container;
use Illuminate\View\Component;
use LauroGuedes\DemoMode\DemoMode;
use LauroGuedes\DemoMode\View\BannerState;

/**
 * The notice that says the data is temporary.
 *
 * Renders nothing at all when this is not a demo, which is what makes it safe to
 * drop into a layout unconditionally rather than wrapping every use in @demo.
 *
 * Renders in one of two styles, and the difference is where the CSS lives.
 *
 * 'bare' ships no styling at all: semantic markup, a role, the variant and
 * position as data attributes, and a class name from the config. A package
 * cannot know whether it is inside Tailwind, daisyUI or Bootstrap, and a
 * component that guesses is one every application rewrites.
 *
 * 'pill' escapes that question rather than answering it. The bar renders inside
 * a shadow root, where the host's reset, preflight and theme do not reach — so
 * the package can style it fully and have it look the same everywhere. That is
 * also why it needs the script: a shadow root cannot be expressed as markup.
 * Without it the style falls back to 'bare' rather than rendering nothing.
 *
 * Everything it shows comes from DemoMode::banner(). It reads no config of its
 * own, deliberately: this component and the Inertia payload used to resolve the
 * banner separately from the same keys, each with its own copy of the defaults,
 * and they had already drifted.
 *
 * The constructor is kept cheap because Blade builds a component before it asks
 * shouldRender(). Anything expensive here would be paid by every page of every
 * installation with this package, demo or not — and the @demo wrapper that used
 * to cover for that is gone precisely because this component answers for itself.
 */
class Banner extends Component
{
    private ?BannerState $state = null;

    private bool $resolved = false;

    /**
     * Attributes win over config, so one page can differ from the rest without a
     * second config key — a red banner on a destructive screen, say.
     *
     * Nullable on purpose: null means "the config decides", which is a different
     * instruction from an explicitly passed false.
     */
    public function __construct(
        private readonly DemoMode $demo,
        private readonly ?string $variant = null,
        private readonly ?bool $dismissible = null,
        private readonly ?string $position = null,
        private readonly ?string $message = null,
        private readonly ?string $style = null,
    ) {}

    public function shouldRender(): bool
    {
        return $this->state() instanceof BannerState;
    }

    public function render(): string
    {
        return $this->state()?->style === 'pill'
            ? 'demo::components.bar'
            : 'demo::components.banner';
    }

    /**
     * Everything the floating bar needs, as one JSON payload on the element.
     *
     * The element builds its own DOM inside a shadow root, so nothing about it
     * can be expressed as markup the server renders. What the server still owns
     * is every decision: the sentence and its word order, the labels, whether
     * there is a reset button and where it posts. The element reads, it does not
     * choose.
     *
     * The CSRF token is composed here rather than held on the state, because the
     * state is memoised per render and a token is per request — a cached one is
     * a reset button that fails the moment the session rotates.
     *
     * @return array<string, mixed>
     */
    public function payload(): array
    {
        $state = $this->state();

        return $state instanceof BannerState
            ? [...$state->forBar($this->units()), 'token' => $state->resetUrl === null ? null : $this->token()]
            : [];
    }

    public function script(): string
    {
        return $this->demo->barScriptUrl();
    }

    /**
     * The resolved banner, or null when there is nothing to show.
     *
     * Memoised for the life of the component, which is one render. The state
     * evaluates the cron expression, and the view asks for it several times —
     * once for the sentence, once for the reset timestamp, once per data
     * attribute. Memoising on the singleton DemoMode would risk a stale date
     * under Octane; memoising here cannot, and it also stops the countdown
     * element and the data attribute being computed from two clock readings.
     */
    public function state(): ?BannerState
    {
        if (! $this->resolved) {
            $this->resolved = true;

            $base = $this->demo->banner();

            $this->state = $base instanceof BannerState ? new BannerState(
                variant: $this->variant ?? $base->variant,
                class: $base->class,
                dismissible: $this->dismissible === null
                    ? $base->dismissible
                    : ($this->dismissible && $this->demo->scripted()),
                position: $this->position ?? $base->position,
                message: $this->message ?? $base->message,
                nextResetAt: $base->nextResetAt,
                resetsIn: $base->resetsIn,
                style: $this->style ?? $base->style,
                label: $base->label,
                cta: $base->cta,
                resetUrl: $base->resetUrl,
                resetScope: $base->resetScope,
            ) : null;
        }

        return $this->state;
    }

    /**
     * The short unit words the ticking countdown substitutes in.
     *
     * Read here rather than hardcoded in the script, because the first tick
     * overwrites whatever the server rendered — hardcoding "h" and "m" meant a
     * Portuguese demo showed "30 minutos" for one frame and "30m 0s" after.
     *
     * @return array{hour: string, minute: string, second: string}
     */
    public function units(): array
    {
        return [
            'hour' => (string) trans('demo::demo.banner.units.hour'),
            'minute' => (string) trans('demo::demo.banner.units.minute'),
            'second' => (string) trans('demo::demo.banner.units.second'),
        ];
    }

    /**
     * The CSRF token, when there is a session to take one from.
     *
     * csrf_token() throws without one, and a banner is dropped into layouts that
     * are also rendered outside a request — a mail view, a queued PDF, a console
     * preview. Refusing to render the whole page because a control on it needs a
     * token is the wrong trade; the button simply posts without one and is
     * refused, which is what CSRF protection is for.
     */
    private function token(): ?string
    {
        $request = Container::getInstance()->make('request');

        return $request->hasSession() ? $request->session()->token() : null;
    }
}
