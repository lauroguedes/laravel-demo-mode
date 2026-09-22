<?php

declare(strict_types=1);

namespace LauroGuedes\DemoMode\Contracts;

use LauroGuedes\DemoMode\Credentials\Credential;

/**
 * Where a demo's published passwords live between the reset that made them and
 * the login page that shows them.
 *
 * Whatever implements this is holding a working password in recoverable form. It
 * has one job beyond reading and writing: not being reachable from the web. The
 * file store's disk must not be public, and 'demo:doctor' fails rather than warns
 * when it is.
 */
interface CredentialStore
{
    /**
     * @param  list<Credential>  $credentials
     */
    public function put(array $credentials): void;

    /**
     * @return list<Credential>
     */
    public function get(): array;

    public function forget(): void;

    /**
     * One line for 'demo:status', naming where without revealing what.
     */
    public function describe(): string;
}
