<?php

declare(strict_types=1);

namespace LauroGuedes\DemoMode\View\Components;

use Illuminate\View\Component;
use LauroGuedes\DemoMode\DemoMode;

/**
 * The only JavaScript this package ships, and the single place that decides
 * whether to ship it.
 *
 * Both components include it, so the decision cannot live in either of them — it
 * was under 'banner.script' first, which meant switching it off for a strict
 * Content-Security-Policy silenced the banner's countdown and left the
 * credentials component emitting the same inline script anyway.
 *
 * The @once lives in the view rather than in the callers. Blade compiles a bare
 *
 * @once to a fresh UUID per compiled occurrence, so one block per caller meant
 * two keys, and a page with both a banner and a credentials list emitted the
 * script twice — installing its click listener twice with it.
 */
class Script extends Component
{
    public function __construct(private readonly DemoMode $demo) {}

    public function shouldRender(): bool
    {
        return $this->demo->scripted();
    }

    public function render(): string
    {
        return 'demo::components.script';
    }
}
