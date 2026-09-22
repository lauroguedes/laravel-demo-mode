<?php

declare(strict_types=1);

namespace LauroGuedes\DemoMode\Support;

/**
 * Narrowing for the untyped option arrays a cleaner or restriction is handed.
 *
 * Distinct from Configuration, which reads a config *key* and throws when the
 * type is wrong. These read an option *value* already in hand and drop what does
 * not fit, because an option array is written inline next to the class that
 * consumes it and a stray entry there should not stop a demo booting.
 */
final class Options
{
    /**
     * Everything in the value that is a string, and nothing else.
     *
     * @return list<string>
     */
    public static function strings(mixed $value): array
    {
        return is_array($value)
            ? array_values(array_filter($value, is_string(...)))
            : [];
    }

    public static function string(mixed $value, string $default): string
    {
        return is_string($value) && $value !== '' ? $value : $default;
    }
}
