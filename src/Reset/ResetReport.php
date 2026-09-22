<?php

declare(strict_types=1);

namespace LauroGuedes\DemoMode\Reset;

use Carbon\CarbonImmutable;

/**
 * What a reset did, for the event listeners and the command's output.
 *
 * Deliberately carries no credential values — only how many rotated. The report
 * travels into logs, events and whatever an application's listener does with it,
 * and a published password that reaches a log file has outlived the reset that
 * was supposed to retire it.
 */
final readonly class ResetReport
{
    /**
     * @param  list<string>  $steps
     * @param  list<string>  $cleaners
     */
    public function __construct(
        public string $strategy,
        public array $steps,
        public array $cleaners,
        public int $credentialsRotated,
        public CarbonImmutable $startedAt,
        public CarbonImmutable $finishedAt,
        public bool $dryRun = false,
    ) {}

    public function duration(): float
    {
        return round($this->finishedAt->getPreciseTimestamp(3) / 1000
            - $this->startedAt->getPreciseTimestamp(3) / 1000, 3);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'strategy' => $this->strategy,
            'steps' => $this->steps,
            'cleaners' => $this->cleaners,
            'credentials_rotated' => $this->credentialsRotated,
            'started_at' => $this->startedAt->toIso8601String(),
            'finished_at' => $this->finishedAt->toIso8601String(),
            'duration' => $this->duration(),
            'dry_run' => $this->dryRun,
        ];
    }
}
