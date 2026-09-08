<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\RegisterRequest;
use App\Models\Plan;
use App\Models\User;
use App\Models\WorkspaceInvitation;
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
 * Path A ("Start free") ends here on the free tier with no card. Path B
 * ("Start trial") carries a chosen plan through and continues to the card form;
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

    /** Path C's token, carried across the signup the same way the plan is. */
    public const PENDING_INVITATION = 'pending_invitation_token';

    /** Section 11's "Start trial" button arrives here as ?plan=pro. */
    public function create(Request $request): Response
    {
        $plan = $this->chosenPlan($request->query('plan'));

        // Held in the SESSION, not a hidden form field. The plan has to survive
        // the redirect to email verification and the separate workspace-naming
        // step, and a form field would be gone after the first of those - as
        // well as being something the visitor could edit.
        $request->session()->put(self::PENDING_PLAN, $plan?->code);

        $invitation = $this->pendingInvitation($request->query('invitation'));
        $request->session()->put(self::PENDING_INVITATION, $invitation === null ? null : $request->query('invitation'));

        return Inertia::render('auth/register', [
            'plan' => $plan === null ? null : [
                'code' => $plan->code,
                'name' => $plan->name,
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
     * Only a plan somebody could actually buy. An unknown, retired, hidden or
     * free code is dropped rather than refused: the visitor followed a link
     * from another project, and the worst outcome should be an ordinary signup,
     * not an error page.
     */
    private function chosenPlan(?string $code): ?Plan
    {
        if ($code === null || $code === '') {
            return null;
        }

        return Plan::query()->public()->where('is_free', false)->where('code', $code)->first();
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
