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
 * --rotate publishes new passwords. It does not make them work.
 *
 * It is half of what a reset does, and the half that only changes what the login
 * page displays. Nothing here hashes a password into an account, because this
 * package has no idea which model or column that would be -- the seeder does it,
 * reading Demo::passwordFor(), and the seeder runs during a reset.
 *
 * So rotating on its own leaves the account signing in with the password it was
 * seeded with, while the login page advertises a different one. It does not
 * retire a leaked password either: the old hash is still in the database and
 * still opens the account. It only stops it being shown.
 *
 * Which leaves two honest uses. Before a reset, so the seeder hashes what is
 * about to be published -- which is what the reset does for you anyway. Or in an
 * application that listens for CredentialsRotated and applies the new password
 * to the account itself, which is why that event exists.
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

            $this->components->info('New passwords published.');

            /*
             * Said every time, because the gap between publishing a password and
             * that password working is invisible: the login page fills in, the
             * sign-in fails, and nothing anywhere explains why.
             */
            $this->components->warn(
                'These do not open their accounts until something hashes them in — a reset running your '
                    .'seeder, or your own listener on CredentialsRotated. Until then the accounts keep their '
                    .'current passwords, including any you rotated to retire.',
            );
        }

        $published = $credentials->all();

        if ($published === []) {
            $this->components->warn('Nothing is published yet. Run demo:reset — it generates the passwords and seeds them in, which --rotate on its own does not.');

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
