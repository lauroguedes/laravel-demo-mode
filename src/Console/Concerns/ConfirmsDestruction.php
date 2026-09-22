<?php

declare(strict_types=1);

namespace LauroGuedes\DemoMode\Console\Concerns;

/**
 * The confirmation rule for commands that cannot be undone.
 *
 * Deliberately not Laravel's ConfirmableTrait, which only prompts in production
 * and treats a non-interactive run as consent. Both of those are the wrong way
 * round here: this package's destructive commands are for everywhere *except*
 * production, and silence from a terminal nobody is sitting at is not agreement.
 *
 * So: --force skips the prompt and does nothing else. No --force and nobody to
 * ask means refuse, the same way 'migrate --force' exists. A cron entry carries
 * --force because a person wrote it there, which is the consent.
 */
trait ConfirmsDestruction
{
    private function confirmed(string $question): bool
    {
        if ($this->option('force') === true) {
            return true;
        }

        if (! $this->input->isInteractive()) {
            $this->components->error('Nothing is attached to answer the confirmation. Pass --force if you meant this.');

            return false;
        }

        return $this->confirm($question);
    }

    private function stringOption(string $name): ?string
    {
        $value = $this->option($name);

        return is_string($value) && $value !== '' ? $value : null;
    }

    private function stringArgument(string $name): ?string
    {
        $value = $this->argument($name);

        return is_string($value) && $value !== '' ? $value : null;
    }
}
