<?php

namespace App\Http\Controllers\Auth;

use App\Contract\Auth\TwoFactorContract;
use App\Http\Controllers\Controller;
use App\Support\PendingTwoFactor;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The second half of a login for an account that has confirmed a second factor.
 *
 * Section 3 keeps the customer and staff worlds apart, so each has its own
 * subclass, its own guard and its own routes - but the mechanism is identical
 * and one copy of it means one place to get it wrong.
 */
abstract class TwoFactorChallengeController extends Controller
{
    public function __construct(protected readonly TwoFactorContract $twoFactor) {}

    abstract protected function guard(): string;

    abstract protected function loginRoute(): string;

    abstract protected function intendedRoute(): string;

    abstract protected function page(): string;

    abstract protected function findUser(int|string $id): ?Authenticatable;

    public function show(Request $request): Response|RedirectResponse
    {
        if (PendingTwoFactor::retrieve($request, $this->guard()) === null) {
            return redirect()->route($this->loginRoute());
        }

        return Inertia::render($this->page());
    }

    public function store(Request $request): RedirectResponse
    {
        $pending = PendingTwoFactor::retrieve($request, $this->guard());

        // Expired, or somebody arriving here without passing a password first.
        if ($pending === null) {
            return redirect()->route($this->loginRoute())
                ->withErrors(['email' => 'Your sign-in attempt expired. Please start again.']);
        }

        $request->validate(['code' => ['required', 'string']]);

        $user = $this->findUser($pending['id']);

        // Deactivated, deleted or otherwise gone between the two steps.
        if ($user === null) {
            PendingTwoFactor::forget($request, $this->guard());

            return redirect()->route($this->loginRoute())
                ->withErrors(['email' => 'These credentials do not match our records.']);
        }

        /*
         * Throttled on its own key. The password throttle has already been
         * cleared by this point, so without this a correct password would buy
         * unlimited guesses at a six-digit code - which is 10^6, brute-forceable
         * in minutes.
         */
        $throttleKey = 'two-factor|'.$this->guard().'|'.$pending['id'].'|'.$request->ip();

        if (RateLimiter::tooManyAttempts($throttleKey, 5)) {
            throw ValidationException::withMessages([
                'code' => __('auth.throttle', [
                    'seconds' => RateLimiter::availableIn($throttleKey),
                    'minutes' => ceil(RateLimiter::availableIn($throttleKey) / 60),
                ]),
            ]);
        }

        if (! $this->twoFactor->verify($user, Str::of($request->string('code'))->trim()->toString())) {
            RateLimiter::hit($throttleKey);

            throw ValidationException::withMessages([
                'code' => 'That code is not valid.',
            ]);
        }

        RateLimiter::clear($throttleKey);
        PendingTwoFactor::forget($request, $this->guard());

        $this->completeLogin($user, $pending['remember']);
        $request->session()->regenerate();

        return redirect()->intended(route($this->intendedRoute()));
    }

    /** Abandoning the challenge must clear the note, not leave it armed. */
    public function destroy(Request $request): RedirectResponse
    {
        PendingTwoFactor::forget($request, $this->guard());

        return redirect()->route($this->loginRoute());
    }

    abstract protected function completeLogin(Authenticatable $user, bool $remember): void;
}
