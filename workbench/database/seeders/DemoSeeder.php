<?php

declare(strict_types=1);

namespace Workbench\Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use LauroGuedes\DemoMode\Facades\Demo;

/**
 * The demonstration data for the integration test's application.
 *
 * Reads the published password rather than inventing one, which is how a real
 * seeder should do it: the login page and the database stay in agreement without
 * either side knowing what the password is.
 */
class DemoSeeder extends Seeder
{
    public function run(): void
    {
        DB::table('demo_users')->insert([
            'email' => 'admin@demo.test',
            'password' => bcrypt(Demo::credentials()['password'] ?? 'fallback'),
        ]);

        DB::table('demo_posts')->insert([
            ['title' => 'A seeded post'],
            ['title' => 'Another seeded post'],
        ]);
    }
}
