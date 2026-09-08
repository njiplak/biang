<?php

namespace App\Http\Controllers\Workspace;

use App\Contract\Admin\AuditContract;
use App\Contract\Billing\PaymentGatewayContract;
use App\Contract\Workspace\WorkspaceContract;
use App\Enums\BillingInterval;
use App\Exceptions\Domain\DomainException;
use App\Exceptions\Domain\TrialAlreadyConsumed;
use App\Http\Controllers\Auth\RegisterController;
use App\Http\Controllers\Controller;
use App\Http\Requests\Workspace\TransferOwnershipRequest;
use App\Http\Requests\Workspace\WorkspaceRequest;
use App\Models\Plan;
use App\Models\Workspace;
use App\Models\WorkspaceMember;
use App\Service\Billing\SubscriptionService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

/**
 * Thin by design: every rule lives in the Service layer, and every domain
 * failure is thrown and rendered centrally. This class only resolves input,
 * asks the policy, and delegates.
 */
class WorkspaceController extends Controller
{
    public function __construct(
        private readonly WorkspaceContract $service,
        private readonly PaymentGatewayContract $gateway,
        // Section 10's permanent record, for the customer actions support is
        // most often asked about. See MemberController for the reasoning.
        private readonly AuditContract $audit,
    ) {}

    public function store(WorkspaceRequest $request): SymfonyResponse
    {
        $workspace = $this->service->create($request->user(), $request->validated('name'));

        return $this->startPendingCheckout($request, $workspace)
            ?? redirect()->route('workspace.member.index', $workspace);
    }

    /**
     * Section 5 Path B: "name your workspace -> enter card -> 14-day trial
     * begins". This is the arrow into the card form, and the workspace has to
     * exist first because a trial belongs to one and the checkout carries its
     * ulid in metadata.
     *
     * It hands the customer to Dodo rather than opening a trial here. Section 4
     * is explicit that "a card is required to start", and a trial started
     * locally has no card behind it - so it could never auto-charge on day 15,
     * and it spent the person's one-per-person trial to give the product away
     * for fourteen days.
     *
     * Failure must never take the workspace with it. They signed up and named
     * it; the worst case is that they land on a read-only workspace and
     * subscribe from the billing page themselves.
     *
     * @return SymfonyResponse|null null when there is nothing to buy, in which
     *                              case the caller lands them in the app
     */
    private function startPendingCheckout(WorkspaceRequest $request, Workspace $workspace): ?SymfonyResponse
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

        $buyer = $request->user();

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

        // Inertia cannot follow a redirect to another origin on its own.
        return Inertia::location($url);
    }

    /**
     * One page for the things that change a workspace itself. What is offered
     * follows section 3's role split rather than hiding buttons in the UI only -
     * the same policies decide both.
     */
    public function settings(Workspace $workspace): Response
    {
        Gate::authorize('view', $workspace);

        return Inertia::render('workspace/settings', [
            'workspace' => $workspace->only(['ulid', 'name', 'slug']),
            'can' => [
                'rename' => Gate::allows('update', $workspace),
                /*
                 * `update` is role AND state, and the two need different
                 * answers from the customer. Role first when both apply: an
                 * ordinary member told to buy a plan is being sent to a page
                 * their role cannot open.
                 */
                'rename_blocked_by' => Gate::allows('update', $workspace)
                    ? null
                    : (request()->user('web')?->roleIn($workspace)?->canManageMembers() === true ? 'state' : 'role'),
                'transfer' => Gate::allows('transferOwnership', $workspace),
                'close' => Gate::allows('delete', $workspace),
            ],
            // candidates for ownership transfer
            'members' => $workspace->members()->with('user:id,name,email')->get()
                ->map(fn (WorkspaceMember $member) => [
                    'id' => $member->id,
                    'name' => $member->user?->name,
                    'email' => $member->user?->email,
                    'role' => $member->role->value,
                    'is_self' => $member->user_id === request()->user('web')?->id,
                ])->values(),
        ]);
    }

    /**
     * Section 6: closing does not delete anything. The workspace becomes
     * recoverable for the retention window and billing stops.
     */
    public function destroy(Workspace $workspace): RedirectResponse
    {
        Gate::authorize('delete', $workspace);

        $this->service->closeWorkspace($workspace);

        // Closing stops billing and starts the retention clock, so "who closed
        // this, and when" is the first question asked when somebody wants it back.
        $this->audit->record('workspace.closed', $workspace, $workspace);

        return redirect()->route('dashboard');
    }

    public function update(WorkspaceRequest $request, Workspace $workspace): RedirectResponse
    {
        Gate::authorize('update', $workspace);

        $workspace->update(['name' => $request->validated('name')]);

        return back();
    }

    /**
     * Section 2: the switcher is how a person moves between workspaces. The
     * policy check matters because this is the one place a user names a
     * workspace directly - ResolveWorkspace re-verifies membership afterwards
     * on every request regardless.
     */
    public function switchTo(Workspace $workspace): RedirectResponse
    {
        Gate::authorize('view', $workspace);

        $user = request()->user();
        $user->update(['current_workspace_id' => $workspace->id]);
        request()->session()->put('current_workspace_id', $workspace->id);

        return back();
    }

    public function transferOwnership(TransferOwnershipRequest $request, Workspace $workspace): RedirectResponse
    {
        Gate::authorize('transferOwnership', $workspace);

        $member = WorkspaceMember::findOrFail($request->validated('member_id'));
        $this->service->transferOwnership($workspace, $member);

        // Ownership carries billing and the right to close, so this is the
        // single most consequential thing a customer can do to a workspace.
        $this->audit->record('workspace.ownership_transferred', $workspace, $member);

        return back();
    }
}
