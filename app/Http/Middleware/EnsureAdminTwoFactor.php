<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Section 3 and section 10 together: staff reach every customer's data, can
 * comp a plan and can enter any workspace as its owner. AdminAuthController
 * already says "password alone is not entry" - but only for staff who had
 * chosen to enrol, which made the strongest control in the console optional.
 *
 * Enrolment is self-service and reachable from here, so nobody is locked out:
 * the console simply does not open until it is done.
 */
class EnsureAdminTwoFactor
{
    /**
     * Enrolling itself, and the two ways back out of the console. Without the
     * first the redirect loops; without the others a staff member mid-way
     * through enrolment cannot sign out or leave a customer's account.
     */
    private const ALWAYS_ALLOWED = [
        'admin.two-factor.*',
        'admin.logout',
        'admin.impersonation.stop',
    ];

    public function handle(Request $request, Closure $next): Response
    {
        $admin = $request->user('admin');

        if ($admin === null
            || $admin->hasTwoFactorEnabled()
            || $request->routeIs(...self::ALWAYS_ALLOWED)) {
            return $next($request);
        }

        if ($request->expectsJson()) {
            abort(403, 'Set up two-factor authentication to use the console.');
        }

        return redirect()->route('admin.two-factor.edit')
            ->with('warning', 'Two-factor authentication is required for staff accounts. Set it up to continue.');
    }
}
