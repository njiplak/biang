<?php

namespace App\Http\Controllers\Admin;

use App\Contract\Auth\TwoFactorContract;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Staff enrolment. Same mechanism as the customer side, its own guard.
 *
 * There is no `password.confirm` here: that middleware is hard-wired to the web
 * guard's confirm-password screen, which staff cannot reach. The console is
 * already behind its own login, and the challenge itself is throttled.
 */
class AdminTwoFactorController extends Controller
{
    public function __construct(private readonly TwoFactorContract $twoFactor) {}

    public function edit(Request $request): Response
    {
        $admin = $request->user('admin');

        return Inertia::render('admin/settings/two-factor', [
            'enabled' => $admin->hasTwoFactorEnabled(),
            'pending' => $admin->hasPendingTwoFactor(),
            'qrSvg' => $admin->hasPendingTwoFactor() ? $this->twoFactor->qrCodeSvg($admin) : null,
            'setupKey' => $admin->hasPendingTwoFactor() ? $this->twoFactor->setupKey($admin) : null,
            'recoveryCodes' => $admin->hasTwoFactorEnabled() ? $admin->twoFactorRecoveryCodes() : [],
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $this->twoFactor->beginEnrolment($request->user('admin'));

        return to_route('admin.two-factor.edit');
    }

    public function confirm(Request $request): RedirectResponse
    {
        $request->validate(['code' => ['required', 'string']]);

        if (! $this->twoFactor->confirm($request->user('admin'), $request->string('code')->trim()->toString())) {
            throw ValidationException::withMessages(['code' => 'That code is not valid. Check your authenticator and try again.']);
        }

        return to_route('admin.two-factor.edit')->with('status', 'two-factor-enabled');
    }

    public function regenerate(Request $request): RedirectResponse
    {
        $this->twoFactor->regenerateRecoveryCodes($request->user('admin'));

        return to_route('admin.two-factor.edit')->with('status', 'recovery-codes-regenerated');
    }

    public function destroy(Request $request): RedirectResponse
    {
        $this->twoFactor->disable($request->user('admin'));

        return to_route('admin.two-factor.edit')->with('status', 'two-factor-disabled');
    }
}
