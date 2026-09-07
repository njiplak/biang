<?php

namespace Database\Factories;

use App\Enums\FeatureAggregation;
use App\Enums\FeatureType;
use App\Enums\ResetPeriod;
use App\Models\Feature;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Feature> */
class FeatureFactory extends Factory
{
    public function definition(): array
    {
        return [
            'key' => fake()->unique()->slug(2),
            'name' => fake()->words(2, true),
            'type' => FeatureType::Limit,
            'aggregation' => FeatureAggregation::Gauge,
            'reset_period' => ResetPeriod::None,
            'unit' => null,
            'is_enforced' => true,
            'sort_order' => 0,
        ];
    }

    public function metered(): static
    {
        return $this->state(fn () => [
            'type' => FeatureType::Metered,
            'aggregation' => FeatureAggregation::Counter,
            'reset_period' => ResetPeriod::CalendarMonth,
        ]);
    }

    public function boolean(): static
    {
        return $this->state(fn () => ['type' => FeatureType::Boolean]);
    }
}
