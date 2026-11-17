<?php

namespace Database\Seeders;

use App\Enums\Role;
use App\Enums\UserType;
use App\Models\User;
use Illuminate\Database\Seeder;

/**
 * Creates the first Super Admin from ADMIN_NAME / ADMIN_EMAIL / ADMIN_PASSWORD in .env.
 * Safe to re-run: it never overwrites an existing user's password.
 */
class AdminUserSeeder extends Seeder
{
    public function run(): void
    {
        $email = env('ADMIN_EMAIL', 'admin@paint-mvp.test');

        $user = User::firstOrCreate(
            ['email' => $email],
            [
                'type' => UserType::Staff,
                'name' => env('ADMIN_NAME', 'Super Admin'),
                'password' => env('ADMIN_PASSWORD', 'password'),
                'is_active' => true,
            ],
        );

        if ($user->email_verified_at === null) {
            $user->forceFill(['email_verified_at' => now()])->save();
        }

        $user->assignRole(Role::SuperAdmin->value);
    }
}
