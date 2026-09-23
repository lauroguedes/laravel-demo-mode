<?php

declare(strict_types=1);

namespace Workbench\App\Models;

use Illuminate\Database\Eloquent\Model;
use LauroGuedes\DemoMode\Guards\PreventsDemoWrites;

/**
 * A model that declares its own protection rather than being listed in the
 * provider's loop, so the trait is exercised by the same tests as the config
 * path it is an alternative to.
 */
class GuardedWidget extends Model
{
    use PreventsDemoWrites;

    public $timestamps = false;

    protected $table = 'demo_widgets';

    protected $guarded = [];
}
