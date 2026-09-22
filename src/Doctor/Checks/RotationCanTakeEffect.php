<?php

declare(strict_types=1);

namespace LauroGuedes\DemoMode\Doctor\Checks;

use LauroGuedes\DemoMode\Configuration;
use LauroGuedes\DemoMode\Contracts\RunsOnDemosOnly;
use LauroGuedes\DemoMode\Doctor\Finding;
use LauroGuedes\DemoMode\Reset\StrategyFactory;
use Throwable;

/**
 * A rotating password only rotates if something re-hashes it.
 *
 * The reset generates a new password and publishes it, and a seeder is what
 * writes the matching hash into the database. Restoring a snapshot or a SQL dump
 * runs no seeder, so the account keeps whatever hash the baseline froze — and
 * two things go wrong at once, both silently.
 *
 * The login page shows a password that opens nothing, so nobody can sign in
 * until somebody notices. And the password baked into the baseline keeps working
 * forever, which is exactly the permanent fact of the internet that rotation
 * exists to prevent.
 *
 * Without this check the package would report "Rotated 1 published password" and
 * demo:doctor would confirm that rotation is configured, while neither was true
 * in any sense a visitor could use.
 */
final readonly class RotationCanTakeEffect implements RunsOnDemosOnly
{
    public function __construct(
        private Configuration $config,
        private StrategyFactory $strategies,
    ) {}

    /**
     * @return list<Finding>
     */
    public function run(): array
    {
        if (! $this->config->boolean('credentials.enabled', true) || ! $this->rotates()) {
            return [];
        }

        try {
            $strategy = $this->strategies->make();
        } catch (Throwable) {
            /* StrategyIsUsable reports an unbuildable strategy. */
            return [];
        }

        if ($strategy->seedsCredentials()) {
            return [];
        }

        return [Finding::error(
            'credentials-rotation',
            sprintf(
                'Passwords rotate on every reset, but "%s" runs no seeder — the restored baseline keeps its own '
                    .'password hash. The login page would show a password that does not work, and the one frozen in '
                    .'the baseline would keep working forever.',
                $strategy->describe(),
            ),
            'Set "rotate" => false on the published accounts and document the baseline\'s password, or re-hash the '
                .'staged password after the restore with a ResetCompleted listener.',
        )];
    }

    private function rotates(): bool
    {
        return array_any($this->config->array('credentials.accounts'), fn (mixed $account): bool => is_array($account) && ($account['rotate'] ?? true));
    }
}
