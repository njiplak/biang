<?php

namespace Database\Factories;

use App\Models\AdminUser;
use App\Models\ImpersonationSession;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<ImpersonationSession> */
class ImpersonationSessionFactory extends Factory
{
    public function definition(): array
    {
        return [
            'admin_user_id' => AdminUser::factory(),
            'user_id' => User::factory(),
            'workspace_id' => Workspace::factory(),
            'reason' => 'Reproducing ticket #'.fake()->numberBetween(100, 9999),
            'started_at' => now(),
            'ip_address' => fake()->ipv4(),
        ];
    }

    public function ended(): static
    {
        return $this->state(fn () => ['ended_at' => now()]);
    }
}
