<?php

declare(strict_types=1);

namespace LauroGuedes\DemoMode\Credentials\Stores;

use LauroGuedes\DemoMode\Contracts\CredentialStore;

/**
 * Publish nothing.
 *
 * For a demo whose password is fixed and written in its README, which is a
 * legitimate choice — it trades rotation for one less secret on the disk. The
 * trade is worth naming: a password documented in public is permanent, so the
 * account it opens should be able to do less than a rotating one.
 */
final class NullStore implements CredentialStore
{
    public function put(array $credentials): void
    {
        //
    }

    public function get(): array
    {
        return [];
    }

    public function forget(): void
    {
        //
    }

    public function describe(): string
    {
        return 'nothing is published';
    }
}
