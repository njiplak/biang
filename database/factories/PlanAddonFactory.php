<?php

namespace Database\Factories;

use App\Models\Addon;
use App\Models\Plan;
use App\Models\PlanAddon;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<PlanAddon> */
class PlanAddonFactory extends Factory
{
    public function definition(): array
    {
        return [
            'plan_id' => Plan::factory(),
            'addon_id' => Addon::factory(),
            'sort_order' => 0,
        ];
    }
}
