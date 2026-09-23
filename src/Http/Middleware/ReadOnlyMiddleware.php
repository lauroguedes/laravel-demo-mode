<?php

declare(strict_types=1);

namespace LauroGuedes\DemoMode\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use LauroGuedes\DemoMode\Configuration;
use LauroGuedes\DemoMode\Exceptions\DemoWriteProhibited;
use LauroGuedes\DemoMode\Guards\Blocker;
use Symfony\Component\HttpFoundation\Response;

/**
 * A demo nobody can write to.
 *
 * Off by default, because a playground exists to be written to and a read-only
 * demo demonstrates less. It earns its place on a demo whose data is expensive
 * to rebuild, or one showing something there is no safe way to let a stranger
 * change.
 *
 * Signing in has to keep working, or the demo is a screenshot. So do signing out
 * and whatever an application needs to get somebody to the page worth looking
 * at, which is what the 'except' list is for — by route name, because a URL is
 * not a stable thing to write in a config file.
 *
 * Named routes only, and that is worth knowing before relying on this: a route
 * with no name cannot be excepted, and will be blocked. Fail-closed is the right
 * default for a guard, but it means turning this on can break a POST somebody
 * forgot to name.
 */
class ReadOnlyMiddleware
{
    public function __construct(
        private readonly Configuration $config,
        private readonly Blocker $blocker,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        if (! $this->config->enabled() || ! $this->config->boolean('guards.read_only.enabled')) {
            return $next($request);
        }

        if (! $this->writes($request)) {
            return $next($request);
        }

        $name = $request->route() === null ? null : $request->route()->getName();

        if ($name !== null && in_array($name, $this->config->strings('guards.read_only.except'), true)) {
            return $next($request);
        }

        $this->blocker->blocked('http', $name ?? $request->path(), 'the demonstration is read-only');

        return $this->refuse($request);
    }

    private function refuse(Request $request): Response
    {
        $message = (string) trans('demo::demo.errors.read_only');

        $redirect = $this->config->nullableString('guards.read_only.redirect');

        /*
         * A redirect with a flashed message rather than an error page, when an
         * application asks for one. A visitor who clicked "save" and got a 403
         * learns that the demo is broken; one who lands back on the form with a
         * sentence learns that it is a demo.
         */
        if ($redirect !== null && ! $request->expectsJson()) {
            return redirect()->to($redirect === 'back' ? url()->previous() : $redirect)
                ->with('error', $message);
        }

        throw DemoWriteProhibited::readOnly();
    }

    /**
     * Whether this request is trying to change something.
     *
     * By default: anything that is not a known-safe method. Stated that way
     * round on purpose, because the alternative — a list of methods to block — is
     * a list that can be short, and a guard whose completeness is a config key is
     * a guard with a way to be wrong. The default has nothing to get wrong.
     *
     * An application can still narrow it, and then owns the narrowing. Both the
     * reported and the real method are checked there, because Laravel enables
     * HTTP method override in its HTTP kernel: a POST carrying _method=PUT
     * reports itself as PUT. On its own that is not a way past this — the router
     * matches on the same spoofed method, so with no PUT route the request never
     * arrives — but a narrowed list plus a route for the spoofed verb is a
     * combination not worth leaving to chance.
     */
    private function writes(Request $request): bool
    {
        $only = $this->config->nullableStrings('guards.read_only.methods');

        if ($only === null || $only === []) {
            return ! $request->isMethodSafe();
        }

        /*
         * Both the reported method and the real one, so a narrowed list cannot
         * be stepped around with _method.
         */
        $methods = array_map(mb_strtoupper(...), $only);

        return in_array(mb_strtoupper($request->method()), $methods, true)
            || in_array(mb_strtoupper($request->getRealMethod()), $methods, true);
    }
}
