<?php

declare(strict_types=1);

namespace LauroGuedes\DemoMode\Contracts;

/**
 * A doctor check that has nothing to say about an installation which is not a
 * demo.
 *
 * Most of them are like this: there is no point auditing the mail transport, the
 * seeder or the database name of an application that never claimed to be a
 * playground, and doing it anyway would bury the one finding that matters — that
 * this is not a demo — under a page of irrelevant ones.
 *
 * Two checks deliberately do not carry this. GuardsWouldPass is the one that
 * reports the flag being off, and CredentialsAreNotPublic runs everywhere
 * because a credentials file served over HTTP stays served after the flag goes
 * off: the flag governs what this package reads, not what a web server hands out.
 */
interface RunsOnDemosOnly extends DoctorCheck {}
