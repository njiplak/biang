<?php

namespace App\Http\Controllers\Workspace;

use App\Contract\Admin\AuditContract;
use App\Contract\Workspace\WorkspaceContract;
use App\Exceptions\Domain\WorkspaceLimitReached;
use App\Http\Controllers\Controller;
use App\Http\Requests\Workspace\TransferOwnershipRequest;
use App\Http\Requests\Workspace\WorkspaceRequest;
use App\Models\Workspace;
use App\Models\WorkspaceMember;
use App\Support\PendingPlanCheckout;
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
        // Section 10's permanent record, for the customer actions support is
        // most often asked about. See MemberController for the reasoning.
        private readonly AuditContract $audit,
    ) {}

    public function store(WorkspaceRequest $request, PendingPlanCheckout $checkout): SymfonyResponse
    {
        // One workspace per customer for now; joining other people's is unaffected.
        if (! $request->user()->canCreateWorkspace()) {
            throw new WorkspaceLimitReached($request->user());
        }

        $workspace = $this->service->create($request->user(), $request->validated('name'));

        return $checkout->start($request, $workspace, $request->user())
            ?? redirect()->route('dashboard');
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
            // So the close warning can say how long it stays restorable.
            'retention_days' => (int) config('workspace.retention_days'),
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

    /**
     * Section 6: undo a close inside the retention window. The subscription
     * ended when it closed, so it comes back read-only and the owner lands on
     * billing, where writing is turned back on.
     */
    public function restore(Workspace $workspace): RedirectResponse
    {
        Gate::authorize('restore', $workspace);

        $workspace = $this->service->reopenWorkspace($workspace);

        $this->audit->record('workspace.restored', $workspace, $workspace);

        $user = request()->user();
        $user->update(['current_workspace_id' => $workspace->id]);
        request()->session()->put('current_workspace_id', $workspace->id);

        return redirect()->route('billing.index');
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
