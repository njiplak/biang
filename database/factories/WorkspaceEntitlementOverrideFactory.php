<?php

namespace Database\Factories;

use App\Models\AdminUser;
use App\Models\Feature;
use App\Models\Workspace;
use App\Models\WorkspaceEntitlementOverride;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<WorkspaceEntitlementOverride> */
class WorkspaceEntitlementOverrideFactory extends Factory
{
    public function definition(): array
    {
        return [
            'workspace_id' => Workspace::factory(),
            'feature_id' => Feature::factory(),
            'value' => fake()->numberBetween(10, 500),
            'reason' => 'Enterprise negotiation',
            'granted_by_admin_id' => AdminUser::factory(),
        ];
    }

    public function revoked(): static
    {
        return $this->state(fn () => ['revoked_at' => now()]);
    }
}
