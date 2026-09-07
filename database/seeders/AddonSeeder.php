<?php

namespace Database\Seeders;

use App\Enums\AddonKind;
use App\Enums\BillingInterval;
use App\Models\Addon;
use App\Models\AddonPrice;
use App\Models\Feature;
use App\Models\Plan;
use App\Support\Features;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Spec section 4's add-ons. Provisional pricing, like the plans - section 13.1
 * still gates the real numbers.
 *
 * The seat add-on is the one that matters structurally: section 7's "a seat
 * limit should be a sales moment, not a wall" has nothing to offer without it,
 * and the paid plans are where that offer applies (section 12 keeps the free
 * tier out of the payment provider entirely).
 */
class AddonSeeder extends Seeder
{
    public function run(): void
    {
        DB::transaction(function () {
            $seats = Feature::firstWhere('key', Features::SEATS);
            if ($seats === null) {
                return;
            }

            $seat = Addon::updateOrCreate(['key' => 'extra-seat'], [
                'name' => 'Extra seat',
                'description' => 'One more person in the workspace.',
                'kind' => AddonKind::Quantity,
                'feature_id' => $seats->id,
                'grant_per_unit' => 1,
                'max_quantity' => 500,
            ]);
            $this->price($seat, 900);

            /*
             * There was an "Unlimited projects" unlock here at $19/month,
             * attached to both paid plans and purchasable. Nothing meters
             * projects and no product surface creates one, so it removed a
             * ceiling that could never be reached - real money for nothing.
             *
             * SubscriptionService now refuses to sell any add-on whose feature
             * is not in Features::MEASURED, so this cannot come back by hand
             * through the console either.
             */

            // Section 4 caps paid add-ons at 10 per plan; both paid plans sell these.
            foreach (Plan::where('is_free', false)->get() as $plan) {
                $plan->addons()->syncWithoutDetaching(Addon::pluck('id')->all());
            }
        });
    }

    private function price(Addon $addon, int $amountMinor): void
    {
        AddonPrice::updateOrCreate(
            [
                'addon_id' => $addon->id,
                'billing_interval' => BillingInterval::Month,
                'currency' => 'USD',
                'archived_at' => null,
            ],
            ['amount_minor' => $amountMinor],
        );
    }
}
