<?php

declare(strict_types=1);

namespace LauroGuedes\DemoMode\Doctor\Checks;

use LauroGuedes\DemoMode\Configuration;
use LauroGuedes\DemoMode\Contracts\RunsOnDemosOnly;
use LauroGuedes\DemoMode\Doctor\Finding;
use LauroGuedes\DemoMode\Reset\Schedule;
use Throwable;

/**
 * The reset schedule has to parse.
 *
 * An unreadable expression is quiet in a way that matters: the banner drops its
 * countdown, the scheduler registers nothing, and the demo simply never resets
 * while continuing to look like a demo that does.
 */
final readonly class ScheduleIsReadable implements RunsOnDemosOnly
{
    public function __construct(private Configuration $config) {}

    public function run(): array
    {
        $expression = $this->config->string('reset.schedule', '0 */6 * * *');

        try {
            Schedule::parse($expression);
        } catch (Throwable $e) {
            return [Finding::error(
                'reset-schedule',
                $e->getMessage(),
                'Use a cron expression, or one of hourly, daily, weekly, every-N-hours.',
            )];
        }

        return [];
    }
}
