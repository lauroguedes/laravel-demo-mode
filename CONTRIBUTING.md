# Contributing

## Running the suite

```bash
composer test
```

That runs Pint, Rector, PHPStan at level 8, 100% type coverage and the tests.
All five gate CI.

**You need PHP 8.4 to run it**, though the package itself supports 8.3: Pest 5
requires 8.4, so the suite cannot run on the floor `composer.json` allows. CI
covers that floor with a separate job that lints every shipped file on a real
8.3 — enough to catch syntax that only newer PHP parses, which is how four
`new Foo()->bar()` expressions got in. Nothing catches an 8.4-only *function*
automatically, so if you reach for one, check `php.net` for its version first.
Rector's set is pinned to `UP_TO_PHP_83` precisely because it introduced both.

## The one rule that is not style

**Nothing destructive gets written before the barrier that guards it.**

`demo:reset` drops tables. The guard matrix in
`tests/Feature/GuardMatrixTest.php` asserts, for every combination of flag,
environment, host and `--force`, whether a reset may proceed — and proves it
through a spy strategy, so a failure is a wrong verdict rather than a dropped
table. If you add a configuration axis that can affect whether a reset is
allowed, add it to that dataset in the same commit.

`--force` skips the confirmation prompt. If a change makes it do anything else,
that change is wrong.

## Adding a doctor check

`demo:doctor` is the accumulated list of ways a demo goes wrong. If you find a
new one, it belongs there:

1. Implement `Contracts\DoctorCheck` in `src/Doctor/Checks/`.
2. Add it to `Doctor::CHECKS`.
3. Add a test that fires it and a test that does not.

An **error** is something that will destroy data or publish a secret, and it sets
the exit code. A **warning** is something that makes the demo worse. Getting that
distinction wrong is how a command becomes one people skip.

## Style

Match what is there. Docblocks explain *why* a thing is the way it is, not what
the signature already says — particularly for anything guarding a destructive
path, where the reason is the documentation.
