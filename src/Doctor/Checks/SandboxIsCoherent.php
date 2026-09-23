<?php

declare(strict_types=1);

namespace LauroGuedes\DemoMode\Doctor\Checks;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Builder as SchemaBuilder;
use LauroGuedes\DemoMode\Configuration;
use LauroGuedes\DemoMode\Contracts\RunsOnDemosOnly;
use LauroGuedes\DemoMode\Doctor\Finding;
use LauroGuedes\DemoMode\Sandbox\BelongsToSandbox;
use LauroGuedes\DemoMode\Sandbox\Sandbox;
use Throwable;

/**
 * Scoped isolation fails silently, which is the only reason it needs a check.
 *
 * Every other way of getting this wrong announces itself. A model that is not
 * marked simply is not scoped: a visitor sees everybody's rows on that one table
 * and creates rows everybody else sees, and nothing anywhere errors. The demo
 * looks isolated because most of it is.
 *
 * So the config lists the models that are supposed to be marked, and this checks
 * that each of them actually is, and that the column and table the feature needs
 * are there.
 */
final readonly class SandboxIsCoherent implements RunsOnDemosOnly
{
    public function __construct(
        private Configuration $config,
        private SchemaBuilder $schema,
    ) {}

    /**
     * @return list<Finding>
     */
    public function run(): array
    {
        if (! $this->config->scoped()) {
            return [];
        }

        return [
            ...$this->tableExists(),
            ...$this->modelsAreDeclared(),
            ...$this->modelsAreMarked(),
        ];
    }

    /**
     * @return list<Finding>
     */
    private function tableExists(): array
    {
        try {
            if ($this->schema->hasTable((new Sandbox)->getTable())) {
                return [];
            }
        } catch (Throwable) {
            return [];
        }

        return [Finding::error(
            'sandbox',
            'The sandbox driver is "scoped" but the demo_sandboxes table does not exist, so no visitor can be given '
                .'a corner of their own and nothing is isolated.',
            'php artisan vendor:publish --tag=demo-migrations && php artisan migrate',
        )];
    }

    /**
     * @return list<Finding>
     */
    private function modelsAreDeclared(): array
    {
        if ($this->config->array('sandbox.models') !== []) {
            return [];
        }

        return [Finding::error(
            'sandbox',
            'The sandbox driver is "scoped" but demo.sandbox.models is empty, so nothing is actually isolated. '
                .'A scoped demo with no marked models behaves exactly like a shared one.',
            'List the models that carry BelongsToSandbox, or set the driver back to "shared".',
        )];
    }

    /**
     * @return list<Finding>
     */
    private function modelsAreMarked(): array
    {
        $findings = [];

        foreach ($this->config->array('sandbox.models') as $class) {
            if (! is_string($class) || ! class_exists($class)) {
                $findings[] = Finding::error('sandbox', sprintf(
                    'demo.sandbox.models lists [%s], which is not a class.',
                    is_string($class) ? $class : get_debug_type($class),
                ));

                continue;
            }

            $findings = [...$findings, ...$this->check($class)];
        }

        return $findings;
    }

    /**
     * @param  class-string  $class
     * @return list<Finding>
     */
    private function check(string $class): array
    {
        if (! in_array(BelongsToSandbox::class, class_uses_recursive($class), true)) {
            return [Finding::error('sandbox', sprintf(
                '[%s] is listed as sandboxed but does not use BelongsToSandbox, so visitors see each other\'s rows '
                    .'in it while the rest of the demo looks isolated.',
                $class,
            ), 'Add the BelongsToSandbox trait to '.$class.'.')];
        }

        $model = new $class;

        if (! $model instanceof Model) {
            return [Finding::error('sandbox', sprintf('[%s] is listed as sandboxed but is not an Eloquent model.', $class))];
        }

        try {
            if ($this->schema->hasColumn($model->getTable(), Sandbox::COLUMN)) {
                return [];
            }
        } catch (Throwable) {
            return [];
        }

        return [Finding::error('sandbox', sprintf(
            '[%s] is sandboxed but its table [%s] has no %s column, so every query against it fails.',
            $class,
            $model->getTable(),
            Sandbox::COLUMN,
        ), sprintf('Add $table->string(\'%s\')->nullable()->index(); to that table.', Sandbox::COLUMN))];
    }
}
