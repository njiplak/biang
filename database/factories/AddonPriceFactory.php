<?php

namespace Database\Factories;

use App\Enums\BillingInterval;
use App\Models\Addon;
use App\Models\AddonPrice;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<AddonPrice> */
class AddonPriceFactory extends Factory
{
    public function definition(): array
    {
        return [
            'addon_id' => Addon::factory(),
            'billing_interval' => BillingInterval::Month,
            'currency' => 'USD',
            'amount_minor' => fake()->numberBetween(100, 4900),
        ];
    }

    public function archived(): static
    {
        return $this->state(fn () => ['archived_at' => now()]);
    }
}
