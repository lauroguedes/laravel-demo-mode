<?php

declare(strict_types=1);

namespace LauroGuedes\DemoMode\Sandbox;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Prunable;
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
