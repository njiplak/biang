<?php

namespace App\Http\Controllers\Auth;

use App\Contract\Auth\UserAuthContract;
use App\Http\Controllers\Controller;
use App\Http\Requests\LoginRequest;
use App\Support\PendingTwoFactor;
use App\Utils\WebResponse;
use Exception;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;

class UserAuthController extends Controller
{
    protected UserAuthContract $service;

    public function __construct(UserAuthContract $service)
    {
        $this->service = $service;
    }

    public function login()
    {
        if (Auth::guard('web')->check()) {
            return redirect(route('dashboard'));
        } else {
            return Inertia::render('auth/login');
        }
    }

    /**
     * The throttle is what this had been missing that the admin login already
     * did: without it a password can be guessed at forever.
     *
     * The session regeneration below is belt-and-braces, NOT the thing that
     * stops session fixation - SessionGuard::updateSession() already calls
     * session->regenerate(true) on every successful login. It is here because
     * this service has hand-rolled credential checks in its history, and the
     * guard rail should outlive a refactor that stops going through the guard.
     */
    public function attempt(LoginRequest $request)
    {
        $request->ensureIsNotRateLimited();

        $payload = $request->validated();
        $result = $this->service->login($payload);

        if ($result instanceof Exception) {
            $request->hitRateLimiter();

            // `email`, matching the throttle message thrown above, so both
            // failures land in the one place the form renders them.
            return back()->withErrors(['email' => $result->getMessage()]);
        }

        $request->clearRateLimiter();

        $remember = (bool) ($payload['remember'] ?? false);

        /*
         * A correct password is not a session when a second factor is enrolled.
         * Nothing is signed in here - only a note that the password step passed
         * - so an attacker with the password holds nothing until they also
         * produce a code.
         */
        if ($result->hasTwoFactorEnabled()) {
            PendingTwoFactor::remember($request, 'web', $result->getKey(), $remember);

            return redirect()->route('two-factor.challenge');
        }

        $this->service->completeLogin($result, $remember);
        $request->session()->regenerate();

        return WebResponse::response($result, 'dashboard');
    }

    public function logout()
    {
        $result = $this->service->logout();

        // `login`, not `auth.login`. The route sits under the `auth` URL prefix
        // but carries no name prefix, and the wrong name here threw rather than
        // redirecting - invisible for as long as the route was unreachable.
        return WebResponse::response($result, 'login');
    }
}
