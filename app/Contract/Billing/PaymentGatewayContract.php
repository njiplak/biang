<?php

namespace App\Contract\Billing;

use App\Enums\BillingInterval;
use App\Enums\CancellationFeedback;
use App\Models\PlanPrice;
use App\Models\Subscription;
use App\Models\User;
use App\Models\Workspace;

/**
 * The only place the app talks OUT to Dodo.
 *
 * Inbound state changes go through ReconcilerContract. They reach it two ways:
 * unsolicited, as a webhook, or because we asked - the three read methods at the
 * bottom of this interface. Both end at the same reconciler, because a webhook
 * that never arrives is indistinguishable from nothing having happened.
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
     * $feedback and $comment are the customer's own answer to "why", passed
     * through so their record and ours agree. Both optional and always
     * optional: the exit is never blocked, and that includes not making
     * somebody answer a question in order to leave.
     *
     * @throws \App\Exceptions\Domain\CancellationFailed
     */
    public function cancelSubscription(
        Subscription $subscription,
        bool $atPeriodEnd = false,
        ?CancellationFeedback $feedback = null,
        ?string $comment = null,
    ): void;

    /**
     * Undo a cancellation scheduled with $atPeriodEnd, so the subscription
     * renews as normal. Only meaningful before the period ends; once Dodo has
     * cancelled it there is nothing left to resume and a new checkout is needed.
     *
     * @throws \App\Exceptions\Domain\ResumeFailed
     */
    public function resumeSubscription(Subscription $subscription): void;

    /**
     * What a plan change would cost, before it is made.
     *
     * Section 4 prices a switch as "the price difference is prorated", and
     * Dodo does that arithmetic - so they are the only ones who can answer
     * what it comes to. We charged it without ever showing it, which is the
     * most reliable way to turn an upgrade into a "why was I charged this?"
     * ticket.
     *
     * Returns minor units, like every other amount in this codebase.
     *
     * @param  list<array{addon_id: string, quantity: int}>  $addons
     * @return array{amount_minor: int, currency: string, tax_minor: int|null, credit_minor: int}
     *
     * @throws \App\Exceptions\Domain\PlanChangeUnavailable
     */
    public function previewPlanChange(
        Subscription $subscription,
        PlanPrice $price,
        array $addons = [],
    ): array;

    public function reportUsage(
        string $customerId,
        string $eventId,
        string $eventName,
        array $metadata,
        ?\DateTimeInterface $occurredAt = null,
    ): void;

    /**
     * Everything Dodo currently knows about one subscription, shaped like the
     * `data` block of their own webhook.
     *
     * Shaped that way on purpose. The reconciler already reads that shape and
     * has the out-of-order and idempotency rules built around it, and a second
     * shape would mean a second set of those rules to keep in step. Translating
     * here is right because this class is already the only thing that knows
     * Dodo's vocabulary.
     *
     * @return array<string, mixed>
     *
     * @throws \App\Exceptions\Domain\ProviderLookupFailed
     */
    public function retrieveSubscription(string $providerSubscriptionId): array;

    /**
     * The ids of every payment Dodo has SETTLED against one subscription.
     *
     * Ids only, because the caller already holds the ones it has recorded and
     * the point is to find the ones it has not. Fetching the detail of a
     * payment we already have an invoice summary for would be a request per
     * renewal, forever.
     *
     * @return list<string>
     *
     * @throws \App\Exceptions\Domain\ProviderLookupFailed
     */
    public function listSucceededPaymentIds(string $providerSubscriptionId): array;

    /**
     * One payment in full, shaped like the `data` block of their
     * `payment.succeeded` webhook.
     *
     * The full record rather than the list entry because this becomes an
     * invoice summary, and their list omits tax and the settlement amount -
     * numbers section 8 makes theirs to state and ours only to copy.
     *
     * @return array<string, mixed>
     *
     * @throws \App\Exceptions\Domain\ProviderLookupFailed
     */
    public function retrievePayment(string $paymentId): array;
}
