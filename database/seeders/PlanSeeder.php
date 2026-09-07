<?php

namespace Database\Seeders;

use App\Enums\BillingInterval;
use App\Models\Feature;
use App\Models\Plan;
use App\Models\PlanPrice;
use App\Support\Features;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * PROVISIONAL. Spec section 13.1: "Everything about the plan table is blocked
 * on [the value metric]." These numbers exist so the app runs locally, and are
 * not a pricing decision - section 10 puts that in the admin console anyway.
 *
 * The free plan is NOT provisional: section 6 requires cancelling to land
 * somewhere, and EntitlementService throws NoFreePlanConfigured without exactly
 * one. That single row is a structural requirement, not a price.
 */
class PlanSeeder extends Seeder
{
    public function run(): void
    {
        DB::transaction(function () {
            /*
             * Section 13.1's value metric, answered: SEATS.
             *
             * Not a placeholder - it is the only thing this product has that a
             * customer consumes, and section 2 describes a workspace shared
             * with colleagues, which is what seat pricing is for.
             *
             * `projects` and `api_calls` remain rows in `features` because they
             * cost nothing and are ready the moment something meters them. They
             * are deliberately NOT on any plan: a limit nothing counts can
             * never be reached, so advertising one is advertising a fiction.
             * Adding it back is one number in the admin console, no deploy.
             */
            $free = $this->plan([
                'code' => 'free',
                'name' => 'Free',
                'description' => 'Perpetual free tier. No card, no expiry.',
                'is_free' => true,
                'sort_order' => 10,
            ], [
                Features::SEATS => 2,
            ]);

            // A free plan never reaches Dodo (section 12), so it has no price row.
            $this->assertNoPrices($free);

            $starter = $this->plan([
                'code' => 'starter',
                'name' => 'Starter',
                'description' => 'Provisional placeholder pending the value metric.',
                'sort_order' => 20,
            ], [
                Features::SEATS => 5,
            ]);

            $this->price($starter, BillingInterval::Month, 1900);
            $this->price($starter, BillingInterval::Year, 19_000);

            $pro = $this->plan([
                'code' => 'pro',
                'name' => 'Pro',
                'description' => 'Provisional placeholder pending the value metric.',
                'sort_order' => 30,
            ], [
                Features::SEATS => 25,
            ]);

            $this->price($pro, BillingInterval::Month, 4900);
            $this->price($pro, BillingInterval::Year, 49_000);
        });
    }

    /** @param  array<string, int|null>  $limits */
    private function plan(array $attributes, array $limits): Plan
    {
        $plan = Plan::updateOrCreate(['code' => $attributes['code']], $attributes);

        foreach ($limits as $key => $value) {
            $feature = Feature::where('key', $key)->first();

            if ($feature === null) {
                continue;
            }

            // syncWithoutDetaching would leave a stale value behind; the pivot
            // has to say exactly what the plan grants today.
            $plan->features()->syncWithoutDetaching([$feature->id => ['value' => $value]]);
            $plan->features()->updateExistingPivot($feature->id, ['value' => $value]);
        }

        return $plan->fresh('features');
    }

    private function price(Plan $plan, BillingInterval $interval, int $amountMinor, string $currency = 'USD'): PlanPrice
    {
        // Matched on the active row for this plan/interval/currency so re-running
        // the seeder updates in place rather than tripping the partial unique
        // index. A real price CHANGE inserts a new row and archives the old one -
        // that is the admin console's job, not this seeder's.
        return PlanPrice::updateOrCreate(
            [
                'plan_id' => $plan->id,
                'billing_interval' => $interval,
                'currency' => $currency,
                'archived_at' => null,
            ],
            ['amount_minor' => $amountMinor],
        );
    }

    private function assertNoPrices(Plan $plan): void
    {
        $plan->prices()->delete();
    }
}
