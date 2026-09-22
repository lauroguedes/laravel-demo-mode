<?php

declare(strict_types=1);

namespace LauroGuedes\DemoMode\Reset;

use Closure;
use Illuminate\Contracts\Console\Kernel as Artisan;
use Illuminate\Contracts\Foundation\Application;

/**
 * What a strategy is given to work with.
 *
 * Strategies are written by applications as often as by this package, so they get
 * an object rather than a bag of globals: the connection to act on, the options
 * from their own config block, somewhere to report progress, and Artisan.
 *
 * The 'output' closure rather than an OutputInterface keeps a strategy usable from
 * Demo::reset() in a queued job, where there is no console to write to.
 */
final readonly class ResetContext
{
    /**
     * @param  array<string, mixed>  $options
     * @param  Closure(string): void  $output
     */
    public function __construct(
        public Application $app,
        public Artisan $artisan,
        public ?string $connection,
        public array $options,
        public Closure $output,
        public bool $dryRun = false,
    ) {}

    public function option(string $key, mixed $default = null): mixed
    {
        return $this->options[$key] ?? $default;
    }

    public function report(string $message): void
    {
        ($this->output)($message);
    }

    /**
     * Artisan arguments with the connection folded in when one is configured.
     *
     * The flag is a parameter because '--database' is Laravel's convention and
     * not everyone's: spatie/laravel-db-snapshots calls it '--connection', and
     * hard-coding the other one meant any demo that set demo.reset.connection
     * got "The --database option does not exist" at reset time — from a command
     * this package builds, on a path validate() could not see.
     *
     * @param  array<string, mixed>  $arguments
     * @return array<string, mixed>
     */
    public function arguments(array $arguments = [], string $flag = '--database'): array
    {
        if ($this->connection !== null) {
            $arguments[$flag] = $this->connection;
        }

        return $arguments;
    }
}
