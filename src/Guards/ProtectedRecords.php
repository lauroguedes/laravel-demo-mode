<?php

declare(strict_types=1);

namespace LauroGuedes\DemoMode\Guards;

use Closure;
use Illuminate\Database\Eloquent\Model;
use LauroGuedes\DemoMode\Configuration;
use LauroGuedes\DemoMode\Exceptions\InvalidConfiguration;

/**
 * Which records a visitor may not change, read once from config.
 *
 * A matcher is either a map of attributes that all have to be equal, or a
 * Closure that answers for itself. The map covers the case every demo has — the
 * published account, by email — without asking anyone to write a closure for it.
 *
 * Both the old and the new values are checked on an update, and the reason is
 * the obvious attack on the obvious protection: guarding only the stored row
 * lets a visitor edit the published account's email to something else and then
 * do as they like with a record that no longer matches. Guarding only the
 * incoming values lets them empty it instead.
 */
final readonly class ProtectedRecords
{
    public function __construct(private Configuration $config) {}

    /**
     * @return list<class-string<Model>>
     */
    public function models(): array
    {
        $models = [];

        foreach (array_keys($this->config->array('guards.protected')) as $model) {
            if (! is_string($model) || ! class_exists($model) || ! is_subclass_of($model, Model::class)) {
                throw InvalidConfiguration::expected('demo.guards.protected', 'a map keyed by Eloquent model class', $model);
            }

            $models[] = $model;
        }

        return $models;
    }

    /**
     * Whether this record is one the demo keeps back.
     */
    public function matches(Model $model): bool
    {
        $matcher = $this->config->array('guards.protected')[$model::class] ?? null;

        if ($matcher instanceof Closure) {
            return (bool) $matcher($model);
        }

        if (! is_array($matcher) || $matcher === []) {
            return false;
        }

        return $this->matchesAttributes($model, $matcher, fresh: true)
            || $this->matchesAttributes($model, $matcher, fresh: false);
    }

    /**
     * @param  array<array-key, mixed>  $matcher
     */
    private function matchesAttributes(Model $model, array $matcher, bool $fresh): bool
    {
        foreach ($matcher as $attribute => $expected) {
            if (! is_string($attribute)) {
                continue;
            }

            $actual = $fresh
                ? $model->getAttribute($attribute)
                : $model->getOriginal($attribute);

            if (! $this->same($actual, $expected)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Compared as strings when both sides are scalar, rather than with ==.
     *
     * A config file holds '1' where a column holds 1, and an id column holds an
     * int where a matcher was written as a string. Loose comparison would cover
     * those and also a handful of coercions nobody wants a protection rule
     * decided by; this covers the first set and nothing else.
     */
    private function same(mixed $actual, mixed $expected): bool
    {
        if ($actual === null) {
            return false;
        }

        return is_scalar($actual) && is_scalar($expected)
            ? (string) $actual === (string) $expected
            : $actual === $expected;
    }
}
