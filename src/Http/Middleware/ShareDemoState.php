<?php

declare(strict_types=1);

namespace LauroGuedes\DemoMode\Http\Middleware;

use Closure;
use Illuminate\Contracts\View\Factory as ViewFactory;
use Illuminate\Http\Request;
use LauroGuedes\DemoMode\DemoMode;
use Symfony\Component\HttpFoundation\Response;

/**
 * Puts the demo state into every Blade and Livewire view as $demo.
 *
 * Shared as a closure rather than a value, so the cost is paid only by a response
 * that actually reads it — an API route that never renders anything computes
 * nothing.
 *
 * Optional, and not registered by the package. A middleware that inserted itself
 * into the web group would be deciding where in the stack it belongs, and that
 * depends on an application's own session and auth ordering.
 *
 * Deliberately no Inertia branch: an Inertia application shares the same payload
 * from its own HandleInertiaRequests::share(), which is where partial reloads and
 * ordering are controlled. A class_exists() branch here would be an undeclared
 * dependency on a package this one does not require, on a path no test can reach.
 * See docs/frontend.md.
 */
class ShareDemoState
{
    public function __construct(
        private readonly DemoMode $demo,
        private readonly ViewFactory $views,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        if ($this->demo->disabled()) {
            return $next($request);
        }

        $this->views->share('demo', fn (): array => $this->demo->toArray());

        return $next($request);
    }
}
