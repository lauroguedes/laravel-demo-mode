<?php

declare(strict_types=1);

namespace Workbench\App\Models;

use Illuminate\Database\Eloquent\Model;
use LauroGuedes\DemoMode\Sandbox\BelongsToSandbox;

/**
 * A model a visitor creates rows in, for the isolation tests.
 */
class SandboxedNote extends Model
{
    use BelongsToSandbox;

    public $timestamps = false;

    protected $table = 'demo_notes';

    protected $guarded = [];
}
