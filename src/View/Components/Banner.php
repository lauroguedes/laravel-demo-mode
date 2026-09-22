<?php

declare(strict_types=1);

namespace LauroGuedes\DemoMode\View\Components;

use Illuminate\View\Component;
use LauroGuedes\DemoMode\DemoMode;
use LauroGuedes\DemoMode\View\BannerState;

/**
 * The notice that says the data is temporary.
 *
 * Renders nothing at all when this is not a demo, which is what makes it safe to
 * drop into a layout unconditionally rather than wrapping every use in @demo.
 *
 * Ships no opinionated styling. A package cannot know whether it is inside
 * Tailwind, daisyUI, Bootstrap or someone's own stylesheet, and a component that
 * guesses is one every application immediately publishes and rewrites. What it
 * does provide is semantic markup, a role, the variant and position as data
 * attributes, and a class name from the config — so the common case is one config
 * line rather than a published view.
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
    ) {}

    public function shouldRender(): bool
    {
        return $this->state() instanceof BannerState;
    }

    public function render(): string
    {
        return 'demo::components.banner';
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
}
