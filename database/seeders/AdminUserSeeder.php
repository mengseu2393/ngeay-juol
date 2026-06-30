<?php

namespace Database\Seeders;

use App\Enums\UserStatus;
use App\Models\User;
use Illuminate\Database\Seeder;

class AdminUserSeeder extends Seeder
{
    public function run(): void
    {
        $email = env('RENTWISE_ADMIN_EMAIL', 'admin@rentwise.test');

        $user = User::firstOrCreate(
            ['email' => $email],
            [
                'name' => 'Super Admin',
                // plain value — the 'hashed' cast hashes on set (do not pre-hash)
                'password' => env('RENTWISE_ADMIN_PASSWORD', 'password'),
            ],
        );

        $user->status = UserStatus::Active; // status is not fillable — set explicitly
        $user->saveQuietly();

        if (! $user->hasRole('super_admin')) {
            $user->assignRole('super_admin');
        }
    }
}
