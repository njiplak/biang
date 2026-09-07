<?php

namespace Database\Factories;

use App\Models\UsageCounter;
use App\Models\Workspace;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<UsageCounter> */
class UsageCounterFactory extends Factory
{
    public function definition(): array
    {
        return [
            'workspace_id' => Workspace::factory(),
            'feature_key' => fake()->unique()->slug(2),
            'period_start' => null, // gauge by default
            'period_end' => null,
            'used' => fake()->numberBetween(0, 50),
        ];
    }

    public function forPeriod(): static
    {
        return $this->state(fn () => [
            'period_start' => now()->startOfMonth(),
            'period_end' => now()->endOfMonth(),
        ]);
    }
}
