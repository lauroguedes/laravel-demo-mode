<?php

declare(strict_types=1);

namespace LauroGuedes\DemoMode\Exceptions;

/**
 * A published config file says something the package cannot act on.
 *
 * Thrown at the point of reading rather than at the point of use, because the
 * point of use may be halfway through a reset.
 */
class InvalidConfiguration extends DemoModeException
{
    public static function expected(string $key, string $expected, mixed $got): self
    {
        return new self(sprintf(
            'Configuration [%s] should be %s, got %s.',
            $key,
            $expected,
            get_debug_type($got),
        ));
    }

    /**
     * @param  list<string>  $available
     */
    public static function unknownStrategy(string $name, array $available): self
    {
        return new self(sprintf(
            'Reset strategy [%s] is not configured. Available: %s.',
            $name,
            $available === [] ? 'none' : implode(', ', $available),
        ));
    }

    public static function unknownCredentialStore(string $name): self
    {
        return new self(sprintf('Credential store [%s] is not configured.', $name));
    }

    public static function unreadableSchedule(string $expression): self
    {
        return new self(sprintf(
            'Reset schedule [%s] is neither a cron expression nor one of hourly, daily, weekly, every-N-hours.',
            $expression,
        ));
    }
}
