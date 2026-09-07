<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        // firstOrCreate so the seeder stays re-runnable, like the rest of them.
        User::firstOrCreate(
            ['email' => 'test@example.com'],
            User::factory()->raw(['email' => 'test@example.com', 'name' => 'Test User']),
        );

        $this->call([
            SettingSeeder::class,
            // staff RBAC before the staff account, so it can be given a role
            AdminRoleSeeder::class,
            AdminUserSeeder::class,
            // features before plans: plan limits attach to feature rows
            FeatureSeeder::class,
            PlanSeeder::class,
            AddonSeeder::class,
        ]);
    }
}
