<?php

declare(strict_types=1);

namespace Workbench\App\Models;

use Illuminate\Foundation\Auth\User as Authenticatable;

/**
 * The minimum an authenticatable needs to be, for the restriction tests.
 */
class DemoUser extends Authenticatable
{
    public $timestamps = false;

    protected $table = 'demo_users';

    protected $guarded = [];
}
