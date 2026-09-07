<?php

namespace App\Contract\Billing;

use App\Models\AddonPrice;
use App\Models\PlanPrice;

/**
 * Keeps our catalogue and Dodo's product list in step.
 *
 * Section 10 lets staff "change what we sell without an engineer", and section
 * 8 makes Dodo the seller - so a price is only real once it exists in both
 * places. Until then it is a number in our database that nobody can buy.
 *
 * Separate from PaymentGatewayContract on purpose: the gateway is a dumb edge
 * that knows how to talk to Dodo, and this is the policy about WHEN we do.
 */
interface CatalogPublisherContract
{
    /**
     * Publish a price so it can be bought, storing the provider's product id.
     *
     * Idempotent: a price that already carries an id is returned untouched, so
     * a retry after a partial failure cannot create a second product for the
     * same price and orphan the first.
     */
    public function publish(PlanPrice|AddonPrice $price): PlanPrice|AddonPrice;

    /**
     * Section 10: "Retire a plan without breaking the customers already on it."
     * Never throws - retiring is our decision and must not be blocked by their
     * availability; a product left live at their end can only be re-archived.
     */
    public function retire(PlanPrice|AddonPrice $price): void;

    /**
     * Every live price with no provider product behind it - the ones a customer
     * would be offered and could not buy.
     *
     * @return array{plan: \Illuminate\Support\Collection<int, PlanPrice>, addon: \Illuminate\Support\Collection<int, AddonPrice>}
     */
    public function unpublished(): array;
}
