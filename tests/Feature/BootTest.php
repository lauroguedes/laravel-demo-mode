<?php

declare(strict_types=1);

use Illuminate\Contracts\Console\Kernel;
use LauroGuedes\DemoMode\DemoModeServiceProvider;
use LauroGuedes\DemoMode\Facades\Demo;

it('boots with the flag off and registers nothing destructive', function (): void {
    expect(Demo::enabled())->toBeFalse();

    $commands = array_keys(app(Kernel::class)->all());

    expect($commands)->toContain('demo:doctor', 'demo:status', 'demo:install')
        ->and($commands)->not->toContain('demo:reset');
});

it('registers the reset command once the installation says it is a demo', function (): void {
    demo();

    app()->register(DemoModeServiceProvider::class, force: true);

    expect(Demo::enabled())->toBeTrue()
        ->and(array_keys(app(Kernel::class)->all()))->toContain('demo:reset');
});
