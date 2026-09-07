<?php

namespace App\Contract\Billing;

use App\Models\PlanPrice;
use App\Models\User;
use App\Models\Workspace;

/**
 * The only place the app talks OUT to Dodo. Everything inbound arrives as a
 * webhook and goes through ReconcilerContract instead.
 *
 * Behind a contract for a specific reason: section 14 phase 3 requires the
 * product to be sellable by hand with no payment provider wired up at all, and
 * that stays true only while the provider is one swappable edge rather than a
 * dependency threaded through the billing services.
 */
interface PaymentGatewayContract
{
    /**
     * Start a hosted checkout for a workspace, returning the URL to send them
     * to. Nothing about our own state changes here - the subscription becomes
     * real when the webhook arrives.
     */
    public function createCheckout(
        Workspace $workspace,
        PlanPrice $price,
        User $buyer,
        string $returnUrl,
        string $cancelUrl,
    ): string;
}
