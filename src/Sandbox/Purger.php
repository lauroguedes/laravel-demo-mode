<?php

declare(strict_types=1);

namespace LauroGuedes\DemoMode\Sandbox;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use Illuminate\Support\Facades\Log;
use LauroGuedes\DemoMode\Configuration;

/**
 * Everything one visitor made, removed.
 *
 * The piece that was missing, and it turned out to be load-bearing twice. A
 * scoped demo's reset button should clear the visitor's own corner rather than
 * rebuild the whole installation — the scheduler already does that, and a
 * visitor-triggered rebuild takes everybody else's session with it. And pruning
 * an expired sandbox used to delete the sandbox row while leaving the rows that
 * pointed at it: unreachable by every visitor, counted by every index, alive
 * until the next full reset.
 *
 * Both wanted the same sentence — "delete everything belonging to this id" — and
 * neither could have it.
 *
 * The command that prunes used to say it had no way to know which tables an
 * application had marked. That stopped being true when demo.sandbox.models
 * arrived: the list is there, and demo:doctor already refuses a class on it that
 * does not carry the trait. This reads the same list.
 */
final readonly class Purger
{
    public function __construct(private Configuration $config) {}

    /**
     * Delete the rows belonging to one sandbox, and say how many.
     *
     * The sandbox row itself is left alone. A visitor clearing their data is
     * still the same visitor, still on the same session — what they asked to
     * throw away is what they made, not who they are.
     */
    public function purge(string $sandbox): int
    {
        /*
         * One sandbox at a time, and one query per marked model. Pruning calls
         * this per expired row rather than in one bulk pass, which is a few
         * hundred indexed deletes on a busy demo's quarter-hourly prune — cheap,
         * and bought with the guarantee that anything reaching Prunable::prune()
         * takes its rows with it, however it got there.
         */
        $deleted = 0;

        foreach ($this->models() as $model) {
            $query = $model->newQuery()
                ->withoutGlobalScope(SandboxScope::class)
                ->where($model->qualifyColumn(Sandbox::COLUMN), $sandbox);

            /*
             * Soft deletes would leave the row in the table still carrying the
             * sandbox id — invisible, which is the state this exists to end, so
             * it would have fixed nothing. Trashed rows are collected too, for
             * the same reason: they are as unreachable as the rest.
             */
            if (in_array(SoftDeletes::class, class_uses_recursive($model::class), true)) {
                /*
                 * The scope dropped by name rather than through withTrashed(),
                 * which SoftDeletingScope adds to the builder as a macro and no
                 * static analysis can see. Same effect, and it says what it does:
                 * rows already in the bin are as unreachable as the rest and go
                 * with them.
                 */
                $deleted += (int) $query->withoutGlobalScope(SoftDeletingScope::class)->forceDelete();

                continue;
            }

            $deleted += (int) $query->delete();
        }

        return $deleted;
    }

    /**
     * The marked models, as instances, skipping anything the list got wrong.
     *
     * Silent here rather than throwing: this runs inside a scheduled prune and
     * inside a visitor's request, and neither is the place to discover that a
     * class name has a typo in it. demo:doctor is, and it reports exactly that.
     *
     * @return list<Model>
     */
    private function models(): array
    {
        $models = [];

        foreach ($this->config->array('sandbox.models') as $class) {
            if (is_string($class) && class_exists($class) && is_subclass_of($class, Model::class) && Sandbox::marks($class)) {
                $models[] = new $class;

                continue;
            }

            /*
             * Logged rather than thrown, and rather than passed over in silence.
             * Throwing would fail a visitor's request and a scheduled prune over
             * a typo; saying nothing would leave that model's rows behind on
             * every clear and every prune with no signal anywhere. demo:doctor
             * reports the same thing properly, but only if somebody runs it.
             */
            Log::channel($this->config->nullableString('log.channel'))->warning(
                'demo-mode: demo.sandbox.models lists a class that cannot be purged, so its rows are being left behind.',
                ['class' => is_string($class) ? $class : get_debug_type($class)],
            );
        }

        return $models;
    }
}
