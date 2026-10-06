<?php

namespace App\Http\Controllers;

use App\Contract\Billing\UsageContract;
use App\Enums\BillingInterval;
use App\Enums\WorkspaceRole;
use App\Http\Controllers\Auth\RegisterController;
use App\Http\Controllers\Workspace\OnboardingController;
use App\Models\Plan;
use App\Models\Workspace;
use App\Service\Billing\SubscriptionService;
use App\Support\CurrentWorkspace;
use App\Support\Features;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Where a signed-in customer lands.
 *
 * Section 2: one person can belong to many workspaces with a different job in
 * each, so this is the switcher's home - it reads ACROSS workspaces, which is
 * why workspace_members is deliberately never tenant-scoped.
 */
class DashboardController extends Controller
{
    public function index(Request $request): Response|RedirectResponse
    {
        // The web guard by name throughout, as in ResolveWorkspace: while staff
        // impersonate both guards are signed in, and this page must not depend
        // on which one is the default - an AdminUser has no workspaces.
        $closed = $this->closedWorkspaces($request);

        /*
         * One customer, one workspace, made for them on the way in. Not when
         * they have a closed one to restore - that is offered below instead -
         * and not straight after onboarding failed, or the two would bounce
         * the request between them.
         */
        if ($request->user('web')->workspaces()->doesntExist()
            && $closed === []
            && ! $request->session()->get(OnboardingController::FAILED)) {
            return redirect()->route('onboarding');
        }

        // The workspace list is already shared as `tenancy` on every page for
        // the switcher; shipping a second copy here was duplicate state, and
        // naming it `workspaces` silently overrode the shared one.
        //
        // No verification prop any more: the route is behind `verified`, so
        // nobody who needs telling can get here. A banner offering to resend a
        // link, on a page an unverified account cannot open, was UI for a state
        // that no longer exists.
        return Inertia::render('dashboard', [
            'pending_plan' => $this->pendingPlan($request),
            'closed_workspaces' => $closed,
            'home' => $this->home(),
        ]);
    }

    /**
     * The current workspace at a glance: its plan, and how far along setting
     * it up the customer is. Null when they are not in one.
     *
     * @return array<string, mixed>|null
     */
    private function home(): ?array
    {
        $workspace = app(CurrentWorkspace::class)->get();

        if ($workspace === null) {
            return null;
        }

        return [
            'plan' => $workspace->subscription()->with('plan')->first()?->plan?->name,
            'members' => $workspace->members()->count(),
            'projects' => app(UsageContract::class)->current($workspace, Features::PROJECTS),
        ];
    }

    /**
     * Section 6 promises a closed workspace is recoverable until its purge
     * date, and this is where that promise can be kept: the switcher no longer
     * lists it, so without this nobody could find it to bring it back.
     *
     * Owners only, matching WorkspacePolicy::restore - listing it to someone
     * who cannot restore it would be a button that only ever says no.
     *
     * @return list<array<string, mixed>>
     */
    private function closedWorkspaces(Request $request): array
    {
        $owned = $request->user('web')->memberships()
            ->where('role', WorkspaceRole::Owner)
            ->pluck('workspace_id');

        return Workspace::onlyTrashed()
            ->whereIn('id', $owned)
            ->whereNull('anonymized_at')
            ->where('purge_after', '>', now())
            ->orderBy('purge_after')
            ->get(['id', 'ulid', 'name', 'purge_after'])
            ->map(fn (Workspace $workspace) => [
                'ulid' => $workspace->ulid,
                'name' => $workspace->name,
                'restorable_until' => $workspace->purge_after,
            ])
            ->values()
            ->all();
    }

    /**
     * The plan chosen on the marketing site, still waiting to be acted on.
     *
     * Section 11 carries a plan through signup and section 5 Path A ends at
     * the card form - but the step between them is "name your workspace", and
     * until now nothing said so. Someone who clicked Pro, signed up and
     * verified landed on a page that said only "You are not in a workspace
     * yet", with no sign the plan they picked was still waiting. Section 15
     * calls trial-to-paid "the number this whole build exists to move", and
     * that silence sits directly on it.
     *
     * Read, never pulled: PendingPlanCheckout::start consumes
     * these keys when the workspace is actually created, and consuming them
     * here would spend the choice on rendering a page.
     *
     * @return array<string, mixed>|null
     */
    private function pendingPlan(Request $request): ?array
    {
        if (! $request->hasSession()) {
            return null;
        }

        $code = $request->session()->get(RegisterController::PENDING_PLAN);

        if (! is_string($code) || $code === '') {
            return null;
        }

        // The same narrowing the signup link gets: a retired or hidden plan is
        // not something we should still be promising.
        $plan = Plan::query()->public()->where('is_free', false)
            ->where('code', $code)->first();

        if ($plan === null) {
            return null;
        }

        /*
         * The SAME price PendingPlanCheckout will check out, resolved the same
         * way - plan plus the interval carried beside it. Reading the plan
         * alone quoted a monthly trial to somebody who picked annual.
         */
        $interval = BillingInterval::tryFrom(
            (string) $request->session()->get(RegisterController::PENDING_INTERVAL)
        ) ?? BillingInterval::Month;

        $currency = config('billing.default_currency');

        $price = $plan->activePriceFor($interval, $currency)
            ?? $plan->activePriceFor(BillingInterval::Month, $currency);

        return [
            'name' => $plan->name,
            /*
             * Section 12 sells one trial per PERSON, ever. Promising a trial to
             * somebody who has already spent theirs would be a second lie on
             * the same screen - they can still buy, so the prompt stays, but it
             * stops naming a trial.
             *
             * Null too when the price itself carries no trial, which annual
             * does not: the prompt then offers the plan without promising free
             * days that the card form is not going to give.
             */
            'trial_days' => $request->user('web')->hasConsumedTrial() || $price === null
                ? null
                : SubscriptionService::trialDaysFor($price),
        ];
    }
}
