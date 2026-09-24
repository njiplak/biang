<?php

namespace App\Http\Controllers\Billing;

use App\Contract\Billing\EntitlementContract;
use App\Contract\Billing\PaymentGatewayContract;
use App\Contract\Billing\SubscriptionContract;
use App\Contract\Billing\SubscriptionPullerContract;
use App\Contract\Billing\UsageContract;
use App\Enums\CancellationFeedback;
use App\Enums\PullOutcome;
use App\Exceptions\Domain\ProviderLookupFailed;
use App\Exceptions\Domain\TrialAlreadyConsumed;
use App\Http\Controllers\Controller;
use App\Http\Requests\Billing\AddonPurchaseRequest;
use App\Http\Requests\Billing\AddonQuantityRequest;
use App\Http\Requests\Billing\CancelSubscriptionRequest;
use App\Http\Requests\Billing\PlanPriceRequest;
use App\Models\Addon;
use App\Models\AddonPrice;
use App\Models\InvoiceSummary;
use App\Models\Plan;
use App\Models\PlanPrice;
use App\Models\Workspace;
use App\Models\WorkspaceEntitlement;
use App\Service\Billing\SubscriptionService;
use App\Support\CurrentWorkspace;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Section 5: "Billing lives on one page inside the app: current plan, usage
 * against every limit, and buttons to change plan, buy add-ons, or open the
 * payment provider's page for cards and invoices."
 *
 * Section 7's note is why plan changes come through here at all: the provider's
 * own plan switcher is turned off, because a downgrade made outside our app
 * could not be blocked.
 */
class BillingController extends Controller
{
    /**
     * Enough for a customer who reloads the page a few times while their
     * payment settles, and far short of what a refresh loop would need to turn
     * this page into a denial-of-service on our own provider quota.
     */
    private const PULL_ATTEMPTS = 10;

    private const PULL_DECAY_SECONDS = 60;

    public function __construct(
        private readonly SubscriptionContract $subscriptions,
        private readonly EntitlementContract $entitlements,
        private readonly UsageContract $usage,
        private readonly PaymentGatewayContract $gateway,
        private readonly SubscriptionPullerContract $puller,
    ) {}

    public function index(): Response|RedirectResponse
    {
        $workspace = $this->workspace();

        /*
         * Dodo sends the customer back here as
         * `?status=active&subscription_id=sub_...` once the card is entered.
         *
         * Section 8 keeps their records authoritative for money, so the only
         * honest way to answer "did that work?" is to ask them - the webhook
         * that would otherwise tell us may be seconds away, may have been
         * rejected at the door, or may never come. Until this existed the
         * customer landed on a page that said Free.
         */
        $returning = request()->input('subscription_id');

        // Only a genuine string is an id. `?subscription_id[]=a` arrives as an
        // array, and stringifying one gives the literal "Array" - a warning in
        // the log and a lookup at Dodo for a subscription nobody has.
        if (is_string($returning) && $returning !== '') {
            return $this->settleReturn($workspace, $returning);
        }

        return Inertia::render('billing/index', [
            'workspace' => [
                'name' => $workspace->name,
                'state' => $workspace->displayState()->value,
                'state_label' => $workspace->displayState()->label(),
                'over_limit_features' => $workspace->over_limit_features,
                // Section 5's link out to Dodo, but only once there is an
                // account there to open. A workspace that has never bought
                // never reaches them, so the button would lead nowhere.
                'has_payment_account' => filled($workspace->dodo_customer_id),
            ],
            'subscription' => $this->subscriptionPayload($workspace),
            'invoices' => $this->invoicePayload($workspace),
            'usage' => $this->usagePayload($workspace),
            'plans' => $this->planPayload($workspace),
            /*
             * Section 12: "Trial eligibility: one per person, ever." The person,
             * not the workspace - so this is asked of whoever is looking at the
             * page. Sent because the alternative is offering a trial button that
             * can only ever answer TrialAlreadyConsumed; a returning customer
             * should be shown the way to buy instead.
             */
            'can_start_trial' => ! request()->user()->hasConsumedTrial(),
            // Section 11: carried here when somebody already signed in clicks
            // "Start trial" on the marketing site. Preselects rather than
            // acting, because starting a trial is their decision to confirm.
            'preselected_plan' => request()->string('plan')->toString() ?: null,
            // Section 4: only what people actually pay extra for is an add-on,
            // and which ones are on offer depends on the current plan.
            'addons' => $this->addonPayload($workspace),
        ]);
    }

    /**
     * Settle a customer who has just come back from the card form, then send
     * them to the clean page.
     *
     * The redirect is what consumes the parameter: without it a reload would
     * ask Dodo again, and the browser back button would ask a third time.
     *
     * Nothing in the URL is trusted. `status` is ignored outright - anyone can
     * type it - and the id is only ever used to ask a question, with the answer
     * checked against this workspace before it moves anything.
     */
    private function settleReturn(Workspace $workspace, string $providerSubscriptionId): RedirectResponse
    {
        RateLimiter::attempt(
            "billing-pull:{$workspace->id}",
            self::PULL_ATTEMPTS,
            function () use ($workspace, $providerSubscriptionId) {
                try {
                    $outcome = $this->puller->pull($providerSubscriptionId, $workspace);
                } catch (ProviderLookupFailed $e) {
                    // This page renders from OUR records and has to keep
                    // working when Dodo does not, so an unreachable provider is
                    // a note on a working page, never an error page.
                    session()->flash('warning', $e->userMessage());

                    return;
                }

                if ($outcome === PullOutcome::Applied || $outcome === PullOutcome::InSync) {
                    return;
                }

                /*
                 * Deliberately the same message for NotFound and Mismatched.
                 * The difference between "we have nothing to attach this to"
                 * and "this is somebody else's subscription" is not the
                 * customer's to learn from a page they can put any id into.
                 */
                session()->flash('warning', 'We could not match that payment to this workspace yet. If you were charged, this page will catch up shortly.');
            },
            self::PULL_DECAY_SECONDS,
        );

        // A plan carried from the marketing site is the only other parameter
        // this page reads, and it must survive being sent round again.
        $plan = request()->input('plan');

        return redirect()->route('billing.index', is_string($plan) && $plan !== '' ? ['plan' => $plan] : []);
    }

    /**
     * Section 4: "A card is required to start. We collect it up front through
     * Dodo." So this does not create a trial - it sends the customer to the
     * same checkout as a purchase, with the first fourteen days free.
     *
     * That is the whole basis of the auto-charge on day 15. A trial with no
     * card cannot charge, so it either gives the product away or ends in a
     * demand for payment nobody agreed to; both are worse than asking now.
     *
     * Eligibility is checked HERE rather than left to the webhook, so someone
     * who has already had their one trial is told before they enter a card.
     */
    public function startTrial(PlanPriceRequest $request): SymfonyResponse
    {
        $workspace = $this->workspace();
        $buyer = $request->user();

        if ($buyer->hasConsumedTrial()) {
            throw new TrialAlreadyConsumed($buyer);
        }

        $price = PlanPrice::findOrFail($request->validated('plan_price_id'));

        // Before the card form, not after the charge. DowngradeBlocked names
        // exactly how many people have to go.
        $this->subscriptions->assertPlanFits($workspace, $price);

        $url = $this->gateway->createCheckout(
            $workspace,
            $price,
            $buyer,
            route('billing.index'),
            route('billing.index'),
            SubscriptionService::TRIAL_DAYS,
        );

        return Inertia::location($url);
    }

    public function changePlan(PlanPriceRequest $request): RedirectResponse
    {
        $workspace = $this->workspace();

        // DowngradeBlocked is thrown from the service and rendered centrally,
        // carrying the exact number of people to remove.
        $this->subscriptions->changePlan(
            $workspace,
            PlanPrice::findOrFail($request->validated('plan_price_id')),
        );

        return back();
    }

    public function purchaseAddon(AddonPurchaseRequest $request): RedirectResponse
    {
        $this->subscriptions->purchaseAddon(
            $this->workspace(),
            AddonPrice::findOrFail($request->validated('addon_price_id')),
            (int) ($request->validated('quantity') ?? 1),
        );

        return back();
    }

    /**
     * DowngradeBlocked is thrown from the service and rendered centrally, so a
     * reduction that would strand people in use says exactly how many to remove.
     */
    public function changeAddonQuantity(AddonQuantityRequest $request): RedirectResponse
    {
        $this->subscriptions->changeAddonQuantity(
            $this->workspace(),
            Addon::findOrFail($request->validated('addon_id')),
            (int) $request->validated('quantity'),
        );

        return back();
    }

    /**
     * Section 8: Dodo is merchant of record, so the card form is theirs. We
     * change nothing here - the subscription becomes real when their webhook
     * arrives, which is the same path a cancellation on their own page takes.
     *
     * CheckoutUnavailable is a DomainException and renders as a message, not an
     * error page: section 14 phase 3 keeps the product sellable by hand, so a
     * provider that is not wired up yet is a normal state.
     */
    public function checkout(PlanPriceRequest $request): SymfonyResponse
    {
        $workspace = $this->workspace();
        $price = PlanPrice::findOrFail($request->validated('plan_price_id'));

        /*
         * Section 7's seat check, before anybody is charged.
         *
         * changePlan has always done this; buying did not, so a read-only
         * workspace with 25 people could pick a 5-seat plan, pay, and land
         * straight in the hard block. Dodo is merchant of record, which makes
         * that a refund request rather than something we can undo.
         */
        $this->subscriptions->assertPlanFits($workspace, $price);

        $url = $this->gateway->createCheckout(
            $workspace,
            $price,
            $request->user(),
            route('billing.index'),
            route('billing.index'),
        );

        // Inertia cannot follow a redirect to another origin on its own.
        return Inertia::location($url);
    }

    /**
     * Section 5: "open the payment provider's page for cards and invoices."
     *
     * Same shape as checkout() and for the same reason - the destination is
     * Dodo's, and PortalUnavailable renders as a message rather than an error
     * page, because a workspace with no payment account is an ordinary state
     * (one that has never bought, and every workspace granted a plan by hand).
     */
    public function portal(): SymfonyResponse
    {
        $url = $this->gateway->customerPortalUrl($this->workspace(), route('billing.index'));

        return Inertia::location($url);
    }

    /**
     * Section 15 wants voluntary churn split by reason, so the answer rides
     * along - optional, always. `routes/web/billing.php` commits to never
     * blocking the exit, and a required question is a block.
     *
     * Scheduled for the end of the paid period, so leaving never forfeits time
     * the customer has already paid for.
     */
    public function cancel(CancelSubscriptionRequest $request): RedirectResponse
    {
        $feedback = $request->validated('feedback');

        $this->subscriptions->cancelAtPeriodEnd(
            $this->workspace(),
            $feedback === null ? null : CancellationFeedback::from($feedback),
            $request->validated('comment'),
        );

        return back();
    }

    /** Take back a scheduled cancellation while the paid period is still running. */
    public function resume(): RedirectResponse
    {
        $this->subscriptions->resume($this->workspace());

        return back();
    }

    /**
     * What a plan change would cost, before it is made.
     *
     * Section 4 prorates a switch and section 8 makes that arithmetic Dodo's,
     * so they are the only ones who can answer. We charged it without ever
     * showing it, which is the most reliable way to turn an upgrade into a
     * "why was I charged this?" ticket.
     *
     * A GET returning JSON rather than an Inertia page: it answers a question
     * the customer asked by hovering over a button, and must not replace what
     * they are looking at.
     */
    public function previewPlan(PlanPriceRequest $request): JsonResponse
    {
        $workspace = $this->workspace();
        $subscription = $workspace->subscription()->first();

        if ($subscription === null || ! $subscription->isHeldWithProvider()) {
            // Nothing to prorate against: a first purchase is priced by the
            // checkout itself, and a comped plan has no money behind it.
            return response()->json(['preview' => null]);
        }

        $price = PlanPrice::findOrFail($request->validated('plan_price_id'));

        // The same refusal the change itself would make, so the dialog never
        // quotes a price for a move that will be blocked.
        $this->subscriptions->assertPlanFits($workspace, $price);

        return response()->json([
            'preview' => $this->gateway->previewPlanChange($subscription, $price),
        ]);
    }

    /** Every action on this page is a billing action, so the check is uniform. */
    private function workspace(): Workspace
    {
        $workspace = app(CurrentWorkspace::class)->get();

        if ($workspace === null) {
            throw new NotFoundHttpException('No workspace selected.');
        }

        Gate::authorize('manageBilling', $workspace);

        return $workspace;
    }

    private function subscriptionPayload(Workspace $workspace): ?array
    {
        $subscription = $workspace->subscription()->with('plan', 'planPrice')->first();

        if ($subscription === null) {
            return null;
        }

        return [
            'plan' => $subscription->plan->name,
            'status' => $subscription->status->value,
            'billing_source' => $subscription->billing_source->value,
            'trial_ends_at' => $subscription->trial_ends_at,
            'current_period_end' => $subscription->current_period_end,
            // The renewal is cancelled and access ends at current_period_end
            // (or trial_ends_at while trialing) unless they resume first.
            'cancel_at_period_end' => $subscription->cancel_at_period_end,
            // When pressing cancel today would end access; null means at once.
            'paid_through' => $this->subscriptions->paidThrough($subscription),
            'amount_minor' => $subscription->planPrice->amount_minor,
            'currency' => $subscription->planPrice->currency,
            'interval' => $subscription->planPrice->billing_interval->value,
        ];
    }

    /**
     * Section 8: "We keep a summary; the document itself stays with the
     * provider." The summary is what this shows - every figure copied from
     * Dodo, never computed here, because they are merchant of record.
     *
     * Bounded to the most recent year rather than every invoice ever: this is
     * the "what was I charged" answer, and the full history lives on their
     * portal, which the button above already opens.
     */
    private function invoicePayload(Workspace $workspace): array
    {
        return InvoiceSummary::withoutWorkspaceScope()
            ->where('workspace_id', $workspace->id)
            ->orderByDesc('issued_at')
            ->limit(12)
            ->get()
            ->map(fn (InvoiceSummary $invoice) => [
                'id' => $invoice->id,
                'number' => $invoice->number,
                'status' => $invoice->status,
                'currency' => $invoice->currency,
                'total_minor' => $invoice->total_minor,
                'tax_minor' => $invoice->tax_minor,
                'issued_at' => $invoice->issued_at,
                // Their document, not ours - null until they give us a link.
                'hosted_url' => $invoice->hosted_url,
            ])
            ->all();
    }

    /** Section 5: usage against EVERY limit, not just the one they breached. */
    private function usagePayload(Workspace $workspace): array
    {
        return WorkspaceEntitlement::withoutWorkspaceScope()
            ->where('workspace_id', $workspace->id)
            ->get()
            ->map(fn (WorkspaceEntitlement $entitlement) => [
                'feature' => $entitlement->feature_key,
                'used' => $this->usage->current($workspace, $entitlement->feature_key),
                'limit' => $entitlement->value,
                'source' => $entitlement->source->value,
            ])
            ->values()
            ->all();
    }

    /** @return array<string, mixed> */
    private function addonPayload(Workspace $workspace): array
    {
        $subscription = $workspace->subscription()->with('plan.addons.prices', 'items.addon')->first();

        if ($subscription === null) {
            // Nothing to attach a paid add-on to without a subscription.
            return ['available' => [], 'owned' => []];
        }

        $owned = $subscription->items->map(fn ($item) => [
            'addon_id' => $item->addon->id,
            'key' => $item->addon->key,
            'name' => $item->addon->name,
            'kind' => $item->addon->kind->value,
            'quantity' => $item->quantity,
        ])->values()->all();

        $ownedIds = array_column($owned, 'addon_id');

        $available = $subscription->plan->addons
            ->filter(fn (Addon $addon) => $addon->archived_at === null && ! in_array($addon->id, $ownedIds, true))
            ->map(function (Addon $addon) {
                $price = $addon->prices->firstWhere('archived_at', null);

                return $price === null ? null : [
                    'addon_id' => $addon->id,
                    'price_id' => $price->id,
                    'key' => $addon->key,
                    'name' => $addon->name,
                    'kind' => $addon->kind->value,
                    'amount_minor' => $price->amount_minor,
                    'currency' => $price->currency,
                    'interval' => $price->billing_interval->value,
                ];
            })
            ->filter()
            ->values()
            ->all();

        return ['available' => $available, 'owned' => $owned];
    }

    /**
     * Section 7 again: a plan that cannot hold the people already here is not
     * an offer, and the page has to say so before it is clicked rather than
     * after the card is charged.
     */
    private function planPayload(Workspace $workspace): array
    {
        $currentPlanId = $workspace->subscription()->value('plan_id');

        return Plan::query()->public()->with('prices', 'features')->orderBy('sort_order')->get()
            ->map(function (Plan $plan) use ($workspace, $currentPlanId) {
                $prices = $plan->prices->whereNull('archived_at')->values();

                // Seats are a PLAN allowance, so any of its prices answers it.
                $overage = $prices->isEmpty()
                    ? 0
                    : $this->subscriptions->seatOverageFor($workspace, $prices->first());

                return [
                    'code' => $plan->code,
                    'name' => $plan->name,
                    'is_current' => $currentPlanId !== null && $plan->id === $currentPlanId,
                    // How many people have to go before this plan is buyable.
                    // Zero means it fits.
                    'seat_overage' => $overage,
                    'prices' => $prices->map(fn (PlanPrice $price) => [
                        'id' => $price->id,
                        'interval' => $price->billing_interval->value,
                        'currency' => $price->currency,
                        'amount_minor' => $price->amount_minor,
                    ])->all(),
                ];
            })
            ->all();
    }
}
