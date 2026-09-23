<?php

declare(strict_types=1);

namespace LauroGuedes\DemoMode\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use LauroGuedes\DemoMode\Configuration;
use Symfony\Component\HttpFoundation\Response;

/**
 * The host check, in front of everything else on the on-demand route.
 *
 * It lives in middleware rather than in the controller because of what runs in
 * between. The throttle is middleware, so a request that the controller would
 * have 404'd had already spent a rate-limit slot getting there — and Laravel does
 * no host matching of its own, so a POST with any Host header reaches the stack.
 * With 'per' set to 'global' and one attempt an hour, a single request from
 * anywhere took the reset button away from every real visitor for an hour.
 *
 * Registered first, so nothing is counted, no session is started and no CSRF
 * token is consulted for a request that was never going to be answered.
 */
class EnsureDemoHost
{
    public function __construct(private readonly Configuration $config) {}

    public function handle(Request $request, Closure $next): Response
    {
        $allowed = $this->config->nullableStrings('allowed_hosts');

        if ($allowed === null) {
            return $next($request);
        }

        /*
         * 404 rather than 403: on a host this demo was never meant to be, the
         * route not existing is the honest description, and it says nothing
         * about where it does exist.
         */
        abort_unless(
            in_array(mb_strtolower($request->getHost()), array_map(mb_strtolower(...), $allowed), true),
            404,
            (string) trans('demo::demo.errors.not_found'),
        );

        return $next($request);
    }
}
