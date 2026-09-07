<?php

namespace Database\Factories;

use App\Enums\BillingSource;
use App\Enums\SubscriptionStatus;
use App\Models\AdminUser;
use App\Models\Plan;
use App\Models\PlanPrice;
use App\Models\Subscription;
use App\Models\Workspace;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Subscription> */
class SubscriptionFactory extends Factory
{
    public function definition(): array
    {
        $plan = Plan::factory()->create();

        return [
            'workspace_id' => Workspace::factory(),
            'plan_id' => $plan->id,
            // the price must belong to the plan it was sold on
            'plan_price_id' => PlanPrice::factory()->for($plan),
            'status' => SubscriptionStatus::Active,
            'billing_source' => BillingSource::Dodo,
            'dodo_subscription_id' => 'sub_'.fake()->unique()->bothify('??##########'),
            'current_period_start' => now()->startOfMonth(),
            'current_period_end' => now()->endOfMonth(),
            'cancel_at_period_end' => false,
        ];
    }

    public function trialing(): static
    {
        return $this->state(fn () => [
            'status' => SubscriptionStatus::Trialing,
            'trial_ends_at' => now()->addDays(14),
        ]);
    }

    public function pastDue(): static
    {
        return $this->state(fn () => ['status' => SubscriptionStatus::PastDue]);
    }

    public function canceled(): static
    {
        return $this->state(fn () => [
            'status' => SubscriptionStatus::Canceled,
            'canceled_at' => now(),
        ]);
    }

    /** Section 10: a plan granted by hand, with no payment behind it. */
    public function manual(): static
    {
        return $this->state(fn () => [
            'billing_source' => BillingSource::Manual,
            'dodo_subscription_id' => null,
            'granted_by_admin_id' => AdminUser::factory(),
            'grant_reason' => 'Comped for launch partner',
        ]);
    }
}
