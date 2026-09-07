<?php

namespace Database\Factories;

use App\Models\Plan;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Plan> */
class PlanFactory extends Factory
{
    public function definition(): array
    {
        $name = fake()->unique()->word();

        return [
            'code' => str($name)->slug()->append('-'.fake()->unique()->numberBetween(1, 999999))->toString(),
            'name' => ucfirst($name),
            'description' => fake()->sentence(),
            'is_public' => true,
            'is_free' => false,
            'sort_order' => 0,
        ];
    }

    public function free(): static
    {
        return $this->state(fn () => ['is_free' => true, 'code' => 'free', 'name' => 'Free']);
    }

    public function archived(): static
    {
        return $this->state(fn () => ['archived_at' => now()]);
    }

    public function hidden(): static
    {
        return $this->state(fn () => ['is_public' => false]);
    }
}
