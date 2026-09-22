<?php

declare(strict_types=1);

namespace LauroGuedes\DemoMode\Tests\Fixtures;

use Illuminate\Database\Console\Migrations\FreshCommand;
use LauroGuedes\DemoMode\Contracts\ResetStrategy;
use LauroGuedes\DemoMode\Reset\ResetContext;
use ReflectionProperty;
use Throwable;

/**
 * A strategy that records being called instead of dropping anything.
 *
 * The guard matrix exists to prove that certain configurations never reach a
 * destructive call, and a test that proved it by actually dropping tables would
 * be proving it the expensive way — and would tell you nothing on the run where
 * the guard failed to hold.
 *
 * It also records whether the destructive-command prohibition was down at the
 * moment it ran, which is the only way to observe that the window the Runner
 * opens is both open when a strategy needs it and closed everywhere else.
 */
final class SpyStrategy implements ResetStrategy
{
    public static int $runs = 0;

    public static ?bool $prohibitedWhenRun = null;

    public static ?Throwable $throws = null;

    /** @var list<string> */
    public static array $problems = [];

    /**
     * @param  array<string, mixed>  $options
     */
    public function __construct(public readonly array $options = []) {}

    public static function reset(): void
    {
        self::$runs = 0;
        self::$prohibitedWhenRun = null;
        self::$throws = null;
        self::$problems = [];
    }

    public function run(ResetContext $context): void
    {
        self::$runs++;
        self::$prohibitedWhenRun = self::prohibited();

        if (self::$throws instanceof Throwable) {
            throw self::$throws;
        }
    }

    public function describe(): string
    {
        return 'Record the call and change nothing';
    }

    public function seedsCredentials(): bool
    {
        return true;
    }

    public function validate(): array
    {
        return self::$problems;
    }

    /**
     * Laravel exposes no getter for the prohibition, so it is read the only way
     * available: ask the command whether it would refuse.
     */
    public static function prohibited(): bool
    {
        $property = new ReflectionProperty(FreshCommand::class, 'prohibitedFromRunning');

        return (bool) $property->getValue();
    }
}
