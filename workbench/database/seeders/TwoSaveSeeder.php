<?php

declare(strict_types=1);

namespace Workbench\Database\Seeders;

use Illuminate\Database\Seeder;
use LauroGuedes\DemoMode\Facades\Demo;
use Workbench\App\Models\DemoUser;

/**
 * A seeder shaped like the ones real applications have: it creates the published
 * account and then saves it again.
 *
 * Through Eloquent rather than DB::table(), unlike the other seeder here, because
 * the guard this reproduces hooks model events. See Support\ResetWindow.
 */
class TwoSaveSeeder extends Seeder
{
    public function run(): void
    {
        $user = DemoUser::create([
            'email' => 'admin@demo.test',
            'password' => bcrypt(Demo::passwordFor('admin@demo.test') ?? 'fallback'),
        ]);

        $user->forceFill(['email' => 'admin@demo.test'])->save();
    }
}
