<?php

declare(strict_types=1);

namespace LauroGuedes\DemoMode\Events;

/**
 * A visitor threw away what they had made.
 *
 * The seam for whatever an application keeps per visitor outside the tables the
 * package knows about — an uploaded file, a search index entry, a cached total.
 * SandboxExpired says the same thing about a sandbox nobody came back to; this
 * one says it about somebody who is still here and pressed the button.
 *
 * The id is null when there was nothing to clear: a visitor who had not created
 * anything yet still gets an answer, and a listener that cleans up by id should
 * know there is nothing to clean up by.
 */
final readonly class SandboxCleared
{
    public function __construct(
        public ?string $sandbox,
        public int $rows,
    ) {}
}
