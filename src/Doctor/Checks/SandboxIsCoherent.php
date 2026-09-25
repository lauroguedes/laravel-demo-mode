<?php

declare(strict_types=1);

namespace LauroGuedes\DemoMode\Doctor\Checks;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Builder as SchemaBuilder;
use LauroGuedes\DemoMode\Configuration;
use LauroGuedes\DemoMode\Contracts\RunsOnDemosOnly;
use LauroGuedes\DemoMode\Doctor\Finding;
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
            return [
                ...$this->isolationWasMeant(),
                ...$this->resetHasSomethingToClear(),
            ];
        }

        return [
            ...$this->tableExists(),
            ...$this->modelsAreDeclared(),
            ...$this->modelsAreMarked(),
        ];
    }

    /**
     * Everything done except the one line that switches it on.
     *
     * Every check below is skipped when the driver is not scoped, so the mistake
     * of publishing the migration, adding the column, marking the models and never
     * setting DEMO_SANDBOX=scoped produced a clean bill of health on a demo where
     * every visitor shared everything. demo:install's own checklist promised this
     * was checked, and for that one step it was not.
     *
     * Listing models is what makes it detectable: the config says isolate these,
     * and the driver says isolate nothing.
     *
     * A warning rather than an error, because carrying the trait and the list the
     * whole time and switching isolation on per deployment is the intended shape —
     * that is what the env var is for, and a shared staging copy of a scoped demo
     * should not fail a pipeline.
     *
     * @return list<Finding>
     */
    private function isolationWasMeant(): array
    {
        if ($this->config->array('sandbox.models') === []) {
            return [];
        }

        return [Finding::warning(
            'sandbox',
            sprintf(
                'demo.sandbox.models lists models to isolate but demo.sandbox.driver is "%s", so nothing is '
                    .'isolated and every visitor shares everything. None of the other scoped checks ran.',
                $this->config->string('sandbox.driver', 'shared'),
            ),
            'Set DEMO_SANDBOX=scoped in this environment, or empty demo.sandbox.models.',
        )];
    }

    /**
     * The one incoherence that lives on a demo which is *not* scoped.
     *
     * "on_demand.scope => sandbox" on a shared demo points the button at a
     * sandbox that can never exist, so pressing it deletes nothing and answers
     * that everything the visitor created has been removed. A control that
     * reports success for doing nothing is worse than one that is missing.
     *
     * An error rather than a warning, and deliberately not fixed at runtime by
     * falling back: the other meaning of that button rebuilds the whole
     * installation, and quietly upgrading "clear my rows" into that would be the
     * worst thing this package could do with a typo.
     *
     * @return list<Finding>
     */
    private function resetHasSomethingToClear(): array
    {
        if (! $this->config->boolean('on_demand.enabled')
            || $this->config->string('on_demand.scope', 'auto') !== 'sandbox') {
            return [];
        }

        return [Finding::error(
            'sandbox',
            'demo.on_demand.scope is "sandbox" but the sandbox driver is not "scoped", so the reset button has '
                .'nothing to clear. It deletes nothing and tells the visitor it worked.',
            'Set demo.sandbox.driver to "scoped", or demo.on_demand.scope to "everything" or "auto".',
        )];
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
        if (! Sandbox::marks($class)) {
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
