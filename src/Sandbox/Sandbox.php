<?php

declare(strict_types=1);

namespace LauroGuedes\DemoMode\Sandbox;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Prunable;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use LauroGuedes\DemoMode\Configuration;
use LauroGuedes\DemoMode\Events\SandboxExpired;

/**
 * One visitor's corner of the demonstration.
 *
 * A row rather than a cookie value, and that is the whole security design: the
 * identifier a visitor's browser sends is only ever a lookup key, and a key that
 * does not match a live row buys nothing. A forged one mints a new empty sandbox
 * instead of selecting somebody else's.
 *
 * @property string $id
 * @property CarbonImmutable|null $expires_at
 */
class Sandbox extends Model
{
    use Prunable;

    /**
     * The column a marked table carries.
     *
     * Here rather than on the trait, because a trait constant can only be read
     * through a class that uses it — and the scope, the trait and any migration
     * an application writes all need to name the same column.
     */
    public const string COLUMN = 'demo_sandbox_id';

    public $incrementing = false;

    protected $table = 'demo_sandboxes';

    protected $keyType = 'string';

    protected $guarded = [];

    /**
     * Whether a class is actually marked as belonging to a sandbox.
     *
     * Asked by the purger, which skips what it cannot safely delete from, and by
     * demo:doctor, which reports it. They had the check written out separately
     * and had already drifted — the sort of pair where one grows a rule and the
     * other does not, and the consequence is rows left behind in silence.
     *
     * @param  class-string  $class
     */
    public static function marks(string $class): bool
    {
        return in_array(BelongsToSandbox::class, class_uses_recursive($class), true);
    }

    /**
     * The table, created from one definition.
     *
     * Called by the published migration and by the Runner after a reset. A
     * strategy that restores a snapshot or a dump drops every table and brings
     * back only what its baseline contains — and a hand-maintained .sql file
     * does not contain this one. Without recreating it, a scoped demo came back
     * from a reset with the table gone, which the Manager reads as "the feature
     * was never set up" and serves unscoped. Every visitor after that reset
     * quietly shared one dataset.
     */
    public static function createTable(): void
    {
        Schema::create((new self)->getTable(), function (Blueprint $table): void {
            $table->string('id')->primary();
            $table->timestamp('expires_at')->nullable()->index();
            $table->timestamps();
        });
    }

    public function expired(?CarbonImmutable $at = null): bool
    {
        return $this->expires_at !== null
            && $this->expires_at->lessThanOrEqualTo($at ?? CarbonImmutable::now());
    }

    /**
     * The sandboxes nobody came back to.
     *
     * Through Laravel's own Prunable rather than a hand-written loop, so a demo
     * that has been up for a month deletes in chunks instead of loading every
     * expired row into memory at once.
     *
     * @return Builder<static>
     */
    public function prunable(): Builder
    {
        return static::query()
            ->whereNotNull('expires_at')
            ->where('expires_at', '<=', CarbonImmutable::now());
    }

    /**
     * Announced one at a time, which is what an application needs: the seam
     * exists so it can remove whatever it keeps per visitor outside the database.
     */
    protected function pruning(): void
    {
        /*
         * The rows go first, while the sandbox that explains them still exists.
         * Deleting the sandbox alone left them behind carrying an id that
         * matched nothing — no visitor could reach them, every scoped query's
         * index still carried them, and only a full reset cleared them.
         *
         * Off by a config key for the application that reads across sandboxes
         * itself, through withoutSandbox(), and would notice them going.
         */
        if (app(Configuration::class)->boolean('sandbox.prune_rows', true)) {
            app(Purger::class)->purge($this->id);
        }

        event(new SandboxExpired($this->id));
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['expires_at' => 'immutable_datetime'];
    }
}
