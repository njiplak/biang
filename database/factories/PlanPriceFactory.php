<?php

namespace Database\Factories;

use App\Enums\BillingInterval;
use App\Models\Plan;
use App\Models\PlanPrice;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<PlanPrice> */
class PlanPriceFactory extends Factory
{
    public function definition(): array
    {
        return [
            'plan_id' => Plan::factory(),
            'billing_interval' => BillingInterval::Month,
            'currency' => 'USD',
            'amount_minor' => fake()->numberBetween(900, 19900),
        ];
    }

    public function yearly(): static
    {
        return $this->state(fn () => ['billing_interval' => BillingInterval::Year]);
    }

    public function archived(): static
    {
        return $this->state(fn () => ['archived_at' => now()]);
    }
}
