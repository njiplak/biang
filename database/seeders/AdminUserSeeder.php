<?php

namespace Database\Seeders;

use App\Models\AdminUser;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Role;

/**
 * Section 3: platform staff sit on a completely separate login from customers.
 *
 * Credentials come from the environment so a real deployment does not ship with
 * a known password. The local fallback is deliberately obvious.
 */
class AdminUserSeeder extends Seeder
{
    public function run(): void
    {
        $admin = AdminUser::updateOrCreate(
            ['email' => env('ADMIN_EMAIL', 'admin@example.com')],
            [
                'name' => env('ADMIN_NAME', 'Platform Admin'),
                'password' => Hash::make(env('ADMIN_PASSWORD', 'password')),
                'is_active' => true,
            ],
        );

        // The bootstrap account has to be able to grant roles to everyone else,
        // so it starts as super-admin. AdminRoleSeeder runs before this one.
        if (Role::where('name', 'super-admin')->where('guard_name', 'admin')->exists()) {
            $admin->assignRole('super-admin');
        }
    }
}
