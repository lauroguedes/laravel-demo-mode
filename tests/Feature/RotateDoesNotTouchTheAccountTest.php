<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Hash;
use LauroGuedes\DemoMode\Facades\Demo;
use Workbench\App\Models\DemoUser;

/*
 * What rotating actually does, as opposed to what three places in this package
 * said it does.
 *
 * Rotating generates a password and writes it to the store the login page reads.
 * It does not hash anything into any account, because this package has no idea
 * which model or column that would be -- the seeder does it, and the seeder runs
 * during a reset.
 *
 * So on its own, rotating changes which password is *displayed* and nothing
 * about which password *works*. Both halves of that are worth a test, because
 * both were claimed the other way round.
 */
beforeEach(function (): void {
    demo(['demo.credentials.accounts' => [
        ['email' => 'admin@demo.test', 'label' => 'Administrator', 'rotate' => true, 'primary' => true, 'password' => null],
    ]]);

    freshSchema();
});

it('publishes a new password', function (): void {
    Demo::rotate();
    $first = Demo::credentials()['password'];

    Demo::rotate();

    expect(Demo::credentials()['password'])->not->toBe($first)
        ->and($first)->not->toBe('');
});

/**
 * The account keeps the password it was seeded with. A visitor reading the newly
 * published one off the login page cannot sign in with it until a reset runs the
 * seeder, which is the half that makes a published password real.
 */
it('leaves the account signing in with the old password', function (): void {
    Demo::rotate();

    $published = Demo::credentials()['password'];

    DemoUser::create(['email' => 'admin@demo.test', 'password' => Hash::make($published)]);

    Demo::rotate();

    $user = DemoUser::whereEmail('admin@demo.test')->firstOrFail();

    expect(Hash::check($published, $user->password))->toBeTrue()
        ->and(Hash::check(Demo::credentials()['password'], $user->password))->toBeFalse();
});
