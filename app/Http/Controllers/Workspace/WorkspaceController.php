<?php

namespace App\Http\Controllers\Workspace;

use App\Contract\Workspace\WorkspaceContract;
use App\Http\Controllers\Controller;
use App\Http\Requests\Workspace\TransferOwnershipRequest;
use App\Http\Requests\Workspace\WorkspaceRequest;
use App\Models\Workspace;
use App\Models\WorkspaceMember;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Thin by design: every rule lives in the Service layer, and every domain
 * failure is thrown and rendered centrally. This class only resolves input,
 * asks the policy, and delegates.
 */
class WorkspaceController extends Controller
{
    public function __construct(private readonly WorkspaceContract $service) {}

    public function store(WorkspaceRequest $request): RedirectResponse
    {
        $workspace = $this->service->create($request->user(), $request->validated('name'));

        return redirect()->route('workspace.member.index', $workspace);
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

        return back();
    }
}
