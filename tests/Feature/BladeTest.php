<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Config;

/**
 * @notdemo has to work precisely when the flag is off, which is when it would be
 * easiest to have never registered it — and an unregistered directive is a
 * compile error, not a false condition.
 */
it('renders @demo only on a demo', function (): void {
    demo();

    expect(Blade::render('@demo yes @enddemo'))->toContain('yes');
});

it('renders @notdemo only when the installation is not a demo', function (): void {
    Config::set('demo.enabled', false);

    expect(Blade::render('@notdemo elsewhere @endnotdemo'))->toContain('elsewhere')
        ->and(Blade::render('@demo here @enddemo'))->not->toContain('here');
});

it('registers both halves of the pair', function (): void {
    demo();

    expect(Blade::render('@notdemo elsewhere @endnotdemo'))->not->toContain('elsewhere');
});
