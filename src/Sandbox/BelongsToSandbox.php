<?php

declare(strict_types=1);

namespace LauroGuedes\DemoMode\Sandbox;

use Illuminate\Database\Eloquent\Builder;

/**
 * Mark a model as belonging to whoever created its rows.
 *
 * Two halves, and both are needed: a global scope so a visitor reads only the
 * baseline and their own, and a creating hook so what they write is theirs.
 *
 * Inert unless demo.sandbox.driver is 'scoped', so a model can carry this the
 * whole time and cost nothing on the shared default or in production.
 *
 * The column has to exist on the table. Add it in the same migration that adds
 * the model, or a scoped demo fails on its first query:
 *
 *     $table->string('demo_sandbox_id')->nullable()->index();
 *
 * withoutSandbox() is the escape hatch for the code that has to see everything —
 * an admin screen the demo itself provides, a count in a dashboard. It is a
 * deliberate act with a name, which is the right shape for "show me other
 * people's rows".
 */
trait BelongsToSandbox
{
    public static function bootBelongsToSandbox(): void
    {
        $sandboxes = app(Manager::class);

        if (! $sandboxes->scoped()) {
            return;
        }

        static::addGlobalScope(new SandboxScope($sandboxes));

        static::creating(static function (self $model) use ($sandboxes): void {
            $sandbox = $sandboxes->current();

            /*
             * Overwritten rather than filled in when empty, and that is the
             * security half of this trait.
             *
             * Demo models are usually written with $guarded = [], so a visitor
             * posting demo_sandbox_id alongside the rest of a form could plant a
             * row in somebody else's sandbox — or set it to null and publish one
             * to everybody. Either way the identifier a visitor sent would have
             * decided where their data went, which is exactly what the read side
             * is careful not to allow.
             *
             * When no sandbox is resolved there is no visitor to attribute the
             * row to, so whatever was set stands. That is the seeder's path, and
             * it is how the shared baseline gets its null.
             */
            if ($sandbox instanceof Sandbox) {
                $model->setAttribute(Sandbox::COLUMN, $sandbox->id);
            }
        });
    }

    /**
     * Every row, including other visitors'. Say it on purpose.
     *
     * @return Builder<static>
     */
    public static function withoutSandbox(): Builder
    {
        return static::query()->withoutGlobalScope(SandboxScope::class);
    }
}
