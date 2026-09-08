<?php

namespace App\Http\Controllers\Settings;

use App\Contract\Auth\TwoFactorContract;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Opt-in TOTP enrolment for a customer account.
 *
 * Every route that changes state here sits behind `password.confirm`: adding,
 * replacing or removing a second factor from a session someone walked away from
 * is exactly the move an attacker wants, and re-proving the password is what
 * makes a stolen session insufficient.
 */
class TwoFactorController extends Controller
{
    public function __construct(private readonly TwoFactorContract $twoFactor) {}

    public function edit(Request $request): Response
    {
        $user = $request->user();

        return Inertia::render('settings/two-factor', [
            'enabled' => $user->hasTwoFactorEnabled(),
            'pending' => $user->hasPendingTwoFactor(),
            // Only ever sent while enrolment is in progress. Once confirmed the
            // seed has no reason to cross the wire again.
            'qrSvg' => $user->hasPendingTwoFactor() ? $this->twoFactor->qrCodeSvg($user) : null,
            'setupKey' => $user->hasPendingTwoFactor() ? $this->twoFactor->setupKey($user) : null,
            'recoveryCodes' => $user->hasTwoFactorEnabled() ? $user->twoFactorRecoveryCodes() : [],
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $this->twoFactor->beginEnrolment($request->user());

        return to_route('two-factor.edit');
    }

    public function confirm(Request $request): RedirectResponse
    {
        $request->validate(['code' => ['required', 'string']]);

        if (! $this->twoFactor->confirm($request->user(), $request->string('code')->trim()->toString())) {
            throw ValidationException::withMessages(['code' => 'That code is not valid. Check your authenticator and try again.']);
        }

        return to_route('two-factor.edit')->with('status', 'two-factor-enabled');
    }

    public function regenerate(Request $request): RedirectResponse
    {
        $this->twoFactor->regenerateRecoveryCodes($request->user());

        return to_route('two-factor.edit')->with('status', 'recovery-codes-regenerated');
    }

    public function destroy(Request $request): RedirectResponse
    {
        $this->twoFactor->disable($request->user());

        return to_route('two-factor.edit')->with('status', 'two-factor-disabled');
    }
}
