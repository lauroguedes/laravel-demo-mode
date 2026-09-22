<?php

declare(strict_types=1);

namespace LauroGuedes\DemoMode\Exceptions;

/**
 * Another reset holds the lock.
 *
 * Separate from ResetRefused because it is the one refusal that is not a
 * misconfiguration: it means the package is working, and waiting is the fix.
 * --force does not get past it — two concurrent migrate:fresh runs on one
 * database is how a demo ends up with half a schema.
 */
class ResetInProgress extends DemoModeException
{
    public static function make(): self
    {
        return new self('Another demo reset is already running. Wait for it to finish.');
    }
}
