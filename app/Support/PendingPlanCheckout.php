<?php

namespace App\Support;

use App\Contract\Billing\PaymentGatewayContract;
use App\Enums\BillingInterval;
use App\Exceptions\Domain\DomainException;
use App\Exceptions\Domain\TrialAlreadyConsumed;
use App\Http\Controllers\Auth\RegisterController;
use App\Models\Plan;
use App\Models\User;
use App\Models\Workspace;
use App\Service\Billing\SubscriptionService;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

/**
 * Section 5 Path A: "name your workspace -> enter card -> 14-day trial
 * begins". The plan picked on the marketing site waits in the session until a
 * workspace exists, then this hands the customer to Dodo's card form.
 *
 * It hands them to Dodo rather than opening a trial here. Section 4 is
 * explicit that "a card is required to start", and a trial started locally has
 * no card behind it - so it could never auto-charge on day 15.
 *
 * Failure never takes the workspace with it: the worst case is that they land
 * on a read-only workspace and subscribe from the billing page themselves.
 */
class PendingPlanCheckout
{
    public function __construct(private readonly PaymentGatewayContract $gateway) {}

    /** @return Response|null null when there is nothing to buy */
    public function start(Request $request, Workspace $workspace, User $buyer): ?Response
    {
        /*
         * Pulled unconditionally, and both keys together. A choice left in the
         * session would be spent on whatever workspace this person created
         * next, which is not the one the link was clicked for.
         */
        $code = $request->session()->pull(RegisterController::PENDING_PLAN);
        $interval = BillingInterval::tryFrom(
            (string) $request->session()->pull(RegisterController::PENDING_INTERVAL)
        ) ?? BillingInterval::Month;

        if ($code === null) {
            return null;
        }

        $price = Plan::query()->public()->where('code', $code)->first()
            ?->activePriceFor($interval, config('billing.default_currency'));

        if ($price === null) {
            return null;
        }

        /*
         * Section 12: one trial per person, ever. Checked here rather than left
         * to the webhook so a returning customer is told before a card form,
         * not after entering one.
         */
        if ($buyer->hasConsumedTrial()) {
            $request->session()->flash('warning', (new TrialAlreadyConsumed($buyer))->userMessage());

            return null;
        }

        try {
            $url = $this->gateway->createCheckout(
                $workspace,
                $price,
                $buyer,
                route('billing.index'),
                route('billing.index'),
                SubscriptionService::TRIAL_DAYS,
            );
        } catch (DomainException $e) {
            // Section 14 phase 3 keeps the product sellable by hand, so a
            // provider that is not wired up yet is a normal state, not a crash.
            $request->session()->flash('warning', $e->userMessage());

            return null;
        }

        ProductEvents::record('checkout_started', $buyer, $workspace, [
            'plan' => $code,
            'interval' => $interval->value,
            'trial' => true,
        ]);

        // Inertia cannot follow a redirect to another origin on its own.
        return Inertia::location($url);
    }
}
