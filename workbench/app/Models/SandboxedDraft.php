<?php

declare(strict_types=1);

namespace Workbench\App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use LauroGuedes\DemoMode\Sandbox\BelongsToSandbox;

/**
 * A sandboxed model that also soft-deletes.
 *
 * Its own fixture because the purger has a branch for it: a soft delete would
 * leave the row in the table still carrying a sandbox id nobody can reach, which
 * is the exact state purging exists to end.
 *
 * @property int $id
 * @property string $body
 */
class SandboxedDraft extends Model
{
    use BelongsToSandbox;
    use SoftDeletes;

    public $timestamps = false;

    protected $table = 'demo_drafts';

    protected $guarded = [];
}
