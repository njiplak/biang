<?php

namespace App\Http\Controllers\Workspace;

use App\Contract\Workspace\InvitationContract;
use App\Enums\WorkspaceRole;
use App\Http\Controllers\Controller;
use App\Http\Requests\Workspace\InvitationRequest;
use App\Models\Workspace;
use App\Models\WorkspaceInvitation;
use App\Notifications\WorkspaceInvitationNotification;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Notification;

class InvitationController extends Controller
{
    public function __construct(private readonly InvitationContract $service) {}

    /**
     * SeatLimitReached is thrown from the service and rendered centrally, so
     * section 7's "sales moment" reaches the user as a message naming the fix
     * rather than a failed request.
     */
    public function store(InvitationRequest $request, Workspace $workspace): RedirectResponse
    {
        Gate::authorize('inviteMembers', $workspace);

        $email = $request->validated('email');
        $role = WorkspaceRole::from($request->validated('role'));

        // Section 7: taking the offer buys the seat and sends the invite as one
        // action; declining leaves SeatLimitReached to block it.
        $invitation = $request->boolean('add_seat')
            ? $this->service->inviteWithAddedSeat($workspace, $email, $role, $request->user())
            : $this->service->invite($workspace, $email, $role, $request->user());

        $this->deliver($invitation, $request->user()->name);

        return back();
    }

    public function resend(WorkspaceInvitation $invitation): RedirectResponse
    {
        Gate::authorize('inviteMembers', $invitation->workspace);

        $resent = $this->service->resend($invitation);

        $this->deliver($resent, request()->user()->name);

        return back();
    }

    /**
     * Sent OUTSIDE the service transaction on purpose. Mail cannot be rolled
     * back, so a failure after this point must never be able to unsend it -
     * by the time we get here the invitation is durably committed.
     */
    private function deliver(WorkspaceInvitation $invitation, string $invitedBy): void
    {
        if ($invitation->plainToken === null) {
            return;
        }

        Notification::route('mail', $invitation->email)->notify(
            new WorkspaceInvitationNotification($invitation, $invitation->plainToken, $invitedBy)
        );
    }

    public function destroy(WorkspaceInvitation $invitation): RedirectResponse
    {
        Gate::authorize('inviteMembers', $invitation->workspace);

        $this->service->revoke($invitation, request()->user());

        return back();
    }
}
