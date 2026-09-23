<?php

declare(strict_types=1);

namespace LauroGuedes\DemoMode\Guards;

use Illuminate\Database\Eloquent\Model;
use LauroGuedes\DemoMode\Exceptions\DemoWriteProhibited;
use LauroGuedes\DemoMode\Support\ResetWindow;

/**
 * The layer that keeps the published account usable.
 *
 * A visitor signs in with the credentials on the login page, opens the profile
 * screen, and changes the email or the password. Both are ordinary features
 * working correctly. From that moment until the next reset the demo's login page
 * shows credentials that do not work and nobody else can get in.
 *
 * Neither of the two implementations this package was extracted from covered
 * this, which is why it is the one guard demo:doctor warns about when it is not
 * configured.
 *
 * Registered as an observer on every model in demo.guards.protected, so it sees
 * writes from a controller, a queued job, a Livewire component, or a seeder that
 * should have known better. An application that would rather declare it on the
 * model itself uses the PreventsDemoWrites trait; both end up here.
 *
 * What it does not see is worth being exact about, because "the record is
 * protected" is a sentence people will rely on. This is Eloquent model events,
 * so it catches what goes through a model instance and nothing else:
 *
 *   User::where('email', '…')->update([...])   — Eloquent\Builder::update() calls
 *                                                straight through to the query
 *                                                builder; no per-model events
 *   DB::table('users')->update([...])          — never touches Eloquent
 *   $user->updateQuietly([...])                — events suppressed by definition
 *   Model::withoutEvents(fn () => …)           — likewise
 *
 * None of those is a path a visitor can choose. A visitor can only do what the
 * application's own code does, so the gap matters exactly when the application
 * writes to the protected record that way itself — which is worth checking once,
 * and is why docs/write-guards.md lists these rather than leaving the sentence
 * sounding absolute. The connection guard is the only layer nothing routes
 * around.
 */
final readonly class ModelGuard
{
    public function __construct(
        private ProtectedRecords $protected,
        private Blocker $blocker,
    ) {}

    /**
     * Creation is not guarded, and that is a decision rather than an oversight.
     *
     * A record that does not exist yet is not the protected record — it is the
     * seeder making one, which is the whole reason there is something to protect.
     *
     * What this does still catch is a visitor renaming some other record *into*
     * the protected identity, because that record exists and its incoming values
     * match. The remaining gap is a visitor creating a second row claiming the
     * same identity; a unique index on the matched attribute is what closes it,
     * and any application publishing credentials by email already has one.
     */
    public function saving(Model $model): void
    {
        if (! $model->exists) {
            return;
        }

        $this->guard($model, 'it is protected from changes in this demonstration');
    }

    public function deleting(Model $model): void
    {
        $this->guard($model, 'it cannot be deleted in this demonstration');
    }

    public function restoring(Model $model): void
    {
        $this->guard($model, 'it cannot be restored in this demonstration');
    }

    public function forceDeleting(Model $model): void
    {
        $this->guard($model, 'it cannot be deleted in this demonstration');
    }

    private function guard(Model $model, string $reason): void
    {
        /*
         * The reset is the one writer allowed to touch this record — it is what
         * creates it. Skipping creates alone was not enough: see ResetWindow.
         */
        if (ResetWindow::isOpen()) {
            return;
        }

        if (! $this->protected->matches($model)) {
            return;
        }

        $this->blocker->blocked('model', $model::class, $reason);

        throw DemoWriteProhibited::write();
    }
}
