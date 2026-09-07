<?php

namespace App\Http\Controllers\Billing;

use App\Contract\Billing\EntitlementContract;
use App\Contract\Billing\PaymentGatewayContract;
use App\Contract\Billing\SubscriptionContract;
use App\Contract\Billing\UsageContract;
use App\Exceptions\Domain\TrialAlreadyConsumed;
use App\Http\Controllers\Controller;
use App\Http\Requests\Billing\AddonPurchaseRequest;
use App\Http\Requests\Billing\AddonQuantityRequest;
use App\Http\Requests\Billing\PlanPriceRequest;
use App\Models\Addon;
use App\Models\AddonPrice;
use App\Models\Plan;
use App\Models\PlanPrice;
use App\Models\Workspace;
use App\Models\WorkspaceEntitlement;
use App\Service\Billing\SubscriptionService;
use App\Support\CurrentWorkspace;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;
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
    public function __construct(
        private readonly SubscriptionContract $subscriptions,
        private readonly EntitlementContract $entitlements,
        private readonly UsageContract $usage,
        private readonly PaymentGatewayContract $gateway,
    ) {}

    public function index(): Response
    {
        $workspace = $this->workspace();

        return Inertia::render('billing/index', [
            'workspace' => [
                'name' => $workspace->name,
                'state' => $workspace->displayState()->value,
                'state_label' => $workspace->displayState()->label(),
                'over_limit_features' => $workspace->over_limit_features,
                // Section 5's link out to Dodo, but only once there is an
                // account there to open. A free workspace never reaches them
                // at all (section 12), so the button would lead nowhere.
                'has_payment_account' => filled($workspace->dodo_customer_id),
            ],
            'subscription' => $this->subscriptionPayload($workspace),
            'usage' => $this->usagePayload($workspace),
            'plans' => $this->planPayload(),
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

        $url = $this->gateway->createCheckout(
            $workspace,
            PlanPrice::findOrFail($request->validated('plan_price_id')),
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

        $url = $this->gateway->createCheckout(
            $workspace,
            PlanPrice::findOrFail($request->validated('plan_price_id')),
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
     * (the free tier, and every workspace granted a plan by hand).
     */
    public function portal(): SymfonyResponse
    {
        $url = $this->gateway->customerPortalUrl($this->workspace(), route('billing.index'));

        return Inertia::location($url);
    }

    public function cancel(): RedirectResponse
    {
        $this->subscriptions->cancel($this->workspace());

        return back();
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
            'amount_minor' => $subscription->planPrice->amount_minor,
            'currency' => $subscription->planPrice->currency,
            'interval' => $subscription->planPrice->billing_interval->value,
        ];
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
            // Section 12: nothing to attach a paid add-on to on the free tier.
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

    private function planPayload(): array
    {
        return Plan::query()->public()->with('prices')->orderBy('sort_order')->get()
            ->map(fn (Plan $plan) => [
                'code' => $plan->code,
                'name' => $plan->name,
                'is_free' => $plan->is_free,
                'prices' => $plan->prices->whereNull('archived_at')->map(fn (PlanPrice $price) => [
                    'id' => $price->id,
                    'interval' => $price->billing_interval->value,
                    'currency' => $price->currency,
                    'amount_minor' => $price->amount_minor,
                ])->values()->all(),
            ])
            ->all();
    }
}
