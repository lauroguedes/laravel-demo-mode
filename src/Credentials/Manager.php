<?php

declare(strict_types=1);

namespace LauroGuedes\DemoMode\Credentials;

use Illuminate\Contracts\Container\Container;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;
use LauroGuedes\DemoMode\Configuration;
use LauroGuedes\DemoMode\Contracts\CredentialStore;
use LauroGuedes\DemoMode\Events\CredentialsRotated;
use LauroGuedes\DemoMode\Exceptions\InvalidConfiguration;

/**
 * Publishes the passwords a stranger signs in with, and retires them.
 *
 * This is the one place in a demo where a password is deliberately recoverable,
 * and it is bounded on both sides. Nothing is written unless the deployment
 * declared itself a demo. Nothing is read back unless it still says so — which is
 * what makes a leftover credentials file inert the moment DEMO_MODE goes off,
 * and what makes "the demo box got promoted to production" a survivable mistake
 * rather than a published administrator password.
 *
 * Rotation is the actual control. A password that never changes is a permanent
 * fact of the internet: whatever a visitor wrote down keeps working forever. A
 * new one on every reset means it stops working the next time the scheduler runs.
 */
final class Manager
{
    /**
     * Generated but not yet written to the store.
     *
     * @var list<Credential>|null
     */
    private ?array $staged = null;

    /**
     * The store's answer, held for this request.
     *
     * One request that renders the banner and then prefills a login form asks
     * for these three or four times, and on the file store each ask is a disk
     * read and a json_decode of the same bytes.
     *
     * @var list<Credential>|null
     */
    private ?array $loaded = null;

    /**
     * The store is resolved on use rather than injected.
     *
     * Building it means StoreFactory, which means the filesystem manager and the
     * cache manager. A login page on an installation that is not a demo
     * constructs this manager to render a component that renders nothing, and
     * that should cost nothing.
     */
    public function __construct(
        private readonly Configuration $config,
        private readonly Container $container,
        private readonly Dispatcher $events,
    ) {}

    /**
     * New passwords, published immediately.
     *
     * What 'demo:credentials --rotate' calls, and what an application calls when
     * it wants to retire a password without rebuilding the data.
     *
     * @return list<Credential>
     */
    public function rotate(): array
    {
        $credentials = $this->stage();

        $this->publish();

        return $credentials;
    }

    /**
     * Generate the new passwords and hold them, without writing anything yet.
     *
     * Split from publishing because of an ordering problem with two ends. The
     * seeder needs the new password while it runs — it is what gets hashed into
     * the account a visitor signs in with — so generation has to happen before
     * the strategy. But the cache store writes into a cache that the cleaners
     * are about to empty, so writing has to happen after them.
     *
     * Doing both at either end breaks something: rotate early and the cache
     * store's value is flushed away; rotate late and the seeder hashes a
     * password that is no longer the published one, so the login page shows
     * credentials that do not open the account they name. Neither failure says
     * anything in a log. Staging in memory is what lets both ends be right.
     *
     * @return list<Credential>
     */
    public function stage(): array
    {
        if (! $this->publishes()) {
            return [];
        }

        $credentials = [];

        foreach ($this->accounts() as $account) {
            $credentials[] = new Credential(
                email: $account['email'],
                password: $account['rotate'] ? $this->generate() : ($account['password'] ?? ''),
                label: $account['label'],
                primary: $account['primary'],
            );
        }

        return $this->staged = array_values(array_filter(
            $credentials,
            static fn (Credential $c): bool => $c->password !== '',
        ));
    }

    /**
     * Write the staged passwords to the store and announce them.
     */
    public function publish(): void
    {
        if ($this->staged === null || ! $this->publishes()) {
            return;
        }

        $this->store()->put($this->staged);

        $this->loaded = null;

        $this->events->dispatch(new CredentialsRotated(
            array_map(static fn (Credential $c): string => $c->email, $this->staged),
        ));
    }

    /**
     * Everything published, or nothing at all when this is not a demo.
     *
     * The flag check is the important line in this class. A file left behind by a
     * demo that has since become something else reads back as an empty list.
     *
     * @return list<Credential>
     */
    public function all(): array
    {
        if (! $this->publishes()) {
            return [];
        }

        /*
         * Staged values win while a reset is in flight, which is what lets the
         * seeder read the password that is about to be published rather than
         * the one the previous reset left in the store.
         */
        return $this->staged ?? $this->loaded ??= $this->store()->get();
    }

    /**
     * The account a login form should prefill.
     *
     * The one marked primary, or the first published — a demo with one account,
     * which is most of them, needs no marking.
     */
    public function primary(): ?Credential
    {
        $credentials = $this->all();

        return Arr::first($credentials, static fn (Credential $c): bool => $c->primary)
            ?? $credentials[0]
            ?? null;
    }

    /**
     * The password for one address, for a seeder that wants to hash it.
     *
     * A seeder calls this without first asking whether this is a demo; null is
     * the answer when it is not, and the seeder falls back to its own default.
     */
    public function passwordFor(string $email): ?string
    {
        return Arr::first($this->all(), static fn (Credential $c): bool => $c->email === $email)?->password;
    }

    public function forget(): void
    {
        $this->staged = null;
        $this->loaded = null;
        $this->store()->forget();
    }

    public function describe(): string
    {
        return $this->publishes() ? $this->store()->describe() : 'disabled';
    }

    /**
     * Both switches: this has to be a demo, and publishing has to be on.
     */
    public function publishes(): bool
    {
        return $this->config->enabled() && $this->config->boolean('credentials.enabled', true);
    }

    /**
     * No symbols by default: a visitor who retypes the password rather than
     * trusting the prefilled field should not be fighting their keyboard layout
     * to do it. Length is what carries the strength here, and the password only
     * has to survive until the next reset.
     */
    private function store(): CredentialStore
    {
        return $this->container->make(CredentialStore::class);
    }

    private function generate(): string
    {
        return Str::password(
            length: max(8, $this->config->integer('credentials.password.length', 16)),
            numbers: $this->config->boolean('credentials.password.numbers', true),
            symbols: $this->config->boolean('credentials.password.symbols', false),
        );
    }

    /**
     * @return list<array{email: string, label: string|null, rotate: bool, primary: bool, password: string|null}>
     */
    private function accounts(): array
    {
        $accounts = [];

        foreach ($this->config->array('credentials.accounts') as $account) {
            if (! is_array($account)) {
                throw InvalidConfiguration::expected('demo.credentials.accounts', 'a list of arrays', $account);
            }

            $email = $account['email'] ?? null;

            if (! is_string($email) || $email === '') {
                throw InvalidConfiguration::expected('demo.credentials.accounts.*.email', 'a non-empty string', $email);
            }

            $label = $account['label'] ?? null;
            $password = $account['password'] ?? null;

            $accounts[] = [
                'email' => $email,
                'label' => is_string($label) ? $label : null,
                'rotate' => (bool) ($account['rotate'] ?? true),
                'primary' => (bool) ($account['primary'] ?? false),
                'password' => is_string($password) ? $password : null,
            ];
        }

        return $accounts;
    }
}
