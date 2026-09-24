<?php

namespace App\Service\Billing;

use App\Contract\Billing\PaymentGatewayContract;
use App\Enums\BillingInterval;
use App\Enums\CancellationFeedback;
use App\Exceptions\Domain\CancellationFailed;
use App\Exceptions\Domain\CheckoutUnavailable;
use App\Exceptions\Domain\PlanChangeUnavailable;
use App\Exceptions\Domain\PortalUnavailable;
use App\Exceptions\Domain\ProductPublishFailed;
use App\Exceptions\Domain\ProviderLookupFailed;
use App\Exceptions\Domain\ResumeFailed;
use App\Exceptions\Domain\UsageReportFailed;
use App\Models\PlanPrice;
use App\Models\Subscription;
use App\Models\User;
use App\Models\Workspace;
use DateTimeInterface;
use Dodopayments\Client;
use Dodopayments\Misc\TaxCategory;
use Dodopayments\Payments\PaymentListParams\Status as PaymentStatus;
use Dodopayments\Products\Price\RecurringPrice;
use Dodopayments\Subscriptions\SubscriptionChangePlanParams\ProrationBillingMode;
use Dodopayments\Subscriptions\SubscriptionPreviewChangePlanParams\ProrationBillingMode as PreviewProrationBillingMode;
use Dodopayments\Subscriptions\SubscriptionStatus;
use Dodopayments\Subscriptions\SubscriptionUpdateParams\CancelReason;
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
     * error page: for a workspace that has never bought, it is expected.
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
    public function cancelSubscription(
        Subscription $subscription,
        bool $atPeriodEnd = false,
        ?CancellationFeedback $feedback = null,
        ?string $comment = null,
    ): void {
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
                // Always the customer: every call to this method comes from
                // them pressing cancel. A merchant-initiated stop is dunning,
                // and that is Dodo's own to record.
                cancelReason: CancelReason::CANCELLED_BY_CUSTOMER,
                cancellationComment: $comment,
                cancellationFeedback: $feedback?->value,
                status: $atPeriodEnd ? null : SubscriptionStatus::CANCELLED,
            );
        } catch (Throwable $e) {
            throw new CancellationFailed('the payment provider did not respond', $e);
        }
    }

    public function resumeSubscription(Subscription $subscription): void
    {
        if (blank($subscription->dodo_subscription_id)) {
            throw new ResumeFailed('this subscription is not held with the payment provider');
        }

        if (blank(config('dodo.api_key'))) {
            throw new ResumeFailed('the payment provider is not configured');
        }

        try {
            // false, not null: the SDK drops nulls, and an omitted flag leaves
            // the scheduled cancellation in place.
            $this->client()->subscriptions->update(
                subscriptionID: $subscription->dodo_subscription_id,
                cancelAtNextBillingDate: false,
            );
        } catch (Throwable $e) {
            throw new ResumeFailed('the payment provider did not respond', $e);
        }
    }

    /**
     * @param  list<array{addon_id: string, quantity: int}>  $addons
     * @return array{amount_minor: int, currency: string, tax_minor: int|null, credit_minor: int}
     */
    public function previewPlanChange(
        Subscription $subscription,
        PlanPrice $price,
        array $addons = [],
    ): array {
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
            /*
             * The same arguments the real change sends, or the number quoted is
             * not the number charged. PRORATED_IMMEDIATELY especially: it is
             * what decides whether this comes back as a charge for the
             * difference or the full price of the new plan.
             */
            $preview = $this->client()->subscriptions->previewChangePlan(
                subscriptionID: $subscription->dodo_subscription_id,
                productID: $price->dodo_product_id,
                prorationBillingMode: PreviewProrationBillingMode::PRORATED_IMMEDIATELY,
                quantity: 1,
                addons: $addons === [] ? null : $addons,
            );
        } catch (Throwable $e) {
            throw new PlanChangeUnavailable('the payment provider could not price this change', $e);
        }

        $summary = $preview->immediateCharge->summary;

        return [
            'amount_minor' => $summary->totalAmount,
            'currency' => $summary->currency,
            'tax_minor' => $summary->tax,
            // What the unused remainder of the current plan is worth back to
            // them. Shown separately because "you are charged 12" reads very
            // differently from "37 less 25 of credit".
            'credit_minor' => $summary->customerCredits,
        ];
    }

    /**
     * Ask Dodo what is true about one subscription, right now.
     *
     * The counterpart to waiting for a webhook, and the reason it exists: a
     * notification that is delayed, rejected at the door, or never sent at all
     * leaves our records saying nothing happened. Asking is the only way to
     * tell those apart from nothing having happened.
     *
     * The answer is reshaped into their own webhook `data` block so it can go
     * through the one reconciler rather than a second copy of its rules.
     *
     * @return array<string, mixed>
     */
    public function retrieveSubscription(string $providerSubscriptionId): array
    {
        if (blank(config('dodo.api_key'))) {
            throw new ProviderLookupFailed('the payment provider is not configured');
        }

        try {
            $subscription = $this->client()->subscriptions->retrieve($providerSubscriptionId);
        } catch (Throwable $e) {
            throw new ProviderLookupFailed('the payment provider did not answer about this subscription', $e);
        }

        return [
            'subscription_id' => $subscription->subscriptionID,
            'status' => $subscription->status,
            'metadata' => $subscription->metadata,
            'customer' => ['customer_id' => $subscription->customer->customerID],
            'trial_period_days' => $subscription->trialPeriodDays,
            'previous_billing_date' => $this->iso($subscription->previousBillingDate),
            'next_billing_date' => $this->iso($subscription->nextBillingDate),
            'cancel_at_next_billing_date' => $subscription->cancelAtNextBillingDate,
            'cancelled_at' => $this->iso($subscription->cancelledAt),
        ];
    }

    /**
     * @return list<string>
     */
    public function listSucceededPaymentIds(string $providerSubscriptionId): array
    {
        if (blank(config('dodo.api_key'))) {
            throw new ProviderLookupFailed('the payment provider is not configured');
        }

        try {
            /*
             * Filtered at their end rather than ours. A subscription accumulates
             * failed and abandoned attempts as well as settled ones, and only a
             * settled payment is an invoice - `hasBeenCharged` reads these to
             * decide whether a trial is over, so a failed attempt counted here
             * would end a trial that was never paid for.
             */
            $page = $this->client()->payments->list(
                status: PaymentStatus::SUCCEEDED,
                subscriptionID: $providerSubscriptionId,
            );

            $ids = [];

            // Paginated: a long-lived annual subscription outlives one page,
            // and stopping at the first would silently lose older invoices.
            foreach ($page as $payment) {
                $ids[] = $payment->paymentID;
            }
        } catch (Throwable $e) {
            throw new ProviderLookupFailed('the payment provider did not answer about these payments', $e);
        }

        return $ids;
    }

    /**
     * @return array<string, mixed>
     */
    public function retrievePayment(string $paymentId): array
    {
        if (blank(config('dodo.api_key'))) {
            throw new ProviderLookupFailed('the payment provider is not configured');
        }

        try {
            $payment = $this->client()->payments->retrieve($paymentId);
        } catch (Throwable $e) {
            throw new ProviderLookupFailed('the payment provider did not answer about this payment', $e);
        }

        return [
            'payment_id' => $payment->paymentID,
            'subscription_id' => $payment->subscriptionID,
            'status' => $payment->status,
            'currency' => $payment->currency,
            // Every figure copied, never computed: section 8 makes them
            // merchant of record, so tax is theirs to state.
            'total_amount' => $payment->totalAmount,
            'settlement_amount' => $payment->settlementAmount,
            'tax' => $payment->tax,
            'created_at' => $this->iso($payment->createdAt),
            'customer' => ['customer_id' => $payment->customer->customerID],
            'metadata' => $payment->metadata,
        ];
    }

    /** Their dates come back as objects; the reconciler parses strings. */
    private function iso(?DateTimeInterface $value): ?string
    {
        return $value?->format(DateTimeInterface::ATOM);
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
