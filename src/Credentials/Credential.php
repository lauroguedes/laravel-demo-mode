<?php

declare(strict_types=1);

namespace LauroGuedes\DemoMode\Credentials;

use SensitiveParameter;
use Stringable;

/**
 * One account a visitor can sign in as.
 *
 * The password is public by design — it is printed on a login page — but public
 * on that page is not the same as public in a stack trace. __debugInfo() and the
 * string cast both redact it so that a var_dump, a dd(), or an exception rendered
 * by Ignition does not put a working credential into a screenshot, a bug report
 * or a log aggregator, where it outlives the reset meant to retire it.
 *
 * toArray() carries the password, because that is what the login page needs.
 * Everything that serialises a Credential somewhere other than a view should use
 * redacted() instead.
 */
final readonly class Credential implements Stringable
{
    public function __construct(
        public string $email,
        #[SensitiveParameter]
        public string $password,
        public ?string $label = null,
        public bool $primary = false,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function __debugInfo(): array
    {
        return $this->redacted() + ['password' => '********'];
    }

    public function __toString(): string
    {
        return $this->email.' (password withheld)';
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): ?self
    {
        $email = $data['email'] ?? null;
        $password = $data['password'] ?? null;

        if (! is_string($email) || ! is_string($password) || $email === '' || $password === '') {
            return null;
        }

        $label = $data['label'] ?? null;

        return new self(
            email: $email,
            password: $password,
            label: is_string($label) ? $label : null,
            primary: (bool) ($data['primary'] ?? false),
        );
    }

    /**
     * Decode a stored payload, dropping anything that is not a credential.
     *
     * Shared by both stores, which read the same shape from different places.
     *
     * @param  array<array-key, mixed>  $payload
     * @return list<self>
     */
    public static function listFromArray(array $payload): array
    {
        $credentials = [];

        foreach ($payload as $entry) {
            if (is_array($entry) && ($credential = self::fromArray($entry)) instanceof self) {
                $credentials[] = $credential;
            }
        }

        return $credentials;
    }

    /**
     * @param  list<self>  $credentials
     * @return list<array{email: string, password: string, label: string|null, primary: bool}>
     */
    public static function toArrays(array $credentials): array
    {
        return array_map(static fn (self $credential): array => $credential->toArray(), $credentials);
    }

    /**
     * @return array{email: string, password: string, label: string|null, primary: bool}
     */
    public function toArray(): array
    {
        return [
            'email' => $this->email,
            'password' => $this->password,
            'label' => $this->label,
            'primary' => $this->primary,
        ];
    }

    /**
     * The same shape, safe to log.
     *
     * @return array{email: string, label: string|null, primary: bool}
     */
    public function redacted(): array
    {
        return [
            'email' => $this->email,
            'label' => $this->label,
            'primary' => $this->primary,
        ];
    }
}
