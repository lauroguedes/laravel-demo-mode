<?php

declare(strict_types=1);

use Illuminate\Support\Facades\File;
use Illuminate\Testing\PendingCommand;
use Symfony\Component\Finder\SplFileInfo;

/**
 * The installer's only irreversible-looking act is appending to .env.example, and
 * it decides whether to by looking for the keys it would add. Getting that check
 * wrong is silent in both directions: appending twice leaves a duplicate key, and
 * skipping wrongly leaves a project with no keys and a line saying it has them.
 */
beforeEach(function (): void {
    $this->example = base_path('.env.example');
    $this->restore = File::exists($this->example) ? File::get($this->example) : null;

    /*
     * demo:install also publishes the config and the seeder into the testbench
     * application, and those files outlive the process. One left behind on a
     * developer's machine shadowed the package's own config for two days: the
     * banner's default style changed, every test here kept reading the stale
     * published copy, and only CI — which starts clean — saw the failure.
     */
    $this->published = [
        config_path('demo.php'),
        database_path('seeders/DemoSeeder.php'),
    ];

    $this->existed = array_filter($this->published, File::exists(...));

    /*
     * The sandboxes migration is published under a fresh timestamp, so it cannot
     * be named ahead of time — recorded by difference instead.
     */
    $this->migrationsBefore = sandboxMigrations();
});

afterEach(function (): void {
    is_string($this->restore)
        ? File::put($this->example, $this->restore)
        : File::delete($this->example);

    foreach (array_diff($this->published, $this->existed) as $path) {
        File::delete($path);
    }

    foreach (array_diff(sandboxMigrations(), $this->migrationsBefore) as $path) {
        File::delete($path);
    }
});

/**
 * @return list<string>
 */
function sandboxMigrations(): array
{
    $directory = database_path('migrations');

    if (! File::isDirectory($directory)) {
        return [];
    }

    return array_values(array_filter(
        array_map(fn (SplFileInfo $file): string => (string) $file->getRealPath(), File::files($directory)),
        fn (string $path): bool => str_ends_with($path, '_create_demo_sandboxes_table.php'),
    ));
}

/**
 * Both answers pinned on every test that is not about the questions themselves:
 * an installer that asks something is an installer whose other tests all have to
 * answer it, and an answer written into a test about .env.example is a test that
 * changes meaning when the wording of a question does.
 */
function install(array $parameters = []): PendingCommand
{
    return test()->artisan('demo:install', [
        '--sandbox' => 'shared',
        '--without-seeder' => true,
        ...$parameters,
    ]);
}

function seederPath(): string
{
    return database_path('seeders/DemoSeeder.php');
}

it('appends the keys to an env example that has none', function (): void {
    File::put($this->example, "APP_NAME=Laravel\n");

    install()->assertSuccessful();

    expect(File::get($this->example))->toContain('DEMO_MODE=false', 'DEMO_RESET_SCHEDULE');
});

/**
 * A live key and a commented-out one both mean the project already has them;
 * appending underneath either would leave a second spelling of one setting.
 */
it('leaves an env example alone when the keys are already in it', function (string $existing): void {
    File::put($this->example, "APP_NAME=Laravel\n{$existing}\n");

    install()->assertSuccessful();

    expect(substr_count(File::get($this->example), 'DEMO_MODE='))->toBe(1);
})->with(['DEMO_MODE=false', '# DEMO_MODE=false']);

it('still appends the keys when another package owns a similarly named one', function (): void {
    File::put($this->example, "APP_NAME=Laravel\nSSO_DEMO_MODE=false\n");

    install()->assertSuccessful();

    expect(File::get($this->example))
        ->toContain('SSO_DEMO_MODE=false')
        ->toContain('DEMO_MODE=false')
        ->toContain('DEMO_CREDENTIALS_STORE=file');
});

/*
 * The first question. Everything else about a demo can be inferred or defaulted;
 * this cannot, and changing it afterwards means a migration and a trait on every
 * model, which is why it is asked at the moment somebody is paying attention.
 */

it('asks which kind of isolation to set up', function (): void {
    File::put($this->example, "APP_NAME=Laravel\n");

    test()->artisan('demo:install', ['--without-seeder' => true])
        ->expectsChoice('Should every visitor see the same data?', 'shared', [
            'shared' => 'Shared — one dataset, everybody pokes at the same thing',
            'scoped' => 'Scoped — each visitor gets the baseline plus their own rows',
        ])
        ->assertSuccessful();

    expect(sandboxMigrations())->toBe([])
        ->and(File::get($this->example))->toContain('# DEMO_SANDBOX=shared');
});

/**
 * The shared driver needs nothing, so a run that installs it should leave no
 * trace of the other one — the table a scoped demo needs is the table this
 * package refuses to add to a database that never asked for it.
 */
it('publishes no sandboxes migration for a shared demo', function (): void {
    install()->assertSuccessful();

    expect(sandboxMigrations())->toBe([]);
});

it('publishes the sandboxes migration when scoped was chosen', function (): void {
    File::put($this->example, "APP_NAME=Laravel\n");

    install(['--sandbox' => 'scoped'])->assertSuccessful();

    $published = sandboxMigrations();

    expect($published)->toHaveCount(1)
        ->and(File::get($published[0]))->toContain('Sandbox::createTable()');
});

/**
 * .env.example is a template for deployments; .env is the file that is read. A
 * scoped install writes the key live into the template, and the checklist still
 * says to set it in the environment that actually serves the demo, because the
 * installer does not touch .env and never will.
 */
it('writes the sandbox key live for scoped and commented for shared', function (): void {
    File::put($this->example, "APP_NAME=Laravel\n");

    install(['--sandbox' => 'scoped'])->assertSuccessful();

    expect(File::get($this->example))
        ->toContain('DEMO_SANDBOX=scoped')
        ->not->toContain('# DEMO_SANDBOX');

    File::put($this->example, "APP_NAME=Laravel\n");

    install()->assertSuccessful();

    expect(File::get($this->example))->toContain('# DEMO_SANDBOX=shared');
});

/*
 * One expectation per test on purpose: the bullet list reaches the output as a
 * single write, and two expectations against one write leave the second one
 * unsatisfied however right the text is.
 */

it('ends on the scoped checklist', function (): void {
    install(['--sandbox' => 'scoped'])
        ->expectsOutputToContain('BelongsToSandbox')
        ->assertSuccessful();
});

/**
 * The shared checklist is the whole value of asking the question. A demo that
 * shares its data has no models to mark and no column to add, and printing those
 * steps anyway is how a five-line checklist becomes one nobody reads.
 */
it('says nothing about marking models on a shared demo', function (): void {
    install()
        ->doesntExpectOutputToContain('BelongsToSandbox')
        ->assertSuccessful();
});

/**
 * Published under a fresh timestamp each time, so a second publish is a second
 * migration creating the same table and the next `migrate` fails — on a project
 * whose only mistake was running the installer twice.
 *
 * The existing one is planted with a name of its own rather than produced by a
 * first run of the installer. The publish path is built from date() when the
 * provider boots, so two runs inside one process agree on the filename and
 * vendor:publish skips the second by itself: a test written that way passes with
 * the guard deleted, which is how this one was written first.
 *
 * --force is passed to show it does not reach here. Forcing cannot overwrite a
 * file whose name it cannot predict; all it could do is add a second one.
 */
it('leaves an existing sandboxes migration alone, whatever it is named', function (): void {
    File::put(database_path('migrations/2020_01_01_000000_create_demo_sandboxes_table.php'), '<?php // ours');

    install(['--sandbox' => 'scoped', '--force' => true])->assertSuccessful();

    $kept = sandboxMigrations();

    expect($kept)->toHaveCount(1)
        ->and(File::get($kept[0]))->toBe('<?php // ours');
});

/**
 * Rejected rather than guessed at. 'sandboxed' and 'scope' both plausibly mean
 * scoped, and an installer that picks one quietly sets up a demo nobody asked
 * for — so it fails before writing anything at all.
 */
it('refuses an isolation it does not recognise, before writing anything', function (): void {
    File::put($this->example, "APP_NAME=Laravel\n");

    install(['--sandbox' => 'sandboxed'])->assertFailed();

    expect(File::get($this->example))->not->toContain('DEMO_MODE')
        ->and(sandboxMigrations())->toBe([]);
});

/**
 * A deploy script that blocks on a prompt is a broken deploy script. The driver
 * it should assume is the one that needs nothing, and the seeder it should assume
 * is the one this command wrote before it asked anything at all — the config it
 * publishes names that class, so an install that silently stopped writing it
 * would leave the two disagreeing.
 */
it('answers both questions itself when there is nobody to ask', function (): void {
    File::put($this->example, "APP_NAME=Laravel\n");

    test()->artisan('demo:install', ['--no-interaction' => true])->assertSuccessful();

    expect(sandboxMigrations())->toBe([])
        ->and(File::get($this->example))->toContain('# DEMO_SANDBOX=shared')
        ->and(File::exists(seederPath()))->toBeTrue();
});

/*
 * The second question. A project whose existing seeder already builds something
 * demonstrable does not want a second empty one — but saying no leaves the
 * published config naming a class that is not there, and that is the whole reason
 * the two answers end on different checklists.
 */

it('asks whether to start a seeder', function (): void {
    File::delete(seederPath());

    test()->artisan('demo:install', ['--sandbox' => 'shared'])
        ->expectsConfirmation('Start a DemoSeeder for the demonstration data?', 'yes')
        ->assertSuccessful();

    expect(File::exists(seederPath()))->toBeTrue();
});

it('writes no seeder when the answer is no', function (): void {
    File::delete(seederPath());

    test()->artisan('demo:install', ['--sandbox' => 'shared'])
        ->expectsConfirmation('Start a DemoSeeder for the demonstration data?', 'no')
        ->assertSuccessful();

    expect(File::exists(seederPath()))->toBeFalse();
});

it('writes no seeder and asks nothing when told not to', function (): void {
    File::delete(seederPath());

    install()->assertSuccessful();

    expect(File::exists(seederPath()))->toBeFalse();
});

/**
 * Not asked when one is already there. The only answer that would change
 * anything is one this command declines to act on — --force is how you say
 * overwrite it — and a file that may hold real seed data is not something to be
 * nudged into replacing by a prompt.
 */
it('does not ask about a seeder that is already there', function (): void {
    File::ensureDirectoryExists(dirname(seederPath()));
    File::put(seederPath(), '<?php // mine');

    test()->artisan('demo:install', ['--sandbox' => 'shared'])->assertSuccessful();

    expect(File::get(seederPath()))->toBe('<?php // mine');
});

/**
 * The line that makes saying no safe. The published config names
 * Database\Seeders\DemoSeeder, and the way a missing seeder fails is bad enough
 * to spell out: migrate:fresh runs first, so the database is already empty by the
 * time db:seed cannot find the class.
 */
it('ends by saying which config key to repoint when no seeder was written', function (): void {
    install()
        ->expectsOutputToContain('migrate-fresh-seed')
        ->assertSuccessful();
});

it('says nothing about repointing it when a seeder was written', function (): void {
    File::delete(seederPath());

    test()->artisan('demo:install', ['--sandbox' => 'shared'])
        ->expectsConfirmation('Start a DemoSeeder for the demonstration data?', 'yes')
        ->doesntExpectOutputToContain('migrate-fresh-seed')
        ->assertSuccessful();
});

/**
 * --force with a DemoSeeder already on disk. The question is meant to be skipped
 * here, and whatever happens the closing advice has to match what is on disk: a
 * run that tells you to repoint demo.reset away from a seeder that exists and
 * works is the one line this whole question was added to get right.
 */
it('does not tell you to repoint away from a seeder that is there, under force', function (): void {
    File::ensureDirectoryExists(dirname(seederPath()));
    File::put(seederPath(), '<?php // mine');

    test()->artisan('demo:install', ['--sandbox' => 'shared', '--force' => true])
        ->doesntExpectOutputToContain('migrate-fresh-seed')
        ->assertSuccessful();
});

it('leaves a seeder that is there alone under force and --without-seeder', function (): void {
    File::ensureDirectoryExists(dirname(seederPath()));
    File::put(seederPath(), '<?php // mine');

    test()->artisan('demo:install', ['--sandbox' => 'shared', '--force' => true, '--without-seeder' => true])
        ->doesntExpectOutputToContain('migrate-fresh-seed')
        ->assertSuccessful();

    expect(File::get(seederPath()))->toBe('<?php // mine');
});
