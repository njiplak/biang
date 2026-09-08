<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AdminUser;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/** The second half of the staff reset. @see AdminPasswordResetLinkController */
class AdminNewPasswordController extends Controller
{
    private const BROKER = 'admin_users';

    public function create(Request $request, string $token): Response
    {
        return Inertia::render('admin/auth/reset-password', [
            'token' => $token,
            'email' => $request->string('email')->toString(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $request->validate([
            'token' => ['required'],
            'email' => ['required', 'email'],
            'password' => ['required', 'confirmed', Rules\Password::defaults()],
        ]);

        $status = Password::broker(self::BROKER)->reset(
            [
                ...$request->only('email', 'password', 'password_confirmation', 'token'),
                // Rechecked here, not just when the link was issued: staff can
                // be deactivated in the hour a token stays valid.
                'is_active' => true,
            ],
            function (AdminUser $admin) use ($request) {
                $admin->forceFill([
                    'password' => $request->string('password')->toString(),
                    'remember_token' => Str::random(60),
                ])->save();

                event(new PasswordReset($admin));
            }
        );

        if ($status !== Password::PasswordReset) {
            // Against `email` because that is the field on the form, and a bad
            // token almost always means the link is stale.
            throw ValidationException::withMessages(['email' => [__($status)]]);
        }

        /*
         * Not signed in. A new password is one factor, and the console asks for
         * two - sending them to the login is what makes them prove the second.
         */
        return redirect()->route('admin.login')->with('status', __($status));
    }
}
