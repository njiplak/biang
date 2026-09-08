<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Password;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Section 3 gave staff their own guard and their own table but no way back in
 * after a forgotten password, so recovering one meant another super-admin
 * retyping it - or, on an install with a single super-admin, a deploy.
 *
 * Its own broker, not the customer one: config('auth.passwords.admin_users')
 * writes to admin_password_reset_tokens, so a token minted for one world can
 * never be spent in the other even when the same person exists in both.
 */
class AdminPasswordResetLinkController extends Controller
{
    private const BROKER = 'admin_users';

    public function create(): Response
    {
        return Inertia::render('admin/auth/forgot-password', ['status' => session('status')]);
    }

    public function store(Request $request): RedirectResponse
    {
        $request->validate(['email' => ['required', 'email']]);

        /*
         * `is_active` is part of the lookup for the same reason it is part of
         * the credential check: a deactivated account is not a way back in, and
         * sending its owner a working link would say otherwise.
         */
        Password::broker(self::BROKER)->sendResetLink([
            'email' => $request->string('email')->toString(),
            'is_active' => true,
        ]);

        // Identical whether or not the address belongs to a staff account.
        // Anything else turns this form into a directory of who works here.
        return back()->with('status', 'If that address belongs to a staff account, a reset link is on its way.');
    }
}
