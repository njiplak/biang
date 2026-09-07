<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\RegisterRequest;
use App\Models\Plan;
use App\Models\User;
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

    /** Section 11's "Start trial" button arrives here as ?plan=pro. */
    public function create(Request $request): Response
    {
        $plan = $this->chosenPlan($request->query('plan'));

        // Held in the SESSION, not a hidden form field. The plan has to survive
        // the redirect to email verification and the separate workspace-naming
        // step, and a form field would be gone after the first of those - as
        // well as being something the visitor could edit.
        $request->session()->put(self::PENDING_PLAN, $plan?->code);

        return Inertia::render('auth/register', [
            'plan' => $plan === null ? null : [
                'code' => $plan->code,
                'name' => $plan->name,
            ],
        ]);
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

    public function store(RegisterRequest $request): RedirectResponse
    {
        $user = DB::transaction(fn () => User::create($request->validated()));

        // Fires the framework's verification mail, since User is MustVerifyEmail.
        event(new Registered($user));

        Auth::guard('web')->login($user);
        $request->session()->regenerate();

        return redirect()->intended(route('dashboard', absolute: false));
    }
}
