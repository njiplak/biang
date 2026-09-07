<?php

namespace App\Contract\Billing;

use App\Enums\BillingInterval;
use App\Models\PlanPrice;
use App\Models\Subscription;
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
     *
     * $trialPeriodDays is section 4's "a card is required to start": a trial is
     * not a different kind of thing, it is this same checkout with the first
     * $trialPeriodDays free. The card is collected now and charged at the end,
     * which is the only reason the auto-charge on day 15 is defensible.
     */
    public function createCheckout(
        Workspace $workspace,
        PlanPrice $price,
        User $buyer,
        string $returnUrl,
        string $cancelUrl,
        ?int $trialPeriodDays = null,
    ): string;

    /**
     * A one-time link into Dodo's own page for this workspace, where the card
     * and the invoices live (section 8 puts both on their side).
     *
     * Section 5 requires the app to offer this, and section 9 depends on it: the
     * past-due banner tells the customer to update their card, and this is the
     * only place they can. Short-lived and customer-specific, so it is created
     * per click rather than stored.
     *
     * @throws \App\Exceptions\Domain\PortalUnavailable when the workspace has no
     *                                                  payment account yet, or the provider cannot be reached
     */
    public function customerPortalUrl(Workspace $workspace, string $returnUrl): string;

    /**
     * Create the product at Dodo that one of our prices is sold as, returning
     * their id for us to store.
     *
     * Nothing can be bought until this has happened: a checkout needs a
     * product, and section 10 lets staff create prices "without an engineer" -
     * so the publishing has to be part of the catalogue, not a deploy.
     *
     * @throws \App\Exceptions\Domain\ProductPublishFailed
     */
    public function publishProduct(
        string $name,
        string $currency,
        int $amountMinor,
        BillingInterval $interval,
        ?string $description = null,
    ): string;

    /**
     * Section 10: "Retire a plan without breaking the customers already on
     * it." Archiving stops new purchases at their end too; existing
     * subscriptions are untouched, which is the whole point.
     *
     * @throws \App\Exceptions\Domain\ProductPublishFailed
     */
    public function archiveProduct(string $productId): void;

    /**
     * Publish an add-on, returning the provider's id for it.
     *
     * Separate from publishProduct because an add-on is a different resource at
     * Dodo, not a product: it carries its own price, inherits the billing
     * interval of whatever subscription it hangs off, and is attached by an
     * addon id that their product endpoints never return.
     *
     * @throws \App\Exceptions\Domain\ProductPublishFailed
     */
    public function publishAddon(
        string $name,
        string $currency,
        int $amountMinor,
        ?string $description = null,
    ): string;

    /**
     * Move a live subscription onto a different plan, prorated.
     *
     * Section 4: "Customers can switch between the two, and between plans, at
     * any time - the price difference is prorated." Dodo owns that arithmetic
     * because section 8 makes them merchant of record; we own the decision and
     * the seat check that has to pass first.
     *
     * $addons carries the quantity add-ons that must survive the move - Dodo
     * replaces the whole subscription line-up, so anything not listed is
     * dropped.
     *
     * @param  list<array{addon_id: string, quantity: int}>  $addons
     *
     * @throws \App\Exceptions\Domain\PlanChangeUnavailable
     */
    public function changeSubscriptionPlan(
        Subscription $subscription,
        PlanPrice $price,
        array $addons = [],
    ): void;

    /**
     * Report one metered event, so Dodo can bill for what was consumed.
     *
     * Section 4's third add-on kind is "billed on actual consumption", and
     * consumption happens in our product - so this is the one direction where
     * WE are the source of truth for something that becomes money. Section 8
     * still leaves the charging to them.
     *
     * $eventId is our idempotency key. Dodo ignores a repeat of one it has
     * already seen, which is what makes a retried job safe: metered usage is
     * money, and reporting it twice bills twice.
     *
     * @param  array<string, string>  $metadata
     *
     * @throws \App\Exceptions\Domain\UsageReportFailed
     */
    /**
     * Stop Dodo charging for a subscription.
     *
     * Section 8's table has cancelling working from both sides, and this is our
     * side of it. Without it a cancellation in our app takes the paid product
     * away and leaves the card being charged every month - the customer finds
     * out on a statement, and their recourse is a chargeback.
     *
     * $atPeriodEnd leaves them the time they have already paid for and stops
     * the next renewal. The immediate form ends it now, which is what our own
     * cancel does to access - see SubscriptionService::cancel.
     *
     * @throws \App\Exceptions\Domain\CancellationFailed
     */
    public function cancelSubscription(Subscription $subscription, bool $atPeriodEnd = false): void;

    public function reportUsage(
        string $customerId,
        string $eventId,
        string $eventName,
        array $metadata,
        ?\DateTimeInterface $occurredAt = null,
    ): void;
}
