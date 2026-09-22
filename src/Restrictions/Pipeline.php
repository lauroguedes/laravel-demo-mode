<?php

declare(strict_types=1);

namespace LauroGuedes\DemoMode\Restrictions;

use Illuminate\Contracts\Container\Container;
use LauroGuedes\DemoMode\Configuration;
use LauroGuedes\DemoMode\Contracts\Restriction;
use LauroGuedes\DemoMode\Exceptions\InvalidConfiguration;

/**
 * Applies every configured restriction, once, during boot.
 *
 * Applications add their own through Restrictions\Pipeline::use(), which is the
 * extension point the contract exists for: the package knows to stop mail, and
 * has no idea whether this particular application also needs to stop charging
 * cards, calling a partner API, or writing to an audit log somebody pays per row
 * for.
 *
 * A restriction that throws is not caught. Unlike a cleaner — which runs after the
 * data is already rebuilt, where failing loudly would trade a mess for an outage —
 * a restriction that did not apply means the demo is serving without a protection
 * it was configured to have, and booting anyway would hide that.
 */
final class Pipeline
{
    /** @var list<class-string<Restriction>> */
    private static array $additional = [];

    public function __construct(
        private readonly Configuration $config,
        private readonly Container $container,
    ) {}

    /**
     * @param  class-string<Restriction>  $restriction
     */
    public static function use(string $restriction): void
    {
        if (! in_array($restriction, self::$additional, true)) {
            self::$additional[] = $restriction;
        }
    }

    public static function flush(): void
    {
        self::$additional = [];
    }

    public function apply(): void
    {
        foreach ($this->config->classMap('restrictions') as $class => $options) {
            $this->resolve($class)->apply($options);
        }

        foreach (self::$additional as $class) {
            $this->resolve($class)->apply([]);
        }
    }

    /**
     * @return list<string>
     */
    public function descriptions(): array
    {
        $descriptions = [];

        foreach (array_keys($this->config->classMap('restrictions')) as $class) {
            $descriptions[] = $this->resolve($class)->describe();
        }

        foreach (self::$additional as $class) {
            $descriptions[] = $this->resolve($class)->describe();
        }

        return $descriptions;
    }

    /**
     * @param  class-string  $class
     */
    private function resolve(string $class): Restriction
    {
        $restriction = $this->container->make($class);

        if (! $restriction instanceof Restriction) {
            throw InvalidConfiguration::expected('demo.restrictions', 'a map of Restriction implementations', $restriction);
        }

        return $restriction;
    }
}
