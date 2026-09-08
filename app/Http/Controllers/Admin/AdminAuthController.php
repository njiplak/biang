<?php

namespace App\Http\Controllers\Admin;

use App\Contract\Auth\AdminAuthContract;
use App\Http\Controllers\Controller;
use App\Http\Requests\LoginRequest;
use App\Support\PendingTwoFactor;
use Exception;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Inertia\Response;

class AdminAuthController extends Controller
{
    public function __construct(private readonly AdminAuthContract $service) {}

    public function login(): Response|RedirectResponse
    {
        if (Auth::guard('admin')->check()) {
            return redirect()->route('admin.dashboard');
        }

        // Set by AdminNewPasswordController, which deliberately does not sign
        // anybody in - without this the reset ends on a bare form with no sign
        // that it worked.
        return Inertia::render('admin/auth/login', ['status' => session('status')]);
    }

    public function attempt(LoginRequest $request): RedirectResponse
    {
        // LoginRequest has carried these helpers all along, but the customer
        // login never calls them. An unthrottled login on the admin console is
        // a brute-force target, so this one uses them.
        $request->ensureIsNotRateLimited();

        $result = $this->service->login($request->validated());

        if ($result instanceof Exception) {
            $request->hitRateLimiter();

            return back()->withErrors(['email' => $result->getMessage()]);
        }

        $request->clearRateLimiter();

        $remember = (bool) ($request->validated()['remember'] ?? false);

        // Staff reach every customer's data and can impersonate them, so the
        // same rule applies here with more reason: password alone is not entry.
        if ($result->hasTwoFactorEnabled()) {
            PendingTwoFactor::remember($request, 'admin', $result->getKey(), $remember);

            return redirect()->route('admin.two-factor.challenge');
        }

        $this->service->completeLogin($result, $remember);
        $request->session()->regenerate();

        // admin_users carries these two columns and nothing wrote them. Who was
        // in the console and when is the first question asked when offboarding
        // staff or reviewing an incident, and it cannot be answered afterwards.
        Auth::guard('admin')->user()->forceFill([
            'last_login_at' => now(),
            'last_login_ip' => $request->ip(),
        ])->save();

        return redirect()->intended(route('admin.dashboard'));
    }

    /**
     * Only the admin guard is logged out. The session itself is left intact
     * because it may still be carrying a signed-in customer - and section 3's
     * separation cuts both ways.
     */
    public function logout(Request $request): RedirectResponse
    {
        $this->service->logout();

        return redirect()->route('admin.login');
    }
}
