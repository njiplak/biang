<?php

namespace App\Service\Billing;

use App\Contract\Billing\PaymentGatewayContract;
use App\Exceptions\Domain\CheckoutUnavailable;
use App\Models\PlanPrice;
use App\Models\User;
use App\Models\Workspace;
use Dodopayments\Client;
use Throwable;

/**
 * Section 8: Dodo is merchant of record, so the card form, tax and the receipt
 * are all theirs. We hand them a product and a way back.
 */
class DodoPaymentGateway implements PaymentGatewayContract
{
    public function createCheckout(
        Workspace $workspace,
        PlanPrice $price,
        User $buyer,
        string $returnUrl,
        string $cancelUrl,
    ): string {
        // A price that was never pushed to Dodo has no product to sell. Failing
        // here is far better than sending someone to a checkout for nothing.
        if (blank($price->dodo_product_id)) {
            throw new CheckoutUnavailable('this price is not published to the payment provider yet');
        }

        if (blank(config('dodo.api_key'))) {
            throw new CheckoutUnavailable('the payment provider is not configured');
        }

        try {
            $session = $this->client()->checkoutSessions->create(
                productCart: [[
                    'product_id' => $price->dodo_product_id,
                    'quantity' => 1,
                ]],
                customer: [
                    'email' => $buyer->email,
                    'name' => $buyer->name,
                ],
                // The link back to us. The first webhook for a brand new
                // subscription arrives before we have stored its provider id,
                // and this is what lets the reconciler find the workspace.
                metadata: [
                    'workspace_ulid' => $workspace->ulid,
                    'plan_price_id' => (string) $price->id,
                ],
                returnURL: $returnUrl,
                cancelURL: $cancelUrl,
            );
        } catch (Throwable $e) {
            throw new CheckoutUnavailable('the payment provider did not respond', $e);
        }

        return $session->checkoutURL
            ?? throw new CheckoutUnavailable('the payment provider returned no checkout URL');
    }

    private function client(): Client
    {
        return new Client(
            bearerToken: config('dodo.api_key'),
            webhookKey: config('dodo.webhook_key'),
            baseUrl: config('dodo.base_url'),
        );
    }
}
