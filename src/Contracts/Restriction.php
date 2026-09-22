<?php

declare(strict_types=1);

namespace LauroGuedes\DemoMode\Contracts;

/**
 * Something a public demonstration is not allowed to do.
 *
 * Applied during boot, only while the flag is on. The package ships four and an
 * application registers its own, because what a demo must not do is specific to
 * what the application does — this package can tell you not to send mail, and has
 * no idea whether you also need to stop it charging cards.
 */
interface Restriction
{
    /**
     * @param  array<string, mixed>  $options
     */
    public function apply(array $options): void;

    /**
     * One line for 'demo:status'.
     */
    public function describe(): string;
}
