<?php

namespace Database\Factories;

use App\Enums\EntitlementSource;
use App\Models\Workspace;
use App\Models\WorkspaceEntitlement;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<WorkspaceEntitlement> */
class WorkspaceEntitlementFactory extends Factory
{
    public function definition(): array
    {
        return [
            'workspace_id' => Workspace::factory(),
            'feature_key' => fake()->unique()->slug(2),
            'value' => fake()->numberBetween(1, 100),
            'source' => EntitlementSource::Plan,
            'computed_at' => now(),
        ];
    }

    public function unlimited(): static
    {
        return $this->state(fn () => ['value' => null]);
    }
}
