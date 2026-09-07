<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\RegisterRequest;
use App\Models\User;
use Illuminate\Auth\Events\Registered;
use Illuminate\Http\RedirectResponse;
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
    public function create(): Response
    {
        return Inertia::render('auth/register');
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
