<?php

namespace Database\Factories;

use App\Enums\DunningResolution;
use App\Models\DunningState;
use App\Models\Subscription;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<DunningState> */
class DunningStateFactory extends Factory
{
    public function definition(): array
    {
        return [
            'subscription_id' => Subscription::factory()->pastDue(),
            'workspace_id' => fn (array $attrs) => Subscription::findOrFail($attrs['subscription_id'])->workspace_id,
            'started_at' => now(),
            'attempt_count' => 1,
            'last_attempt_at' => now(),
            'last_failure_code' => 'card_declined',
            'grace_ends_at' => now()->addDays(14),
        ];
    }

    public function recovered(): static
    {
        return $this->state(fn () => [
            'resolved_at' => now(),
            'resolution' => DunningResolution::Recovered,
        ]);
    }
}
