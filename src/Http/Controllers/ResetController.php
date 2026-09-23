<?php

declare(strict_types=1);

namespace LauroGuedes\DemoMode\Http\Controllers;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Contracts\Bus\Dispatcher as Bus;
use Illuminate\Http\Request;
use LauroGuedes\DemoMode\Configuration;
use LauroGuedes\DemoMode\DemoMode;
use LauroGuedes\DemoMode\Exceptions\ResetInProgress;
use LauroGuedes\DemoMode\Exceptions\ResetRefused;
use LauroGuedes\DemoMode\Jobs\ResetTheDemo;
use LauroGuedes\DemoMode\Reset\Runner;
use LauroGuedes\DemoMode\Support\Options;
use Symfony\Component\HttpFoundation\Response;

/**
 * "I broke the demo, let me start over."
 *
 * A real thing visitors want, and a route that hands an anonymous stranger a
 * migrate:fresh. It is off by default, and the controls around it are the
 * feature rather than an afterthought.
 *
 * Worth saying plainly, because the route's name does not: this resets the whole
 * demonstration, not the visitor's own corner of it. Whatever anybody else was
 * partway through goes with it. On a demo that more than one person looks at,
 * the per-visitor sandbox is the thing that actually wants building; this is for
 * a single-visitor playground somebody has got stuck in.
 *
 * Three separate limits, because they stop different things:
 *
 *   the throttle    one visitor pressing the button repeatedly
 *   the cooldown    many visitors each pressing it once
 *   the lock        two resets overlapping, whatever asked for them
 *
 * The throttle alone is not enough — fifty visitors with one request each are
 * fifty rebuilds. The cooldown alone is not enough either, because a throttle is
 * what keeps the cooldown check itself from being hammered.
 */
class ResetController
{
    public function __construct(
        private readonly DemoMode $demo,
        private readonly Configuration $config,
    ) {}

    public function __invoke(Request $request, Bus $bus, Runner $runner): Response
    {
        $waitFor = $this->cooldownRemaining();

        if ($waitFor > 0) {
            return $this->respond(
                $request,
                Response::HTTP_TOO_MANY_REQUESTS,
                (string) trans('demo::demo.errors.cooldown', ['time' => CarbonImmutable::now()->addSeconds($waitFor)->diffForHumans(syntax: CarbonInterface::DIFF_ABSOLUTE)]),
                ['Retry-After' => (string) $waitFor],
            );
        }

        try {
            if ($this->config->boolean('on_demand.queue', true)) {
                $bus->dispatch(new ResetTheDemo);

                return $this->respond($request, Response::HTTP_ACCEPTED, (string) trans('demo::demo.reset.queued'));
            }

            $runner->run();
        } catch (ResetInProgress) {
            return $this->respond($request, Response::HTTP_CONFLICT, (string) trans('demo::demo.errors.in_progress'));
        } catch (ResetRefused) {
            /*
             * The reasons name environments, hostnames and database settings.
             * They belong in the log the Runner already writes to, not in a
             * response to whoever pressed the button.
             */
            return $this->respond($request, Response::HTTP_SERVICE_UNAVAILABLE, (string) trans('demo::demo.errors.unavailable'));
        }

        return $this->respond($request, Response::HTTP_OK, (string) trans('demo::demo.reset.done'));
    }

    /**
     * @param  array<string, string>  $headers
     */
    private function respond(Request $request, int $status, string $message, array $headers = []): Response
    {
        if ($request->expectsJson()) {
            return response()->json(['message' => $message], $status, $headers);
        }

        $redirect = $this->config->nullableString('on_demand.redirect');

        if ($redirect !== null) {
            /*
             * Through the redirector rather than a new RedirectResponse, because
             * only the redirector hands the response the session it needs to
             * flash with.
             */
            $response = redirect()->to(Options::redirectTarget($redirect))->withHeaders($headers);

            /*
             * Only flashed when there is somewhere to flash to. A redirect
             * configured on a route without session middleware is an application
             * mistake, and answering it with a 500 from inside this package would
             * hide which mistake it was.
             */
            return $request->hasSession()
                ? $response->with($status < 300 ? 'status' : 'error', $message)
                : $response;
        }

        abort($status, $message, $headers);
    }

    /**
     * Seconds until another reset is allowed, counted from the last one.
     *
     * Uses the recorded reset rather than a counter of its own, so a reset from
     * the scheduler or the command line also starts the clock. A visitor pressing
     * the button ten seconds after the cron ran should be told to wait, not
     * handed a second rebuild.
     */
    private function cooldownRemaining(): int
    {
        $cooldown = $this->config->integer('on_demand.cooldown', 900);
        $last = $this->demo->lastResetAt();

        if ($cooldown <= 0 || ! $last instanceof CarbonImmutable) {
            return 0;
        }

        $elapsed = CarbonImmutable::now()->diffInSeconds($last, absolute: true);

        return (int) max(0, $cooldown - $elapsed);
    }
}
