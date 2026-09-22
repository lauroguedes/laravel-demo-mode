<?php

declare(strict_types=1);

namespace LauroGuedes\DemoMode\Restrictions;

use Illuminate\Auth\Events\Attempting;
use Illuminate\Auth\Events\Login;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;
use LauroGuedes\DemoMode\Contracts\Restriction;
use LauroGuedes\DemoMode\Support\Options;

/**
 * Keeps the real administrator out of the demo.
 *
 * A demo seeded from a production-shaped seeder often has a genuine super-admin
 * in it, and a demo that publishes one password tends to accumulate others in
 * documentation. This closes the accounts a visitor should never reach even if
 * they somehow have the password — the account that can delete tenants, read
 * every record, or change what the demo is.
 *
 * Listens on Login rather than Attempting so that it sees the resolved user and
 * can match on a role, which is the useful form: an application knows its
 * privileged accounts by role far more often than by address.
 *
 * Throws a ValidationException against the email field, so the application's own
 * login form renders it the way it renders a wrong password — no special-casing
 * in the view, and no leak about which part failed.
 */
final readonly class BlockPrivilegedAccounts implements Restriction
{
    public function __construct(private Dispatcher $events) {}

    public function apply(array $options): void
    {
        $roles = Options::strings($options['roles'] ?? []);
        $emails = array_map(mb_strtolower(...), Options::strings($options['emails'] ?? []));
        $message = is_string($options['message'] ?? null)
            ? $options['message']
            : (string) trans('demo::demo.errors.privileged_account');

        if ($roles === [] && $emails === []) {
            return;
        }

        /*
         * Static closures on purpose: a listener bound to $this keeps the
         * restriction — and through it the dispatcher — in a reference cycle
         * held for the whole request, for no gain beyond reaching a method.
         */
        $this->events->listen(Login::class, static function (Login $event) use ($roles, $emails, $message): void {
            if (self::isPrivileged($event->user, $roles, $emails)) {
                auth()->guard($event->guard)->logout();

                throw ValidationException::withMessages(['email' => $message]);
            }
        });

        $this->events->listen(Attempting::class, static function (Attempting $event) use ($emails, $message): void {
            $email = $event->credentials['email'] ?? null;

            if (is_string($email) && in_array(mb_strtolower($email), $emails, true)) {
                throw ValidationException::withMessages(['email' => $message]);
            }
        });
    }

    public function describe(): string
    {
        return 'Privileged accounts cannot sign in';
    }

    /**
     * @param  list<string>  $roles
     * @param  list<string>  $emails
     */
    private static function isPrivileged(Authenticatable $user, array $roles, array $emails): bool
    {
        if ($emails !== [] && $user instanceof Model) {
            $email = $user->getAttribute('email');

            if (is_string($email) && in_array(mb_strtolower($email), $emails, true)) {
                return true;
            }
        }

        if ($roles === []) {
            return false;
        }

        /*
         * Role membership is asked for through whatever the application already
         * uses — spatie/laravel-permission's hasAnyRole(), a hasRole() of its
         * own, or nothing at all. Asking rather than requiring a package keeps
         * this usable without adding a dependency that most applications that
         * need it already have.
         */
        foreach (['hasAnyRole', 'hasRole'] as $method) {
            if (method_exists($user, $method)) {
                return (bool) $user->{$method}($roles);
            }
        }

        return false;
    }
}
