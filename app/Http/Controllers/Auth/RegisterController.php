<?php

namespace App\Http\Controllers\Auth;

use App\Enums\BillingInterval;
use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\RegisterRequest;
use App\Models\Plan;
use App\Models\PlanPrice;
use App\Models\User;
use App\Models\WorkspaceInvitation;
use App\Service\Billing\SubscriptionService;
use Illuminate\Auth\Events\Registered;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Spec section 5, the way in.
 *
 * There is no free tier, so there is one way in rather than two: a plan is
 * chosen, carried through signup, and ends at the card form. A signup that
 * names no plan still creates the account - it lands on a read-only workspace
 * and picks a plan from the billing page.
 *
 * Path C never reaches this controller at all - an invitee joins an existing
 * workspace and creates nothing.
 *
 * Note what this deliberately does NOT do: it does not create a workspace.
 * Naming the workspace is its own step (section 5), and an invitee arriving via
 * Path C must not end up with a stray empty one.
 */
class RegisterController extends Controller
{
    /** Read by WorkspaceController when the workspace is finally named. */
    public const PENDING_PLAN = 'pending_plan_code';

    /**
     * The chosen billing interval, carried beside the plan.
     *
     * Separate from the plan code because they are chosen separately: section
     * 11's pricing page has a monthly/annual toggle, and carrying only the plan
     * meant somebody who picked annual was quietly given a monthly trial.
     */
    public const PENDING_INTERVAL = 'pending_plan_interval';

    /** Path C's token, carried across the signup the same way the plan is. */
    public const PENDING_INVITATION = 'pending_invitation_token';

    /** Section 11's "Start trial" button arrives here as ?plan=pro&interval=year. */
    public function create(Request $request): Response
    {
        $interval = BillingInterval::tryFrom((string) $request->query('interval'))
            ?? BillingInterval::Month;

        $price = $this->chosenPrice($request->query('plan'), $interval);

        /*
         * Held in the SESSION, not a hidden form field. The choice has to
         * survive the redirect to email verification and the separate
         * workspace-naming step, and a form field would be gone after the first
         * of those - as well as being something the visitor could edit.
         *
         * The RESOLVED interval is stored rather than the requested one, so the
         * price the card form charges is the price this page quoted even when
         * the request asked for an interval this plan does not sell.
         */
        $request->session()->put(self::PENDING_PLAN, $price?->plan->code);
        $request->session()->put(self::PENDING_INTERVAL, $price?->billing_interval->value);

        $invitation = $this->pendingInvitation($request->query('invitation'));
        $request->session()->put(self::PENDING_INVITATION, $invitation === null ? null : $request->query('invitation'));

        return Inertia::render('auth/register', [
            /*
             * Echoed back rather than only remembered. Somebody who clicked
             * "Start trial - Pro" on the marketing site was shown a form with
             * no sign their choice had survived, which is indistinguishable
             * from having lost it.
             */
            'plan' => $price === null ? null : [
                'code' => $price->plan->code,
                'name' => $price->plan->name,
                'interval' => $price->billing_interval->value,
                'currency' => $price->currency,
                'amount_minor' => $price->amount_minor,
                'trial_days' => SubscriptionService::TRIAL_DAYS,
            ],
            // Prefills the form and tells the page why it is asking.
            'invitedEmail' => $invitation?->email,
            'invitedTo' => $invitation?->workspace->name,
        ]);
    }

    /** The invitation a Path C link points at, or null if it cannot be used. */
    private function pendingInvitation(?string $token): ?WorkspaceInvitation
    {
        if ($token === null || $token === '') {
            return null;
        }

        $invitation = WorkspaceInvitation::with('workspace')
            ->where('token_hash', hash('sha256', $token))
            ->first();

        return $invitation !== null && $invitation->isPending() ? $invitation : null;
    }

    /**
     * The price a signup link actually resolves to, or null if there is nothing
     * to sell.
     *
     * Only a plan somebody could actually buy. An unknown, retired, hidden or
     * free code is dropped rather than refused: the visitor followed a link
     * from another project, and the worst outcome should be an ordinary signup,
     * not an error page. A plan that does not sell the requested interval falls
     * back to monthly for the same reason.
     */
    private function chosenPrice(?string $code, BillingInterval $interval): ?PlanPrice
    {
        if ($code === null || $code === '') {
            return null;
        }

        $plan = Plan::query()->public()->where('is_free', false)->where('code', $code)->first();

        if ($plan === null) {
            return null;
        }

        $currency = config('billing.default_currency');

        $price = $plan->activePriceFor($interval, $currency)
            ?? $plan->activePriceFor(BillingInterval::Month, $currency);

        // setRelation, not a lazy load: the plan is already in hand, and the
        // payload below reads code and name off it for every request.
        return $price?->setRelation('plan', $plan);
    }

    /**
     * Signing up no longer drops anyone on the dashboard. Verification is a step
     * in section 5's order, not a suggestion, so the only place to go from here
     * is the page that tells them to check their inbox.
     *
     * The exception is Path C, and only when the invitation was addressed to the
     * address they just signed up with: we mailed a token to that inbox and they
     * came back holding it, which is the same proof the verification mail asks
     * for. Matching the address is what makes it proof - accepting ANY invitation
     * token would let one invite verify any address the holder cared to name,
     * and a verified address is what unlocks sending mail in our name.
     */
    public function store(RegisterRequest $request): RedirectResponse
    {
        $token = $request->session()->get(self::PENDING_INVITATION);
        $invitation = $this->pendingInvitation(is_string($token) ? $token : null);

        $user = DB::transaction(function () use ($request, $invitation) {
            $user = User::create($request->validated());

            if ($invitation !== null && $invitation->email === $user->email) {
                $user->markEmailAsVerified();
            }

            return $user;
        });

        if (! $user->hasVerifiedEmail()) {
            // Fires the framework's verification mail, since User is MustVerifyEmail.
            event(new Registered($user));
        }

        Auth::guard('web')->login($user);
        $request->session()->regenerate();

        if ($user->hasVerifiedEmail() && $invitation !== null) {
            return redirect()->route('invitation.show', $token);
        }

        return redirect()->route('verification.notice');
    }
}
