<?php

namespace Database\Seeders;

use App\Enums\UserStatus;
use App\Models\User;
use Illuminate\Database\Seeder;

class RealAdminSeeder extends Seeder
{
    public function run(): void
    {
        $email = env('RENTWISE_REAL_ADMIN_EMAIL');
        $password = env('RENTWISE_REAL_ADMIN_PASSWORD');

        if (! $email || ! $password) {
            throw new \RuntimeException('Set RENTWISE_REAL_ADMIN_EMAIL and RENTWISE_REAL_ADMIN_PASSWORD before running RealAdminSeeder.');
        }

        $user = User::firstOrCreate(
            ['email' => $email],
            [
                'name' => env('RENTWISE_REAL_ADMIN_NAME', 'Admin'),
                // plain value — the 'hashed' cast hashes on set (do not pre-hash)
                'password' => $password,
            ],
        );

        $user->status = UserStatus::Active; // status is not fillable — set explicitly
        $user->saveQuietly();

        if (! $user->hasRole('super_admin')) {
            $user->assignRole('super_admin');
        }
    }
}
