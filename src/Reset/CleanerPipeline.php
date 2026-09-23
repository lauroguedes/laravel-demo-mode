<?php

declare(strict_types=1);

namespace LauroGuedes\DemoMode\Reset;

use Closure;
use Illuminate\Contracts\Container\Container;
use LauroGuedes\DemoMode\Configuration;
use LauroGuedes\DemoMode\Contracts\Cleaner;
use LauroGuedes\DemoMode\Exceptions\InvalidConfiguration;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * The cleaners, in the order the config lists them.
 *
 * A cleaner that throws is logged and skipped rather than aborting the run. By
 * the time these execute the database has already been rebuilt, so refusing to
 * bring the application back up because a queue connection was unreachable would
 * trade a small mess — some stale jobs — for an outage on a server whose entire
 * purpose is being reachable.
 *
 * The failure is never silent: it is logged, and it appears in the report as the
 * step that did not happen.
 *
 * Unlike Doctor::check() and Restrictions\Pipeline::use(), there is no static
 * way to register a cleaner. That is deliberate rather than an omission: a
 * cleaner without its options does nothing useful — which disks, which queues,
 * which keys to keep — and options live in the config map. A use() that could
 * only pass an empty array would be half a feature offered as a whole one.
 */
final class CleanerPipeline
{
    /** @var list<array{0: Cleaner, 1: array<string, mixed>}>|null */
    private ?array $resolved = null;

    public function __construct(
        private readonly Configuration $config,
        private readonly Container $container,
        private readonly LoggerInterface $logger,
    ) {}

    /**
     * @param  Closure(string): void  $output
     * @return list<string>
     */
    public function run(Closure $output): array
    {
        $done = [];

        foreach ($this->cleaners() as [$cleaner, $options]) {
            $description = $cleaner->describe();

            try {
                $output($description);
                $cleaner->clean($options);
                $done[] = $description;
            } catch (Throwable $e) {
                $this->logger->warning('A demo cleaner failed and was skipped.', [
                    'cleaner' => $cleaner::class,
                    'exception' => $e->getMessage(),
                ]);

                $done[] = $description.' — skipped, see the log';
            }
        }

        return $done;
    }

    /**
     * @return list<string>
     */
    public function descriptions(): array
    {
        return array_map(
            static fn (array $entry): string => $entry[0]->describe(),
            $this->cleaners(),
        );
    }

    /**
     * @return list<array{0: Cleaner, 1: array<string, mixed>}>
     */
    private function cleaners(): array
    {
        if ($this->resolved !== null) {
            return $this->resolved;
        }

        $cleaners = [];

        foreach ($this->config->classMap('cleaners') as $class => $options) {
            $cleaner = $this->container->make($class);

            if (! $cleaner instanceof Cleaner) {
                throw InvalidConfiguration::expected('demo.cleaners', 'a map of Cleaner implementations', $cleaner);
            }

            $cleaners[] = [$cleaner, $options];
        }

        return $this->resolved = $cleaners;
    }
}
