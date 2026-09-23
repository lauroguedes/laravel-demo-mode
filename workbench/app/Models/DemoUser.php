<?php

declare(strict_types=1);

namespace Workbench\App\Models;

use Illuminate\Foundation\Auth\User as Authenticatable;

/**
 * The minimum an authenticatable needs to be, for the restriction tests.
 *
 * The columns are annotated because larastan runs with checkModelProperties on
 * and the workbench has no schema it can read them from.
 *
 * @property int $id
 * @property string $email
 * @property string $password
 */
class DemoUser extends Authenticatable
{
    public $timestamps = false;

    protected $table = 'demo_users';

    protected $guarded = [];
}
