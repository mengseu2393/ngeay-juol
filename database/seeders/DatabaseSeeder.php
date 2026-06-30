<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([
            RolesAndPermissionsSeeder::class,
            AdminUserSeeder::class,
        ]);

        if (filter_var(env('SEED_DEMO', false), FILTER_VALIDATE_BOOL)) {
            $this->call(DemoDataSeeder::class);
        }
    }
}
