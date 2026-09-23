<?php

declare(strict_types=1);

namespace LauroGuedes\DemoMode\Doctor;

use Illuminate\Contracts\Container\Container;
use LauroGuedes\DemoMode\Configuration;
use LauroGuedes\DemoMode\Contracts\DoctorCheck;
use LauroGuedes\DemoMode\Contracts\RunsOnDemosOnly;
use LauroGuedes\DemoMode\Doctor\Checks\ConnectionGuardIsSurvivable;
use LauroGuedes\DemoMode\Doctor\Checks\CredentialsAreNotPublic;
use LauroGuedes\DemoMode\Doctor\Checks\CredentialsSurviveTheReset;
use LauroGuedes\DemoMode\Doctor\Checks\DatabaseLooksDisposable;
use LauroGuedes\DemoMode\Doctor\Checks\GuardsWouldPass;
use LauroGuedes\DemoMode\Doctor\Checks\MailIsContained;
use LauroGuedes\DemoMode\Doctor\Checks\PublishedAccountIsProtected;
use LauroGuedes\DemoMode\Doctor\Checks\RotationCanTakeEffect;
use LauroGuedes\DemoMode\Doctor\Checks\ScheduleIsReadable;
use LauroGuedes\DemoMode\Doctor\Checks\StrategyIsUsable;
use LauroGuedes\DemoMode\Exceptions\InvalidConfiguration;

/**
 * Runs every check and collects what they found.
 *
 * The piece of this package that justifies the rest of it. Everything else is
 * mechanism an application could write itself; this is the accumulated list of
 * ways a demo goes wrong, in a form that runs in a deploy pipeline and exits
 * non-zero before the first scheduled reset rather than after it.
 *
 * Applications add their own with Doctor::check(), which is how a project's
 * particular way of going wrong stops being folklore.
 *
 * The shipped list is a constant rather than a config key on purpose. Everything
 * here exists because a demo went wrong that way once, and a check an application
 * can delete from a config file is a check that gets deleted the first time it is
 * inconvenient — at which point the command reports success having audited less
 * than it claims. Adding is open; removing is not.
 */
final class Doctor
{
    /** @var list<class-string<DoctorCheck>> */
    private const array CHECKS = [
        GuardsWouldPass::class,
        ScheduleIsReadable::class,
        StrategyIsUsable::class,
        CredentialsAreNotPublic::class,
        CredentialsSurviveTheReset::class,
        MailIsContained::class,
        PublishedAccountIsProtected::class,
        RotationCanTakeEffect::class,
        ConnectionGuardIsSurvivable::class,
        DatabaseLooksDisposable::class,
    ];

    /** @var list<class-string<DoctorCheck>> */
    private static array $additional = [];

    public function __construct(
        private readonly Container $container,
        private readonly Configuration $config,
    ) {}

    /**
     * @param  class-string<DoctorCheck>  $check
     */
    public static function check(string $check): void
    {
        if (! in_array($check, self::$additional, true)) {
            self::$additional[] = $check;
        }
    }

    public static function flush(): void
    {
        self::$additional = [];
    }

    /**
     * Every check this package ships.
     *
     * An arch test asserts this list covers the Checks namespace, because two of
     * them have already been written, tested in isolation and never registered —
     * a check that is not in this array is a check that reports nothing while
     * looking like it works.
     *
     * @return list<class-string<DoctorCheck>>
     */
    public static function shipped(): array
    {
        return self::CHECKS;
    }

    /**
     * @return list<Finding>
     */
    public function run(): array
    {
        $findings = [];

        foreach ([...self::CHECKS, ...self::$additional] as $class) {
            $check = $this->container->make($class);

            /*
             * Throws rather than skipping. This is the one command whose exit
             * code is the contract, and a registered check that silently did not
             * run would let it report success having audited less than it claims
             * — the failure mode the command exists to prevent, in the command
             * itself.
             */
            if (! $check instanceof DoctorCheck) {
                throw InvalidConfiguration::expected('demo:doctor checks', 'a DoctorCheck implementation', $check);
            }

            if ($check instanceof RunsOnDemosOnly && ! $this->config->enabled()) {
                continue;
            }

            $findings = [...$findings, ...$check->run()];
        }

        return $findings;
    }
}
