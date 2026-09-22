<?php

declare(strict_types=1);

namespace LauroGuedes\DemoMode\Console;

use Illuminate\Console\Command;
use LauroGuedes\DemoMode\Credentials\Credential;
use LauroGuedes\DemoMode\Credentials\Manager as Credentials;

/**
 * Shows, or replaces, the passwords this demo publishes.
 *
 * Separate from 'demo:status' because it prints working credentials to a
 * terminal, and that should be something you typed rather than something that
 * happened while you were looking at a status table.
 *
 * --rotate is the manual version of what every reset does. Worth having on its
 * own for the case the rotation exists to handle: a password that has been seen
 * by more people than it should have been, and a demo you do not want to rebuild
 * right now to retire it.
 */
final class CredentialsCommand extends Command
{
    protected $signature = 'demo:credentials
        {--rotate : Publish new passwords now, without resetting the data}
        {--json : Print them as JSON}';

    protected $description = 'Show the credentials this demo publishes';

    public function handle(Credentials $credentials): int
    {
        if (! $credentials->publishes()) {
            $this->components->warn('This installation publishes no credentials.');

            return self::SUCCESS;
        }

        if ($this->option('rotate') === true) {
            $credentials->rotate();
            $this->components->info('New passwords published. The old ones no longer work.');
        }

        $published = $credentials->all();

        if ($published === []) {
            $this->components->warn('Nothing is published yet. Run demo:reset, or demo:credentials --rotate.');

            return self::SUCCESS;
        }

        if ($this->option('json') === true) {
            $this->output->writeln((string) json_encode(
                Credential::toArrays($published),
                JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES,
            ));

            return self::SUCCESS;
        }

        $this->newLine();

        foreach ($published as $credential) {
            $this->components->twoColumnDetail(
                $credential->email.($credential->primary ? ' <fg=gray>(primary)</>' : ''),
                $credential->password,
            );
        }

        $this->newLine();

        return self::SUCCESS;
    }
}
