<?php

namespace App\Service\Billing;

use App\Contract\Billing\PaymentGatewayContract;
use App\Enums\BillingInterval;
use App\Exceptions\Domain\CancellationFailed;
use App\Exceptions\Domain\CheckoutUnavailable;
use App\Exceptions\Domain\PlanChangeUnavailable;
use App\Exceptions\Domain\PortalUnavailable;
use App\Exceptions\Domain\ProductPublishFailed;
use App\Exceptions\Domain\UsageReportFailed;
use App\Models\PlanPrice;
use App\Models\Subscription;
use App\Models\User;
use App\Models\Workspace;
use Dodopayments\Client;
use Dodopayments\Misc\TaxCategory;
use Dodopayments\Products\Price\RecurringPrice;
use Dodopayments\Subscriptions\SubscriptionChangePlanParams\ProrationBillingMode;
use Dodopayments\Subscriptions\SubscriptionStatus;
use Dodopayments\Subscriptions\TimeInterval;
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
        ?int $trialPeriodDays = null,
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
                    // Section 12 spends the trial on the PERSON who starts one,
                    // and by the time the webhook lands there is no session to
                    // ask who that was.
                    'started_by_user_id' => (string) $buyer->id,
                ],
                returnURL: $returnUrl,
                cancelURL: $cancelUrl,
                // Section 4: the trial IS this checkout, with the first days
                // free. Overrides whatever the product itself carries.
                subscriptionData: $trialPeriodDays === null
                    ? null
                    : ['trial_period_days' => $trialPeriodDays],
            );
        } catch (Throwable $e) {
            throw new CheckoutUnavailable('the payment provider did not respond', $e);
        }

        return $session->checkoutURL
            ?? throw new CheckoutUnavailable('the payment provider returned no checkout URL');
    }

    /**
     * Section 5: "open the payment provider's page for cards and invoices."
     *
     * The customer id is stamped on the workspace by DodoReconciler the first
     * time Dodo tells us about them, so a workspace that has never paid has
     * none - and there is genuinely nothing to open. That is a message, not an
     * error page: on the free tier it is the expected state.
     */
    public function customerPortalUrl(Workspace $workspace, string $returnUrl): string
    {
        if (blank($workspace->dodo_customer_id)) {
            throw new PortalUnavailable('this workspace has no payment account with the provider yet');
        }

        if (blank(config('dodo.api_key'))) {
            throw new PortalUnavailable('the payment provider is not configured');
        }

        try {
            $session = $this->client()->customers->customerPortal->create(
                customerID: $workspace->dodo_customer_id,
                returnURL: $returnUrl,
            );
        } catch (Throwable $e) {
            throw new PortalUnavailable('the payment provider did not respond', $e);
        }

        return $session->link;
    }

    /**
     * Section 10: staff change what we sell without an engineer, so the product
     * on Dodo's side has to be created from the catalogue rather than by hand in
     * their dashboard - otherwise every new price is a two-system chore that
     * silently half-completes.
     *
     * Tax category is SAAS for everything we sell. Section 8 makes Dodo the
     * merchant of record and worldwide tax their job, but the category is the
     * one input only we can supply.
     */
    public function publishProduct(
        string $name,
        string $currency,
        int $amountMinor,
        BillingInterval $interval,
        ?string $description = null,
    ): string {
        if (blank(config('dodo.api_key'))) {
            throw new ProductPublishFailed('the payment provider is not configured');
        }

        try {
            $product = $this->client()->products->create(
                name: $name,
                price: RecurringPrice::with(
                    currency: $currency,
                    discount: 0,
                    // Both counts are 1 of the SAME unit: an annual plan is one
                    // Year, billed every one Year. Splitting them (12 Months
                    // billed yearly) is how you get a monthly-looking product
                    // that charges annually.
                    paymentFrequencyCount: 1,
                    paymentFrequencyInterval: $this->interval($interval),
                    price: $amountMinor,
                    subscriptionPeriodCount: 1,
                    subscriptionPeriodInterval: $this->interval($interval),
                ),
                taxCategory: TaxCategory::SAAS,
                description: $description,
            );
        } catch (Throwable $e) {
            throw new ProductPublishFailed('the payment provider rejected it', $e);
        }

        return $product->productID;
    }

    /**
     * Section 4's add-ons. No interval is sent because Dodo's add-on takes the
     * billing interval of the subscription it is attached to - which is the
     * behaviour we want anyway: an extra seat on an annual plan is billed
     * annually, and being able to say otherwise would only let us configure a
     * contradiction.
     */
    public function publishAddon(
        string $name,
        string $currency,
        int $amountMinor,
        ?string $description = null,
    ): string {
        if (blank(config('dodo.api_key'))) {
            throw new ProductPublishFailed('the payment provider is not configured');
        }

        try {
            $addon = $this->client()->addons->create(
                currency: $currency,
                name: $name,
                price: $amountMinor,
                taxCategory: TaxCategory::SAAS,
                description: $description,
            );
        } catch (Throwable $e) {
            throw new ProductPublishFailed('the payment provider rejected it', $e);
        }

        return $addon->id;
    }

    public function archiveProduct(string $productId): void
    {
        if (blank(config('dodo.api_key'))) {
            throw new ProductPublishFailed('the payment provider is not configured');
        }

        try {
            $this->client()->products->archive($productId);
        } catch (Throwable $e) {
            throw new ProductPublishFailed('the payment provider rejected it', $e);
        }
    }

    /**
     * Section 4: "Customers can switch between the two, and between plans, at
     * any time — the price difference is prorated."
     *
     * PRORATED_IMMEDIATELY is that sentence: charge or credit for the unused
     * remainder of what they already paid for, now. The alternatives would
     * either bill the whole new price again (FULL_IMMEDIATELY) or move them
     * without settling the difference at all (DO_NOT_BILL), and neither is what
     * we told customers we do.
     *
     * Quantity is the PLAN's quantity, which is always one - a workspace holds
     * one subscription to one plan (section 12: one payment account per
     * workspace). Seats are add-ons, and travel in $addons.
     */
    public function changeSubscriptionPlan(
        Subscription $subscription,
        PlanPrice $price,
        array $addons = [],
    ): void {
        if (blank($subscription->dodo_subscription_id)) {
            throw new PlanChangeUnavailable('this subscription is not held with the payment provider');
        }

        if (blank($price->dodo_product_id)) {
            throw new PlanChangeUnavailable('the new plan is not published to the payment provider yet');
        }

        if (blank(config('dodo.api_key'))) {
            throw new PlanChangeUnavailable('the payment provider is not configured');
        }

        try {
            $this->client()->subscriptions->changePlan(
                subscriptionID: $subscription->dodo_subscription_id,
                productID: $price->dodo_product_id,
                prorationBillingMode: ProrationBillingMode::PRORATED_IMMEDIATELY,
                quantity: 1,
                // Dodo replaces the whole line-up, so add-ons the customer
                // already pays for have to be restated or they are silently
                // dropped along with the entitlements they grant.
                addons: $addons === [] ? null : $addons,
            );
        } catch (Throwable $e) {
            throw new PlanChangeUnavailable('the payment provider rejected the change', $e);
        }
    }

    /**
     * Section 4: metered add-ons are "billed on actual consumption", and the
     * consumption happens here, not there.
     *
     * Dodo's event carries no quantity field of its own - their meters
     * aggregate over metadata - so the amount travels as a metadata value,
     * which is also why every value here is a string.
     *
     * They reject a timestamp older than an hour or more than five minutes
     * ahead. Rather than lose the event, a late one is sent with no timestamp
     * at all and lands at receipt time: billed in the wrong period is a
     * discrepancy, never billed is lost revenue nobody can find.
     */
    public function reportUsage(
        string $customerId,
        string $eventId,
        string $eventName,
        array $metadata,
        ?\DateTimeInterface $occurredAt = null,
    ): void {
        if (blank(config('dodo.api_key'))) {
            throw new UsageReportFailed('the payment provider is not configured');
        }

        $withinWindow = $occurredAt !== null
            && $occurredAt->getTimestamp() > now()->subHour()->getTimestamp()
            && $occurredAt->getTimestamp() < now()->addMinutes(5)->getTimestamp();

        try {
            $this->client()->usageEvents->ingest(events: [array_filter([
                'customer_id' => $customerId,
                // Our idempotency key. A repeat of one they have already seen is
                // ignored at their end, which is what makes a retry safe.
                'event_id' => $eventId,
                'event_name' => $eventName,
                'metadata' => $metadata,
                'timestamp' => $withinWindow ? $occurredAt : null,
            ], fn ($value) => $value !== null)]);
        } catch (Throwable $e) {
            throw new UsageReportFailed('the payment provider rejected the event', $e);
        }
    }

    /**
     * Section 8: "Cancelling: us and Dodo - both work." This is our half.
     *
     * Two shapes, because they are genuinely different products: scheduling it
     * for the next billing date leaves the customer the time they have paid
     * for, while cancelling outright ends the subscription now. Which one we
     * use is SubscriptionService's decision, not this method's.
     */
    public function cancelSubscription(Subscription $subscription, bool $atPeriodEnd = false): void
    {
        if (blank($subscription->dodo_subscription_id)) {
            throw new CancellationFailed('this subscription is not held with the payment provider');
        }

        if (blank(config('dodo.api_key'))) {
            throw new CancellationFailed('the payment provider is not configured');
        }

        try {
            $this->client()->subscriptions->update(
                subscriptionID: $subscription->dodo_subscription_id,
                cancelAtNextBillingDate: $atPeriodEnd ? true : null,
                status: $atPeriodEnd ? null : SubscriptionStatus::CANCELLED,
            );
        } catch (Throwable $e) {
            throw new CancellationFailed('the payment provider did not respond', $e);
        }
    }

    /** Our billing interval in the provider's vocabulary. */
    private function interval(BillingInterval $interval): TimeInterval
    {
        return match ($interval) {
            BillingInterval::Month => TimeInterval::MONTH,
            BillingInterval::Year => TimeInterval::YEAR,
        };
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
