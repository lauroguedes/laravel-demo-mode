<?php

declare(strict_types=1);

namespace LauroGuedes\DemoMode\Console;

use Illuminate\Console\Command;
use LauroGuedes\DemoMode\Doctor\Doctor;
use LauroGuedes\DemoMode\Doctor\Finding;

/**
 * Audits a demo's configuration before it can do any damage.
 *
 * Meant to run in a deploy pipeline, ahead of the first scheduled reset. The
 * exit code is the contract: non-zero on any error, so a pipeline stops on a
 * demo pointed at the wrong database or publishing passwords to a public disk.
 *
 * Runs on installations that are not demos too, and says so as its first line,
 * because "why is my demo not working" is most often answered by "this one is not
 * a demo".
 */
final class DoctorCommand extends Command
{
    protected $signature = 'demo:doctor {--json : Print the findings as JSON}';

    protected $description = 'Audit this installation\'s demo configuration';

    public function handle(Doctor $doctor): int
    {
        $findings = $doctor->run();
        $errors = array_values(array_filter($findings, static fn (Finding $f): bool => $f->isError()));

        if ($this->option('json') === true) {
            $this->output->writeln((string) json_encode([
                'ok' => $errors === [],
                'findings' => array_map(static fn (Finding $f): array => $f->toArray(), $findings),
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return $errors === [] ? self::SUCCESS : self::FAILURE;
        }

        if ($findings === []) {
            $this->components->info('This demo is configured correctly.');

            return self::SUCCESS;
        }

        $this->newLine();

        foreach ($findings as $finding) {
            $finding->isError()
                ? $this->components->error($finding->message)
                : $this->components->warn($finding->message);

            if ($finding->fix !== null) {
                $this->components->bulletList([$finding->fix]);
            }
        }

        $this->components->info(sprintf(
            '%d error(s), %d warning(s).',
            count($errors),
            count($findings) - count($errors),
        ));

        return $errors === [] ? self::SUCCESS : self::FAILURE;
    }
}
