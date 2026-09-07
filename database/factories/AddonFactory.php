<?php

namespace Database\Factories;

use App\Enums\AddonKind;
use App\Models\Addon;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Addon> */
class AddonFactory extends Factory
{
    public function definition(): array
    {
        return [
            'key' => fake()->unique()->slug(2),
            'name' => fake()->words(2, true),
            'kind' => AddonKind::Unlock,
        ];
    }

    public function quantity(): static
    {
        return $this->state(fn () => ['kind' => AddonKind::Quantity, 'grant_per_unit' => 1]);
    }

    public function metered(): static
    {
        return $this->state(fn () => ['kind' => AddonKind::Metered]);
    }

    public function archived(): static
    {
        return $this->state(fn () => ['archived_at' => now()]);
    }
}
