<?php

declare(strict_types=1);

namespace LauroGuedes\DemoMode;

use Illuminate\Container\Container;
use Illuminate\Contracts\Config\Repository;
use LauroGuedes\DemoMode\Exceptions\InvalidConfiguration;

/**
 * The only place in the package that reads "config('demo.*')".
 *
 * Config is an untyped bag, and a package that decides whether to drop tables
 * cannot afford to guess at what came back. Everything is read through here and
 * narrowed once, so a wrong type in a published config file surfaces as a named
 * exception at the point of reading rather than as a TypeError somewhere inside
 * a reset that has already started.
 *
 * It is deliberately not the public surface: the facade is. Demo::enabled() is
 * what an application calls, and this is what answers it.
 *
 * The repository is resolved per call rather than held, for two reasons.
 * ForceConfig
 * replaces the container's config binding with a PinnedConfigRepository while
 * the application is booting. A Configuration that captured the repository at
 * construction would go on reading the one that was swapped out — leaving the
 * class documented as the single point of truth holding the detached copy, which
 * is the exact failure it exists to prevent. The cost is a container lookup of
 * an already-built singleton.
 */
class Configuration
{
    /**
     * Whether this installation declared itself a public demonstration.
     */
    public function enabled(): bool
    {
        return (bool) $this->repository()->get('demo.enabled', false);
    }

    /**
     * Whether visitors are isolated from each other.
     *
     * Asked by the provider, the Manager and the trait, and each of them would
     * otherwise spell out the same comparison against the same string.
     */
    public function scoped(): bool
    {
        return $this->enabled() && $this->string('sandbox.driver', 'shared') === 'scoped';
    }

    public function boolean(string $key, bool $default = false): bool
    {
        $value = $this->repository()->get($this->qualify($key));

        return $value === null ? $default : (bool) $value;
    }

    public function integer(string $key, int $default = 0): int
    {
        $value = $this->repository()->get($this->qualify($key));

        if ($value === null) {
            return $default;
        }

        if (! is_numeric($value)) {
            throw InvalidConfiguration::expected($this->qualify($key), 'an integer', $value);
        }

        return (int) $value;
    }

    public function string(string $key, string $default = ''): string
    {
        $value = $this->repository()->get($this->qualify($key));

        if ($value === null) {
            return $default;
        }

        if (! is_string($value) && ! is_numeric($value)) {
            throw InvalidConfiguration::expected($this->qualify($key), 'a string', $value);
        }

        return (string) $value;
    }

    public function nullableString(string $key): ?string
    {
        $value = $this->repository()->get($this->qualify($key));

        if ($value === null || $value === '') {
            return null;
        }

        if (! is_string($value) && ! is_numeric($value)) {
            throw InvalidConfiguration::expected($this->qualify($key), 'a string or null', $value);
        }

        return (string) $value;
    }

    /**
     * @return array<array-key, mixed>
     */
    public function array(string $key): array
    {
        $value = $this->repository()->get($this->qualify($key), []);

        if ($value === null) {
            return [];
        }

        if (! is_array($value)) {
            throw InvalidConfiguration::expected($this->qualify($key), 'an array', $value);
        }

        return $value;
    }

    /**
     * An array whose entries are all strings, with anything else rejected.
     *
     * Used for the lists a guard reads — environments, hosts, route names. A
     * silently dropped non-string there would widen a guard rather than fail.
     *
     * @return list<string>
     */
    public function strings(string $key): array
    {
        $values = [];

        foreach ($this->array($key) as $value) {
            if (! is_string($value)) {
                throw InvalidConfiguration::expected($this->qualify($key), 'a list of strings', $value);
            }

            $values[] = $value;
        }

        return $values;
    }

    /**
     * A list of strings, or null when the key is absent — which for an
     * allowlist means "do not check" rather than "allow nothing".
     *
     * @return list<string>|null
     */
    public function nullableStrings(string $key): ?array
    {
        return $this->repository()->get($this->qualify($key)) === null
            ? null
            : $this->strings($key);
    }

    /**
     * A map of class name to options, as the cleaner, restriction and strategy
     * lists are written.
     *
     * @return array<class-string, array<string, mixed>>
     */
    public function classMap(string $key): array
    {
        $map = [];

        foreach ($this->array($key) as $class => $options) {
            if (! is_string($class) || ! class_exists($class)) {
                throw InvalidConfiguration::expected($this->qualify($key), 'a map keyed by class name', $class);
            }

            if (! is_array($options)) {
                throw InvalidConfiguration::expected($this->qualify($key).'.'.$class, 'an array of options', $options);
            }

            /** @var array<string, mixed> $options */
            $map[$class] = $options;
        }

        return $map;
    }

    private function qualify(string $key): string
    {
        return 'demo.'.$key;
    }

    /**
     * Whatever repository is bound right now.
     *
     * Through Container::getInstance() rather than an injected container: this
     * is a singleton, and a singleton that captures the container it was built
     * with holds a stale one for every request after the first under Octane.
     * The static accessor is the one Octane keeps pointed at the live container.
     */
    private function repository(): Repository
    {
        return Container::getInstance()->make(Repository::class);
    }
}
