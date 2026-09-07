<?php

namespace Database\Factories;

use App\Models\Addon;
use App\Models\AddonPrice;
use App\Models\Subscription;
use App\Models\SubscriptionItem;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<SubscriptionItem> */
class SubscriptionItemFactory extends Factory
{
    public function definition(): array
    {
        $addon = Addon::factory()->quantity()->create();

        return [
            'subscription_id' => Subscription::factory(),
            // must match the subscription's workspace or the composite foreign
            // key rejects the row - which is exactly the point of it
            'workspace_id' => fn (array $attrs) => Subscription::findOrFail($attrs['subscription_id'])->workspace_id,
            'addon_id' => $addon->id,
            'addon_price_id' => AddonPrice::factory()->for($addon),
            'quantity' => fake()->numberBetween(1, 10),
        ];
    }
}
