<?php

declare(strict_types=1);

use Illuminate\Support\Facades\File;

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
});

afterEach(function (): void {
    is_string($this->restore)
        ? File::put($this->example, $this->restore)
        : File::delete($this->example);

    foreach (array_diff($this->published, $this->existed) as $path) {
        File::delete($path);
    }
});

it('appends the keys to an env example that has none', function (): void {
    File::put($this->example, "APP_NAME=Laravel\n");

    $this->artisan('demo:install')->assertSuccessful();

    expect(File::get($this->example))->toContain('DEMO_MODE=false', 'DEMO_RESET_SCHEDULE');
});

/**
 * A live key and a commented-out one both mean the project already has them;
 * appending underneath either would leave a second spelling of one setting.
 */
it('leaves an env example alone when the keys are already in it', function (string $existing): void {
    File::put($this->example, "APP_NAME=Laravel\n{$existing}\n");

    $this->artisan('demo:install')->assertSuccessful();

    expect(substr_count(File::get($this->example), 'DEMO_MODE='))->toBe(1);
})->with(['DEMO_MODE=false', '# DEMO_MODE=false']);

it('still appends the keys when another package owns a similarly named one', function (): void {
    File::put($this->example, "APP_NAME=Laravel\nSSO_DEMO_MODE=false\n");

    $this->artisan('demo:install')->assertSuccessful();

    expect(File::get($this->example))
        ->toContain('SSO_DEMO_MODE=false')
        ->toContain('DEMO_MODE=false')
        ->toContain('DEMO_CREDENTIALS_STORE=file');
});
