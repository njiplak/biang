<?php

namespace Database\Factories;

use App\Models\UsageRecord;
use App\Models\Workspace;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<UsageRecord> */
class UsageRecordFactory extends Factory
{
    public function definition(): array
    {
        return [
            'workspace_id' => Workspace::factory(),
            'feature_key' => 'api_calls',
            'quantity' => fake()->numberBetween(1, 1000),
            'occurred_at' => now(),
            'idempotency_key' => (string) Str::ulid(),
            'metadata' => [],
        ];
    }

    public function reported(): static
    {
        return $this->state(fn () => [
            'reported_at' => now(),
            'dodo_event_id' => 'evt_'.fake()->unique()->bothify('??########'),
        ]);
    }
}
