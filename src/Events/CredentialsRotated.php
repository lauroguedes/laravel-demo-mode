<?php

declare(strict_types=1);

namespace LauroGuedes\DemoMode\Events;

/**
 * New passwords were published.
 *
 * Carries how many and for which addresses, never the passwords. An event is
 * broadcast, logged and serialised by things this package does not control, and a
 * password that reaches any of them stops being retired by the next rotation.
 *
 * Read the values through Demo::credentials(), which is gated on the flag.
 */
final readonly class CredentialsRotated
{
    /**
     * @param  list<string>  $emails
     */
    public function __construct(public array $emails)
    {
        //
    }

    public function count(): int
    {
        return count($this->emails);
    }
}
