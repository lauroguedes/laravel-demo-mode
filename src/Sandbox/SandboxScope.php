<?php

declare(strict_types=1);

namespace LauroGuedes\DemoMode\Sandbox;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

/**
 * What a visitor can see: the seeded baseline, plus their own.
 *
 * Rows the seeder made carry no sandbox id, so they are everybody's — that is
 * what makes a scoped demo look like a demo rather than an empty application.
 * Rows a visitor makes carry theirs.
 *
 * The identifier comes from the resolved Sandbox the Manager holds, never from
 * the request. That distinction is the whole point: a global scope built from
 * user input is an access-control decision made from user input.
 *
 * @implements Scope<Model>
 */
final readonly class SandboxScope implements Scope
{
    public function __construct(private Manager $sandboxes) {}

    /**
     * @param  Builder<covariant Model>  $builder
     */
    public function apply(Builder $builder, Model $model): void
    {
        /*
         * Two different nulls, and treating them alike was a leak.
         *
         * No visitor at all — a console command, a queued job, a route without a
         * session — means no scope, which is what a seeder needs.
         *
         * A visitor who simply has not created anything yet also has no sandbox,
         * and they must still see only the baseline. Returning early for them
         * showed every other visitor's rows to anybody who had not written
         * anything, which is most people.
         */
        if (! $this->sandboxes->applies()) {
            return;
        }

        $column = $model->qualifyColumn(Sandbox::COLUMN);
        $sandbox = $this->sandboxes->current();

        if (! $sandbox instanceof Sandbox) {
            $builder->whereNull($column);

            return;
        }

        $builder->where(static function (Builder $query) use ($column, $sandbox): void {
            $query->whereNull($column)->orWhere($column, $sandbox->id);
        });
    }
}
