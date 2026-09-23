<?php

declare(strict_types=1);

namespace LauroGuedes\DemoMode\Events;

/**
 * A sandbox was pruned, and whatever was in it went with it.
 *
 * The seam for an application that keeps something outside its database per
 * visitor — an uploaded file, a search index entry — and needs to remove it at
 * the same time.
 */
final readonly class SandboxExpired
{
    public function __construct(public string $id) {}
}
