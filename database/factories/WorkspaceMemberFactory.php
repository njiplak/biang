<?php

namespace Database\Factories;

use App\Enums\WorkspaceRole;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceMember;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<WorkspaceMember> */
class WorkspaceMemberFactory extends Factory
{
    public function definition(): array
    {
        return [
            'workspace_id' => Workspace::factory(),
            'user_id' => User::factory(),
            'role' => WorkspaceRole::Member,
            'joined_at' => now(),
        ];
    }

    public function owner(): static
    {
        return $this->state(fn () => ['role' => WorkspaceRole::Owner]);
    }

    public function admin(): static
    {
        return $this->state(fn () => ['role' => WorkspaceRole::Admin]);
    }

    public function billingManager(): static
    {
        return $this->state(fn () => ['role' => WorkspaceRole::BillingManager]);
    }

    public function viewer(): static
    {
        return $this->state(fn () => ['role' => WorkspaceRole::Viewer]);
    }
}
