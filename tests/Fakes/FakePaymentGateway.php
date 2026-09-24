<?php

namespace Tests\Fakes;

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

/**
 * A Dodo that answers, and records what it was asked.
 *
 * One shared fake rather than an anonymous class per test file: the contract is
 * the seam the whole billing integration hangs off, and every method added to
 * it used to break every test that had stubbed it by hand. Now it breaks here,
 * once.
 *
 * `->broken()` is the other half. Section 14 phase 3 keeps the product sellable
 * with no provider wired up at all, so "the provider is down" is a supported
 * state that deserves the same first-class fake as the happy path.
 */
class FakePaymentGateway implements PaymentGatewayContract
{
    /** @var list<array<string, mixed>> */
    public array $published = [];

    /** @var list<string> */
    public array $archived = [];

    /** @var list<array<string, mixed>> */
    public array $checkouts = [];

    /** @var list<array<string, mixed>> */
    public array $publishedAddons = [];

    /** @var list<array<string, mixed>> */
    public array $planChanges = [];

    /** @var list<array<string, mixed>> */
    public array $previews = [];

    /** What previewPlanChange answers. Overwrite it to price a test. */
    public array $preview = [
        'amount_minor' => 1200,
        'currency' => 'USD',
        'tax_minor' => 0,
        'credit_minor' => 2500,
    ];

    /** @var list<array<string, mixed>> */
    public array $usage = [];

    /** @var list<array<string, mixed>> */
    public array $cancellations = [];

    /** @var list<string> provider subscription ids, in call order */
    public array $resumptions = [];

    /** @var list<string> provider subscription ids whose scheduled change was dropped */
    public array $scheduledChangeCancellations = [];

    /**
     * What Dodo would say about a subscription, keyed by their id. Shaped like
     * the `data` block of their webhook, because that is what the real gateway
     * translates their SDK objects into.
     *
     * @var array<string, array<string, mixed>>
     */
    public array $remoteSubscriptions = [];

    /** @var array<string, array<string, mixed>> keyed by payment id */
    public array $remotePayments = [];

    /** Every read, in order, so a test can assert nothing was asked twice. */
    public array $lookups = [];

    public int $nextProductId = 1;

    public int $nextAddonId = 1;

    private bool $broken = false;

    public string $checkoutUrl = 'https://checkout.dodopayments.test/session/abc';

    public string $portalUrl = 'https://portal.dodopayments.test/session/abc';

    /** Every outbound call now fails the way an unreachable provider does. */
    public function broken(): static
    {
        $this->broken = true;

        return $this;
    }

    public function createCheckout(
        Workspace $workspace,
        PlanPrice $price,
        User $buyer,
        string $returnUrl,
        string $cancelUrl,
        ?int $trialPeriodDays = null,
    ): string {
        if ($this->broken) {
            throw new CheckoutUnavailable('the payment provider did not respond');
        }

        if (blank($price->dodo_product_id)) {
            throw new CheckoutUnavailable('this price is not published to the payment provider yet');
        }

        $this->checkouts[] = [
            'workspace' => $workspace->ulid,
            'plan_price_id' => $price->id,
            'buyer' => $buyer->email,
            'trial_period_days' => $trialPeriodDays,
        ];

        return $this->checkoutUrl;
    }

    public function customerPortalUrl(Workspace $workspace, string $returnUrl): string
    {
        if ($this->broken) {
            throw new PortalUnavailable('the payment provider did not respond');
        }

        if (blank($workspace->dodo_customer_id)) {
            throw new PortalUnavailable('this workspace has no payment account with the provider yet');
        }

        return $this->portalUrl;
    }

    public function publishProduct(
        string $name,
        string $currency,
        int $amountMinor,
        BillingInterval $interval,
        ?string $description = null,
    ): string {
        if ($this->broken) {
            throw new ProductPublishFailed('the payment provider did not respond');
        }

        $this->published[] = [
            'name' => $name,
            'currency' => $currency,
            'amountMinor' => $amountMinor,
            'interval' => $interval->value,
            'description' => $description,
        ];

        return 'prod_'.$this->nextProductId++;
    }

    public function publishAddon(
        string $name,
        string $currency,
        int $amountMinor,
        ?string $description = null,
    ): string {
        if ($this->broken) {
            throw new ProductPublishFailed('the payment provider did not respond');
        }

        $this->publishedAddons[] = [
            'name' => $name,
            'currency' => $currency,
            'amountMinor' => $amountMinor,
            'description' => $description,
        ];

        return 'addon_'.$this->nextAddonId++;
    }

    public function changeSubscriptionPlan(
        Subscription $subscription,
        PlanPrice $price,
        array $addons = [],
        bool $atNextBillingDate = false,
        bool $replaceScheduled = false,
    ): void {
        if ($this->broken) {
            throw new PlanChangeUnavailable('the payment provider did not respond');
        }

        /*
         * The real gateway's preconditions, repeated deliberately. A fake that
         * accepts what Dodo would reject is a fake that hides the bug instead
         * of catching it - the whole point of these is that a test passing here
         * means the same call would work in production.
         */
        if (blank($subscription->dodo_subscription_id)) {
            throw new PlanChangeUnavailable('this subscription is not held with the payment provider');
        }

        if (blank($price->dodo_product_id)) {
            throw new PlanChangeUnavailable('the new plan is not published to the payment provider yet');
        }

        $this->planChanges[] = [
            'subscription' => $subscription->dodo_subscription_id,
            'product_id' => $price->dodo_product_id,
            'addons' => $addons,
            'at_next_billing_date' => $atNextBillingDate,
            'replace_scheduled' => $replaceScheduled,
        ];
    }

    public function cancelScheduledPlanChange(Subscription $subscription): void
    {
        if ($this->broken) {
            throw new PlanChangeUnavailable('the payment provider did not respond');
        }

        if (blank($subscription->dodo_subscription_id)) {
            throw new PlanChangeUnavailable('this subscription is not held with the payment provider');
        }

        $this->scheduledChangeCancellations[] = $subscription->dodo_subscription_id;
    }

    public function cancelSubscription(
        Subscription $subscription,
        bool $atPeriodEnd = false,
        ?CancellationFeedback $feedback = null,
        ?string $comment = null,
    ): void {
        if ($this->broken) {
            throw new CancellationFailed('the payment provider did not respond');
        }

        if (blank($subscription->dodo_subscription_id)) {
            throw new CancellationFailed('this subscription is not held with the payment provider');
        }

        $this->cancellations[] = [
            'subscription' => $subscription->dodo_subscription_id,
            'at_period_end' => $atPeriodEnd,
            'feedback' => $feedback?->value,
            'comment' => $comment,
        ];
    }

    public function resumeSubscription(Subscription $subscription): void
    {
        if ($this->broken) {
            throw new ResumeFailed('the payment provider did not respond');
        }

        if (blank($subscription->dodo_subscription_id)) {
            throw new ResumeFailed('this subscription is not held with the payment provider');
        }

        $this->resumptions[] = $subscription->dodo_subscription_id;
    }

    /**
     * @return array{amount_minor: int, currency: string, tax_minor: int|null, credit_minor: int}
     */
    public function previewPlanChange(
        Subscription $subscription,
        PlanPrice $price,
        array $addons = [],
        bool $replaceScheduled = false,
    ): array {
        if ($this->broken) {
            throw new PlanChangeUnavailable('the payment provider could not price this change');
        }

        // The real gateway's preconditions, repeated: a fake that prices what
        // Dodo would refuse hides the bug instead of catching it.
        if (blank($subscription->dodo_subscription_id)) {
            throw new PlanChangeUnavailable('this subscription is not held with the payment provider');
        }

        if (blank($price->dodo_product_id)) {
            throw new PlanChangeUnavailable('the new plan is not published to the payment provider yet');
        }

        $this->previews[] = [
            'subscription' => $subscription->dodo_subscription_id,
            'product_id' => $price->dodo_product_id,
        ];

        return $this->preview;
    }

    public function reportUsage(
        string $customerId,
        string $eventId,
        string $eventName,
        array $metadata,
        ?\DateTimeInterface $occurredAt = null,
    ): void {
        if ($this->broken) {
            throw new UsageReportFailed('the payment provider did not respond');
        }

        $this->usage[] = [
            'customer_id' => $customerId,
            'event_id' => $eventId,
            'event_name' => $eventName,
            'metadata' => $metadata,
            'occurred_at' => $occurredAt,
        ];
    }

    public function archiveProduct(string $productId): void
    {
        if ($this->broken) {
            throw new ProductPublishFailed('the payment provider did not respond');
        }

        $this->archived[] = $productId;
    }

    /**
     * @return array<string, mixed>
     */
    public function retrieveSubscription(string $providerSubscriptionId): array
    {
        $this->lookups[] = ['subscription', $providerSubscriptionId];

        if ($this->broken) {
            throw new ProviderLookupFailed('the payment provider did not respond');
        }

        return $this->remoteSubscriptions[$providerSubscriptionId]
            ?? throw new ProviderLookupFailed('no such subscription');
    }

    /**
     * @return list<string>
     */
    public function listSucceededPaymentIds(string $providerSubscriptionId): array
    {
        $this->lookups[] = ['payments', $providerSubscriptionId];

        if ($this->broken) {
            throw new ProviderLookupFailed('the payment provider did not respond');
        }

        return collect($this->remotePayments)
            // The real call filters at their end, and a fake that returned
            // failed attempts too would hide a bug rather than catch it: a
            // failed attempt counted as an invoice ends a trial nobody paid for.
            ->filter(fn (array $payment) => ($payment['subscription_id'] ?? null) === $providerSubscriptionId
                && ($payment['status'] ?? null) === 'succeeded')
            ->keys()
            ->all();
    }

    /**
     * @return array<string, mixed>
     */
    public function retrievePayment(string $paymentId): array
    {
        $this->lookups[] = ['payment', $paymentId];

        if ($this->broken) {
            throw new ProviderLookupFailed('the payment provider did not respond');
        }

        return $this->remotePayments[$paymentId]
            ?? throw new ProviderLookupFailed('no such payment');
    }
}
