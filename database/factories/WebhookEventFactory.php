<?php

namespace Database\Factories;

use App\Models\WebhookEvent;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<WebhookEvent> */
class WebhookEventFactory extends Factory
{
    public function definition(): array
    {
        return [
            'provider' => 'dodo',
            'event_id' => 'evt_'.fake()->unique()->bothify('??############'),
            'event_type' => 'subscription.active',
            'payload' => ['data' => ['object' => 'subscription']],
            'signature_verified' => true,
            'occurred_at' => now(),
            'received_at' => now(),
            'attempts' => 0,
        ];
    }

    public function unverified(): static
    {
        return $this->state(fn () => ['signature_verified' => false]);
    }

    public function processed(): static
    {
        return $this->state(fn () => ['processed_at' => now()]);
    }
}
