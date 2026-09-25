<?php

declare(strict_types=1);

namespace LauroGuedes\DemoMode\Console;

use Illuminate\Console\Command;
use Illuminate\Filesystem\Filesystem;

use function Laravel\Prompts\select;

/**
 * Sets a project up to have a demo, without making it one.
 *
 * Every action here is additive. It publishes the config, writes a seeder stub,
 * and appends the DEMO_ keys to .env.example. It does not touch .env, and it does
 * not set DEMO_MODE=true — turning an installation into a public playground is an
 * act a person performs deliberately, on the deployment they meant, and an
 * installer that did it for you would be the first thing this package gets wrong.
 *
 * It asks exactly one question, and only because that answer changes what the
 * rest of the installation is: whether visitors share one set of data or get
 * their own. 'scoped' also needs a table, a column on every marked model and a
 * trait on each of them, so answering it publishes the migration and ends on a
 * different checklist. Everything else this package can infer or default, and a
 * question whose answer is already in the config is a question that wastes the
 * one moment somebody is paying attention.
 *
 * Non-interactive runs get 'shared' — the default, and the only driver that needs
 * nothing — because a deploy script that blocks on a prompt is a broken deploy
 * script. --sandbox= answers it without being asked.
 *
 * What it prints either way is the checklist, ending on the two settings that
 * matter most and that nothing else can infer: which environments may reset, and
 * which hosts.
 */
final class InstallCommand extends Command
{
    private const array DRIVERS = ['shared', 'scoped'];

    protected $signature = 'demo:install
        {--force : Overwrite files that already exist}
        {--sandbox= : shared or scoped, rather than being asked}';

    protected $description = 'Publish the demo-mode config and seeder stub';

    public function handle(Filesystem $files): int
    {
        /* Asked before anything is written, so a mistyped --sandbox costs nothing. */
        $driver = $this->sandboxDriver();

        if ($driver === null) {
            return self::FAILURE;
        }

        $this->newLine();
        $this->components->info('Installing demo mode.');

        $this->call('vendor:publish', array_filter([
            '--tag' => 'demo-config',
            '--force' => $this->option('force') === true ? true : null,
        ], static fn (mixed $v): bool => $v !== null));

        $this->publishSeeder($files);

        if ($driver === 'scoped') {
            $this->publishSandboxMigration($files);
        }

        $this->appendEnvironmentKeys($files, $driver);

        $this->newLine();
        $this->components->info('Installed. Nothing is a demo yet — here is what makes one:');
        $this->components->bulletList($this->checklist($driver));
        $this->newLine();

        return self::SUCCESS;
    }

    /**
     * Null means a value was given and was not one of the two. An empty --sandbox=
     * counts as not given, and falls through to the question.
     *
     * Rejected rather than corrected: 'sandboxed' and 'scope' both plausibly mean
     * scoped, and an installer that guesses which one you meant is an installer
     * that can quietly set up the wrong demo.
     */
    private function sandboxDriver(): ?string
    {
        $given = $this->option('sandbox');

        if (is_string($given) && $given !== '') {
            if (! in_array($given, self::DRIVERS, true)) {
                $this->components->error(sprintf(
                    '--sandbox takes "shared" or "scoped", not "%s".',
                    $given,
                ));

                return null;
            }

            return $given;
        }

        if (! $this->input->isInteractive()) {
            return 'shared';
        }

        return (string) select(
            label: 'Should every visitor see the same data?',
            options: [
                'shared' => 'Shared — one dataset, everybody pokes at the same thing',
                'scoped' => 'Scoped — each visitor gets the baseline plus their own rows',
            ],
            default: 'shared',
            hint: 'Scoped needs a column on every model you mark. Start shared if you are not sure; it is one env var to change.',
        );
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
     * The table a scoped demo records its visitors in.
     *
     * --force is deliberately not passed through. This migration is published
     * under a fresh timestamp each time, so forcing would not overwrite the old
     * one — it would add a second migration creating the same table, and the next
     * `migrate` would fail on a project whose only mistake was running the
     * installer twice. Hence a suffix match over the directory rather than an
     * exists() on one path: the name of the one already there is not predictable.
     */
    private function publishSandboxMigration(Filesystem $files): void
    {
        if ($this->sandboxMigrationExists($files)) {
            $this->components->twoColumnDetail('Sandboxes table', '<fg=yellow>migration already exists, left alone</>');

            return;
        }

        $this->call('vendor:publish', ['--tag' => 'demo-migrations']);
    }

    private function sandboxMigrationExists(Filesystem $files): bool
    {
        $directory = $this->laravel->databasePath('migrations');

        if (! $files->isDirectory($directory)) {
            return false;
        }

        foreach ($files->files($directory) as $file) {
            if (str_ends_with($file->getFilename(), '_create_demo_sandboxes_table.php')) {
                return true;
            }
        }

        return false;
    }

    /**
     * Appended to .env.example only. The real .env belongs to whoever deployed
     * this, and a package that edits it is a package that can turn a demo on.
     */
    private function appendEnvironmentKeys(Filesystem $files, string $driver): void
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

        /*
         * A heredoc rather than a nowdoc, for the one interpolation below. Nothing
         * else in this block contains a $ or a backslash, and anything added to it
         * must not: it would be interpolated into the file somebody deploys from.
         */
        $files->append($path, <<<ENV

            DEMO_MODE=false
            DEMO_RESET_SCHEDULE="0 */6 * * *"
            DEMO_RESET_STRATEGY=migrate-fresh-seed
            DEMO_CREDENTIALS_STORE=file
            DEMO_EMAIL=admin@demo.test
            {$this->sandboxKey($driver)}

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

    /**
     * Live when it was chosen, commented when it was not, so the shared default
     * stays visible as a thing that can be changed rather than a thing nobody
     * mentioned.
     */
    private function sandboxKey(string $driver): string
    {
        return $driver === 'scoped'
            ? 'DEMO_SANDBOX=scoped                 # shared | scoped'
            : '# DEMO_SANDBOX=shared               # shared | scoped';
    }

    /**
     * @return list<string>
     */
    private function checklist(string $driver): array
    {
        $doctor = 'Run demo:doctor. It exits non-zero on anything that would destroy data or publish a secret.';

        $everyDemo = [
            'Write the demonstration data into database/seeders/DemoSeeder.php.',
            'Set DEMO_MODE=true in the environment that serves the demo, and nowhere else.',
            'Set demo.environments to the environments that may rebuild the data.',
            'Set demo.allowed_hosts to the demo\'s hostname — it is the guard that survives a copied .env.',
            'Add the published account to demo.guards.protected so a visitor cannot lock the next one out.',
        ];

        if ($driver === 'scoped') {
            /*
             * DEMO_SANDBOX comes first because every other step here is checked
             * against it: demo:doctor returns early on a driver that is not
             * scoped, so doing the other three and forgetting this one used to
             * produce a clean report on a demo with no isolation at all. It warns
             * about that now, which is why the last line can say what it says.
             */
            return [
                ...$everyDemo,
                'Set DEMO_SANDBOX=scoped in that same environment. .env.example now carries it; .env is the file that is read.',
                'Run migrate — the demo_sandboxes migration is now in database/migrations.',
                'Add $table->string(\'demo_sandbox_id\')->nullable()->index(); to every table a visitor writes to.',
                'Add the BelongsToSandbox trait to those models, and list them in demo.sandbox.models.',
                'Optional: append the demo.sandbox middleware to the web group, so a sandbox expires an hour after its visitor stops rather than an hour after it was created.',
                $doctor.' It errors on each of the last three above that is missing, and warns if the first one never happened — a model you forget to mark is the one way scoped isolation fails silently.',
            ];
        }

        return [...$everyDemo, $doctor];
    }
}
