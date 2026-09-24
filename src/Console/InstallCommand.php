<?php

declare(strict_types=1);

namespace LauroGuedes\DemoMode\Console;

use Illuminate\Console\Command;
use Illuminate\Filesystem\Filesystem;

/**
 * Sets a project up to have a demo, without making it one.
 *
 * Every action here is additive. It publishes the config, writes a seeder stub,
 * and appends the DEMO_ keys to .env.example. It does not touch .env, and it does
 * not set DEMO_MODE=true — turning an installation into a public playground is an
 * act a person performs deliberately, on the deployment they meant, and an
 * installer that did it for you would be the first thing this package gets wrong.
 *
 * What it does instead is print the checklist, ending on the two settings that
 * matter most and that nothing else can infer: which environments may reset, and
 * which hosts.
 */
final class InstallCommand extends Command
{
    protected $signature = 'demo:install {--force : Overwrite files that already exist}';

    protected $description = 'Publish the demo-mode config and seeder stub';

    public function handle(Filesystem $files): int
    {
        $this->newLine();
        $this->components->info('Installing demo mode.');

        $this->call('vendor:publish', array_filter([
            '--tag' => 'demo-config',
            '--force' => $this->option('force') === true ? true : null,
        ], static fn (mixed $v): bool => $v !== null));

        $this->publishSeeder($files);
        $this->appendEnvironmentKeys($files);

        $this->newLine();
        $this->components->info('Installed. Nothing is a demo yet — here is what makes one:');
        $this->components->bulletList([
            'Write the demonstration data into database/seeders/DemoSeeder.php.',
            'Set DEMO_MODE=true in the environment that serves the demo, and nowhere else.',
            'Set demo.environments to the environments that may rebuild the data.',
            'Set demo.allowed_hosts to the demo\'s hostname — it is the guard that survives a copied .env.',
            'Add the published account to demo.guards.protected so a visitor cannot lock the next one out.',
            'Run demo:doctor. It exits non-zero on anything that would destroy data or publish a secret.',
        ]);
        $this->newLine();

        return self::SUCCESS;
    }

    private function publishSeeder(Filesystem $files): void
    {
        $destination = $this->laravel->databasePath('seeders/DemoSeeder.php');

        if ($files->exists($destination) && $this->option('force') !== true) {
            $this->components->twoColumnDetail('Seeder', '<fg=yellow>already exists, left alone</>');

            return;
        }

        $files->ensureDirectoryExists(dirname($destination));
        $files->put($destination, (string) $files->get(__DIR__.'/../../stubs/DemoSeeder.php.stub'));

        $this->components->twoColumnDetail('Seeder', '<fg=green>database/seeders/DemoSeeder.php</>');
    }

    /**
     * Appended to .env.example only. The real .env belongs to whoever deployed
     * this, and a package that edits it is a package that can turn a demo on.
     */
    private function appendEnvironmentKeys(Filesystem $files): void
    {
        $path = $this->laravel->basePath('.env.example');

        if (! $files->exists($path)) {
            return;
        }

        $contents = (string) $files->get($path);

        /*
         * Anchored to the start of a line, because a substring search answers
         * yes to SSO_DEMO_MODE and to a comment that merely mentions the key.
         * Both happen: the first project to install this had SSO_DEMO_MODE in
         * its .env.example and was told its keys were already there.
         *
         * --force does not reach here, unlike the config and the seeder. Those
         * are files this package owns and can replace; this one is shared, and
         * the only thing forcing could add is a second DEMO_MODE= line.
         */
        if (preg_match('/^\s*#?\s*DEMO_MODE=/m', $contents) === 1) {
            $this->components->twoColumnDetail('.env.example', '<fg=yellow>already has the keys</>');

            return;
        }

        $files->append($path, <<<'ENV'

            DEMO_MODE=false
            DEMO_RESET_SCHEDULE="0 */6 * * *"
            DEMO_RESET_STRATEGY=migrate-fresh-seed
            DEMO_CREDENTIALS_STORE=file
            DEMO_EMAIL=admin@demo.test

            # The notice, so it can be dressed without a deployment. Every option
            # is spelled out beside its key in config/demo.php.
            # DEMO_BANNER=true
            # DEMO_BANNER_STYLE=pill              # pill | bare
            # DEMO_BANNER_VARIANT=warning         # warning | danger | info | success | neutral
            # DEMO_BANNER_POSITION=bottom         # top | bottom
            # DEMO_BANNER_LABEL=Demo
            # DEMO_BANNER_DISMISSIBLE=true
            # DEMO_BANNER_RESET_BUTTON=true
            # DEMO_BANNER_CTA_LABEL="Deploy your own"
            # DEMO_BANNER_CTA_URL=https://github.com/you/your-project

            ENV);

        $this->components->twoColumnDetail('.env.example', '<fg=green>added the DEMO_ keys</>');
    }
}
