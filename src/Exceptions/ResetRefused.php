<?php

declare(strict_types=1);

namespace LauroGuedes\DemoMode\Exceptions;

/**
 * A guard said no.
 *
 * Carries every failure rather than the first, because someone setting up a demo
 * should learn all of what is wrong in one run instead of discovering the next
 * problem after fixing this one. Nothing destructive has happened when this is
 * thrown; that is the whole contract.
 */
class ResetRefused extends DemoModeException
{
    /**
     * @param  list<string>  $reasons
     */
    public function __construct(public readonly array $reasons)
    {
        parent::__construct(
            "A demo reset was refused:\n".implode("\n", array_map(
                static fn (string $reason): string => '  - '.$reason,
                $reasons,
            )),
        );
    }
}
