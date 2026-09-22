<?php

declare(strict_types=1);

namespace LauroGuedes\DemoMode\Http\Middleware;

use Closure;
use Illuminate\Contracts\View\Factory as ViewFactory;
use Illuminate\Http\Request;
use Inertia\Inertia;
use LauroGuedes\DemoMode\DemoMode;
use Symfony\Component\HttpFoundation\Response;

/**
 * Puts the demo state where a front end can read it.
 *
 * One payload for Blade, Livewire and Inertia, which is what "front-end
 * agnostic" comes down to in practice: all three read the same array and none of
 * them needs the package to know which one it is talking to.
 *
 * Shared as a closure rather than a value, so the cost is paid only by a response
 * that actually reads it — an API route that never renders anything computes
 * nothing.
 *
 * Optional, and not registered by the package. A middleware that inserted itself
 * into the web group would be deciding where in the stack it belongs, and that
 * depends on an application's own session and auth ordering.
 *
 * It does not know the word Inertia. An earlier version branched on
 * class_exists(Inertia::class) and called Inertia::share() — an undeclared soft
 * dependency on a package this one does not require, guarding a path no test here
 * can reach, duplicating a seam every Inertia application already has in its own
 * HandleInertiaRequests::share(). One documented line there is the honest depth;
 * docs/frontend.md carries it.
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

        $state = fn (): array => $this->demo->toArray();

        $this->views->share('demo', $state);

        if (class_exists(Inertia::class)) {
            Inertia::share('demo', $state);
        }

        return $next($request);
    }
}
