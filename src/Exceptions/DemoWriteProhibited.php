<?php

declare(strict_types=1);

namespace LauroGuedes\DemoMode\Exceptions;

use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

/**
 * A visitor tried to write something the demo keeps back.
 *
 * Almost always the published account. If its email or password can be changed,
 * the next visitor cannot sign in, and on a six-hour cycle the demo is closed for
 * up to six hours because one person did something entirely reasonable.
 *
 * Extends the package's own base rather than Symfony's HttpException, because
 * PHP has single inheritance and catching the whole package with one type is
 * worth more than inheriting two short methods. Hence the interface by hand.
 *
 * Rendered as 403 rather than 500: this is the application refusing, not
 * failing, and an error page that says "server error" sends people to look for a
 * bug that is not there.
 *
 * Which is also why the message is a translated sentence rather than a technical
 * one. Laravel passes an HttpExceptionInterface to the error view, and the stock
 * 403 view prints its message — so whatever is in here is shown to the visitor.
 * The model class and the precise rule go to the log through Guards\Blocker,
 * where a developer will look for them and a stranger will not.
 */
class DemoWriteProhibited extends DemoModeException implements HttpExceptionInterface
{
    public function __construct(string $message, ?Throwable $previous = null)
    {
        parent::__construct($message, 0, $previous);
    }

    /**
     * Something specific was kept back — the published account, usually.
     */
    public static function write(): self
    {
        return new self((string) trans('demo::demo.errors.write_prohibited'));
    }

    /**
     * Nothing can be written here at all, whether by HTTP or at the connection.
     */
    public static function readOnly(): self
    {
        return new self((string) trans('demo::demo.errors.read_only'));
    }

    public function getStatusCode(): int
    {
        return 403;
    }

    /**
     * Required by the interface and deliberately empty: a refusal needs no
     * Retry-After or WWW-Authenticate, and inventing somewhere for headers to
     * come from would be API surface nothing asked for.
     *
     * @return array<string, string>
     */
    public function getHeaders(): array
    {
        return [];
    }
}
