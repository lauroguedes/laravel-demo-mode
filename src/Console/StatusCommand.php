<?php

declare(strict_types=1);

namespace LauroGuedes\DemoMode\Console;

use Illuminate\Console\Command;
use LauroGuedes\DemoMode\Configuration;
use LauroGuedes\DemoMode\Credentials\Manager as Credentials;
use LauroGuedes\DemoMode\DemoMode;
use LauroGuedes\DemoMode\Reset\StrategyFactory;
use Throwable;

/**
 * What this installation currently is.
 *
 * Answers the question you ask when a demo is behaving unexpectedly, in the
 * order you ask it: is this a demo at all, when does it reset, what rebuilds it,
 * and where do the credentials come from.
 *
 * Never prints a password. 'demo:credentials' does that, deliberately as a
 * separate command you have to mean to run rather than something that happens
 * while you are looking at a status table.
 */
final class StatusCommand extends Command
{
    protected $signature = 'demo:status {--json : Print the status as JSON}';

    protected $description = 'Show what demo mode is currently doing';

    public function handle(
        DemoMode $demo,
        Configuration $config,
        StrategyFactory $strategies,
        Credentials $credentials,
    ): int {
        $schedule = $demo->schedule();
        $strategy = $this->describeStrategy($strategies, $config->string('reset.strategy', 'migrate-fresh-seed'));
        $restrictions = array_map(class_basename(...), array_keys($config->classMap('restrictions')));
        $cleaners = array_map(class_basename(...), array_keys($config->classMap('cleaners')));
        $protected = array_map(strval(...), array_keys($config->array('guards.protected')));

        if ($this->option('json') === true) {
            $this->output->writeln((string) json_encode([
                'enabled' => $demo->enabled(),
                'environment' => $this->laravel->environment(),
                'schedule' => $schedule?->source,
                'next_reset_at' => $demo->nextResetAt()?->toIso8601String(),
                'last_reset_at' => $demo->lastResetAt()?->toIso8601String(),
                'strategy' => $strategy,
                'credentials' => $credentials->describe(),
                'accounts' => count($credentials->all()),
                'restrictions' => $restrictions,
                'cleaners' => $cleaners,
                'protected_models' => $protected,
                'sandbox' => $config->string('sandbox.driver', 'shared'),
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        }

        if (! $demo->enabled()) {
            $this->components->warn('This installation is not a demo. Set DEMO_MODE=true to make it one.');

            return self::SUCCESS;
        }

        $this->newLine();
        $this->components->twoColumnDetail('<fg=green;options=bold>Demo mode</>', 'on');
        $this->components->twoColumnDetail('Environment', $this->laravel->environment());
        $this->components->twoColumnDetail('Resets', $schedule->source ?? 'never — the schedule could not be read');
        $this->components->twoColumnDetail('Next reset', $demo->nextResetAt()?->toIso8601String() ?? 'unknown');
        $this->components->twoColumnDetail('Last reset', $demo->lastResetAt()?->toIso8601String() ?? 'not yet');
        $this->components->twoColumnDetail('Strategy', $strategy);
        $this->components->twoColumnDetail('Credentials', $credentials->describe());
        $this->components->twoColumnDetail('Accounts published', (string) count($credentials->all()));
        $this->components->twoColumnDetail('Restrictions', $this->list($restrictions));
        $this->components->twoColumnDetail('Cleaners', $this->list($cleaners));
        $this->components->twoColumnDetail('Protected models', $this->list($protected));
        $this->components->twoColumnDetail('Sandbox', $config->string('sandbox.driver', 'shared'));
        $this->newLine();

        return self::SUCCESS;
    }

    private function describeStrategy(StrategyFactory $strategies, string $name): string
    {
        try {
            return $strategies->make()->describe();
        } catch (Throwable) {
            return $name.' — misconfigured, run demo:doctor';
        }
    }

    /**
     * @param  list<string>  $values
     */
    private function list(array $values): string
    {
        return $values === [] ? 'none' : implode(', ', $values);
    }
}
