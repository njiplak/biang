<?php

namespace App\Service\Public;

use App\Contract\Public\PricingContract;
use App\Models\Feature;
use App\Models\Plan;
use App\Models\PlanPrice;

/**
 * Section 11: "Pricing is published by our app and read by the marketing site,
 * so a price change in the admin console updates both places at once. Nobody
 * retypes a price."
 *
 * This is the read side of the seam whose write side is the admin catalogue.
 * It is deliberately the ONLY thing on that seam: the marketing site renders
 * whatever this returns, including the destination of both buttons, so adding a
 * plan never needs a second deploy in a second repository.
 */
class PricingService implements PricingContract
{
    /** Section 13.2 still lists confirming 14 days as open. */
    private const TRIAL_DAYS = 14;

    public function published(): array
    {
        // `public()` is active AND is_public, so a retired plan and an
        // internal-only one are both invisible here while continuing to work
        // perfectly for the customers already on them.
        $plans = Plan::query()
            ->public()
            ->with(['prices' => fn ($query) => $query->active(), 'features'])
            ->orderBy('sort_order')
            ->get();

        return [
            'plans' => $plans->map(fn (Plan $plan) => $this->planPayload($plan))->all(),
            // Named separately so the marketing site can describe the trial
            // without hardcoding a number that section 13.2 may still change.
            'trial_days' => self::TRIAL_DAYS,
            'signup_url' => route('register'),
            'generated_at' => now()->toIso8601String(),
        ];
    }

    private function planPayload(Plan $plan): array
    {
        return [
            'code' => $plan->code,
            'name' => $plan->name,
            'description' => $plan->description,
            'is_free' => $plan->is_free,
            'sort_order' => $plan->sort_order,
            // Keyed by interval so the marketing site's monthly/yearly toggle
            // does not have to search an array.
            'prices' => $plan->prices
                ->mapWithKeys(fn (PlanPrice $price) => [
                    $price->billing_interval->value => [
                        'currency' => $price->currency,
                        // Integer minor units, as everywhere else. Formatting is
                        // the marketing site's business; rounding here would be
                        // a second place for money to go wrong.
                        'amount_minor' => $price->amount_minor,
                    ],
                ])
                ->all(),
            'limits' => $plan->features
                ->map(fn (Feature $feature) => [
                    'key' => $feature->key,
                    'name' => $feature->name,
                    'unit' => $feature->unit,
                    // Null is unlimited, the same as everywhere in the
                    // entitlement layer.
                    'value' => $feature->pivot->value === null ? null : (int) $feature->pivot->value,
                ])
                ->values()
                ->all(),
            /*
             * Section 11's "two buttons, two destinations", resolved here rather
             * than assembled by the marketing site. Free goes straight to
             * signup; a paid plan carries itself through so the trial can start
             * on the right plan at the end.
             */
            'signup_url' => $plan->is_free
                ? route('register')
                : route('register', ['plan' => $plan->code]),
        ];
    }
}
