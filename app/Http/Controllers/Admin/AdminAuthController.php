<?php

namespace App\Http\Controllers\Admin;

use App\Contract\Auth\AdminAuthContract;
use App\Http\Controllers\Controller;
use App\Http\Requests\LoginRequest;
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

        return Inertia::render('admin/auth/login');
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
        $request->session()->regenerate();

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
