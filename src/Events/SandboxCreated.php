<?php

declare(strict_types=1);

namespace LauroGuedes\DemoMode\Events;

/**
 * A visitor arrived and got a corner of their own.
 *
 * Carries the identifier, which is not a secret: it is a lookup key with no
 * authority of its own, and holding one proves nothing.
 */
final readonly class SandboxCreated
{
    public function __construct(public string $id) {}
}
