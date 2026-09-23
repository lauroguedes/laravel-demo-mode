<?php

declare(strict_types=1);

namespace LauroGuedes\DemoMode\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use LauroGuedes\DemoMode\Sandbox\Manager;
use Symfony\Component\HttpFoundation\Response;

/**
 * Gives this request's visitor their corner, and keeps it alive while they are
 * using it.
 *
 * Strictly speaking optional: the Manager resolves a sandbox on first use
 * anyway, so a scoped demo works without this. What it adds is the expiry
 * renewal — without it a sandbox is pruned an hour after it was created rather
 * than an hour after the visitor stopped looking, which is the difference between
 * a TTL and a deadline.
 *
 * Runs after the session middleware, because the identifier lives in the session.
 * That is why it is an alias rather than something this package pushes into the
 * web group: the ordering is the application's to state.
 */
class AttachSandbox
{
    public function __construct(private readonly Manager $sandboxes) {}

    public function handle(Request $request, Closure $next): Response
    {
        $this->sandboxes->touch();

        return $next($request);
    }
}
