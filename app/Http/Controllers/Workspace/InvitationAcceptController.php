<?php

namespace App\Http\Controllers\Workspace;

use App\Contract\Workspace\InvitationContract;
use App\Http\Controllers\Auth\RegisterController;
use App\Http\Controllers\Controller;
use App\Models\WorkspaceInvitation;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Section 5 Path C: the invitee joins an EXISTING workspace. They do not create
 * one, do not start a trial, and do not enter a card.
 *
 * No policy here on purpose - holding a valid, unexpired token IS the
 * authorisation, and the service re-checks that it is still acceptable.
 */
class InvitationAcceptController extends Controller
{
    public function __construct(private readonly InvitationContract $service) {}

    /**
     * Public: the emailed link is a GET, and the invitee usually has no account
     * yet. An invalid token reveals nothing - same shape, no workspace name -
     * so this page cannot be used to probe which invitations exist.
     */
    public function show(string $token): Response
    {
        $invitation = WorkspaceInvitation::where('token_hash', hash('sha256', $token))->first();
        $valid = $invitation !== null && $invitation->isPending();

        return Inertia::render('invitation/show', [
            'token' => $token,
            'valid' => $valid,
            'workspace' => $valid ? $invitation->workspace->name : null,
            'role' => $valid ? $invitation->role->value : null,
            'authenticated' => request()->user('web') !== null,
        ]);
    }

    public function accept(string $token): RedirectResponse
    {
        $user = request()->user();

        $membership = $this->service->accept($token, $user);

        // Drop them straight into the workspace they just joined.
        $user->update(['current_workspace_id' => $membership->workspace_id]);
        request()->session()->put('current_workspace_id', $membership->workspace_id);

        // Spent: onboarding would otherwise keep sending them back to it.
        request()->session()->forget(RegisterController::PENDING_INVITATION);

        return redirect()->route('workspace.member.index', $membership->workspace);
    }
}
