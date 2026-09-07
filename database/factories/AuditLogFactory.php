<?php

namespace Database\Factories;

use App\Models\AuditLog;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<AuditLog> */
class AuditLogFactory extends Factory
{
    public function definition(): array
    {
        return [
            'workspace_id' => Workspace::factory(),
            'actor_type' => User::class,
            'actor_id' => User::factory(),
            'action' => 'member.invited',
            'changes' => ['role' => ['member', 'admin']],
            'ip_address' => fake()->ipv4(),
        ];
    }

    public function byStaff(): static
    {
        return $this->state(fn () => [
            'actor_type' => \App\Models\AdminUser::class,
            'actor_id' => \App\Models\AdminUser::factory(),
        ]);
    }
}
