<?php

declare(strict_types=1);

namespace LauroGuedes\DemoMode\Reset;

use Carbon\CarbonImmutable;
use Cron\CronExpression;
use LauroGuedes\DemoMode\Exceptions\InvalidConfiguration;

/**
 * One reading of the reset schedule, shared by the scheduler and the banner.
 *
 * The bug this exists to prevent is a demo that tells visitors their data resets
 * every 24 hours while the scheduler runs hourly. Both numbers came from the same
 * config key, but each side interpreted it separately, and only one of them was
 * wrong where anybody could see it. Here the cron expression is parsed once and
 * both the next run date and the human description come out of the same object,
 * so the countdown on the page cannot disagree with what the server will do.
 */
final readonly class Schedule
{
    private const array SHORTCUTS = [
        'hourly' => '0 * * * *',
        'daily' => '0 0 * * *',
        'weekly' => '0 0 * * 0',
        'monthly' => '0 0 1 * *',
    ];

    private function __construct(
        public string $expression,
        public string $source,
    ) {}

    /**
     * Read whatever the config says, whether that is cron or a shortcut.
     *
     * Accepts 'hourly', 'daily', 'weekly', 'monthly', 'every-N-hours', and any
     * valid five-field cron expression. Anything else is a misconfiguration and
     * says so, rather than silently falling back to a default that would make
     * the banner lie again.
     */
    public static function parse(string $expression): self
    {
        $normalised = mb_strtolower(trim($expression));

        if (isset(self::SHORTCUTS[$normalised])) {
            return new self(self::SHORTCUTS[$normalised], $normalised);
        }

        if (preg_match('/^every-(\d+)-hours?$/', $normalised, $matches) === 1) {
            $hours = (int) $matches[1];

            if ($hours >= 1 && $hours <= 23) {
                return new self(sprintf('0 */%d * * *', $hours), $normalised);
            }
        }

        if (CronExpression::isValidExpression($expression)) {
            return new self($expression, $expression);
        }

        throw InvalidConfiguration::unreadableSchedule($expression);
    }

    /**
     * When the scheduler will next run the reset.
     */
    public function nextRunAt(?CarbonImmutable $after = null): CarbonImmutable
    {
        $from = $after ?? CarbonImmutable::now();

        return CarbonImmutable::instance(
            (new CronExpression($this->expression))->getNextRunDate($from->toDateTime()),
        );
    }
}
