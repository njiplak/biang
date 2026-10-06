<?php

namespace App\Http\Controllers\Workspace;

use App\Contract\Workspace\WorkspaceContract;
use App\Exceptions\Domain\DomainException;
use App\Http\Controllers\Auth\RegisterController;
use App\Http\Controllers\Controller;
use App\Models\WorkspaceInvitation;
use App\Support\PendingPlanCheckout;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * One customer, one workspace: it is created for them on the way in, rather
 * than being a separate "name your workspace" screen they have to find.
 *
 * Reached from the dashboard whenever a verified person belongs to no
 * workspace. Path C invitees are sent to their invitation instead, so joining
 * someone else's workspace never leaves a stray empty one behind.
 */
class OnboardingController extends Controller
{
    /** Set when creation failed, so the dashboard does not send them straight back here. */
    public const FAILED = 'onboarding_failed';

    public function __invoke(Request $request, WorkspaceContract $workspaces, PendingPlanCheckout $checkout): Response
    {
        $user = $request->user();

        // workspaces(), not memberships(): a closed workspace keeps its
        // membership rows until it is purged, but it is not somewhere to work.
        if ($user->workspaces()->exists()) {
            return redirect()->route('dashboard');
        }

        $token = $request->session()->get(RegisterController::PENDING_INVITATION);

        if (is_string($token) && $this->invitationIsPending($token)) {
            return redirect()->route('invitation.show', $token);
        }

        // A closed workspace they could restore counts against the limit; the
        // dashboard offers the restore instead of making a second one.
        if (! $user->canCreateWorkspace()) {
            return $this->failed($request, null);
        }

        $name = trim((string) $request->session()->pull(RegisterController::PENDING_WORKSPACE_NAME));

        try {
            $workspace = $workspaces->create($user, $name !== '' ? $name : $this->defaultName($user->name));
        } catch (DomainException $e) {
            // e.g. NoFloorPlanConfigured on an install whose catalogue is not seeded yet.
            report($e);

            return $this->failed($request, $e->userMessage());
        }

        return $checkout->start($request, $workspace, $user)
            ?? redirect()->route('dashboard');
    }

    private function invitationIsPending(string $token): bool
    {
        return WorkspaceInvitation::where('token_hash', hash('sha256', $token))->first()?->isPending() === true;
    }

    private function defaultName(string $personName): string
    {
        $first = Str::before(trim($personName), ' ');

        return ($first !== '' ? $first : 'My')."'s workspace";
    }

    private function failed(Request $request, ?string $message): Response
    {
        $request->session()->flash(self::FAILED, true);

        if ($message !== null) {
            $request->session()->flash('warning', $message);
        }

        return redirect()->route('dashboard');
    }
}
