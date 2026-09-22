<?php

declare(strict_types=1);

namespace LauroGuedes\DemoMode\Console;

use Illuminate\Console\Command;
use LauroGuedes\DemoMode\Exceptions\ResetInProgress;
use LauroGuedes\DemoMode\Exceptions\ResetRefused;
use LauroGuedes\DemoMode\Reset\Runner;
use Throwable;

/**
 * Puts a public demo back the way it started.
 *
 * A demonstration server is signed into by strangers, and everything they can
 * reach they can also change: rename things, revoke tokens, disable accounts.
 * Rebuilding on a schedule is what keeps it a demonstration rather than whatever
 * the last visitor left behind.
 *
 * What --force means here, precisely, is: do not ask me to confirm. That is all.
 * It does not lower the demo-mode flag check, the environment check, the
 * production check, the host check or the lock — and the guard chain is what
 * refuses, not this class, so there is no branch in this file that could be
 * simplified into letting it.
 */
final class ResetCommand extends Command
{
    protected $signature = 'demo:reset
        {--force : Skip the confirmation prompt, for a scheduled run}
        {--strategy= : Override the configured reset strategy}
        {--seeder= : Override the configured seeder class}
        {--no-maintenance : Stay online while the data is rebuilt}
        {--dry-run : Print what would happen and stop}';

    protected $description = 'Rebuild the demonstration data';

    public function handle(Runner $runner): int
    {
        $options = array_filter([
            'strategy' => $this->stringOption('strategy'),
            'seeder' => $this->stringOption('seeder'),
            'maintenance' => $this->option('no-maintenance') === true ? false : null,
            'dry-run' => $this->option('dry-run') === true ? true : null,
        ], static fn (mixed $value): bool => $value !== null);

        if ($this->option('dry-run') !== true && ! $this->confirmed()) {
            $this->components->info('Nothing was changed.');

            return self::FAILURE;
        }

        try {
            $report = $runner->run($options, function (string $message): void {
                $this->components->task($message);
            });
        } catch (ResetRefused $e) {
            $this->components->error('This reset was refused.');

            foreach ($e->reasons as $reason) {
                $this->components->bulletList([$reason]);
            }

            return self::FAILURE;
        } catch (ResetInProgress $e) {
            $this->components->warn($e->getMessage());

            return self::FAILURE;
        } catch (Throwable $e) {
            $this->components->error('The reset failed: '.$e->getMessage());
            $this->components->info('The application has been brought back online.');

            return self::FAILURE;
        }

        $this->newLine();

        if ($report->dryRun) {
            $this->components->info('Nothing was changed. This is what a reset would do:');
            $this->components->bulletList($report->steps);

            return self::SUCCESS;
        }

        $this->components->info(sprintf(
            'The demonstration is back to its starting state (%ss).',
            number_format($report->duration(), 1),
        ));

        return self::SUCCESS;
    }

    /**
     * The interactive confirmation, which is the only guard --force touches.
     *
     * A non-interactive run with no --force refuses rather than proceeding.
     * There is nobody at the terminal to answer, and taking silence for consent
     * on a command that drops every table is the wrong way round — the same
     * reason 'migrate --force' exists. A cron entry says --force because
     * somebody wrote it there, which is the consent.
     */
    private function confirmed(): bool
    {
        if ($this->option('force') === true) {
            return true;
        }

        if (! $this->input->isInteractive()) {
            $this->components->error('Nothing is attached to answer the confirmation. Pass --force if you meant this.');

            return false;
        }

        return $this->confirm('This deletes every row in the demo database. Continue?');
    }

    private function stringOption(string $name): ?string
    {
        $value = $this->option($name);

        return is_string($value) && $value !== '' ? $value : null;
    }
}
