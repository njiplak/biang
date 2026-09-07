<?php

namespace Tests\Fakes;

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
    public array $usage = [];

    /** @var list<array<string, mixed>> */
    public array $cancellations = [];

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
        ];
    }

    public function cancelSubscription(Subscription $subscription, bool $atPeriodEnd = false): void
    {
        if ($this->broken) {
            throw new CancellationFailed('the payment provider did not respond');
        }

        if (blank($subscription->dodo_subscription_id)) {
            throw new CancellationFailed('this subscription is not held with the payment provider');
        }

        $this->cancellations[] = [
            'subscription' => $subscription->dodo_subscription_id,
            'at_period_end' => $atPeriodEnd,
        ];
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
}
