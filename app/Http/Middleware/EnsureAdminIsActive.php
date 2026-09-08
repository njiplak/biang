<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Section 3: deactivating a staff member is the offboarding path, and it has to
 * take effect now rather than whenever their session happens to expire.
 *
 * `is_active` was only ever part of the credential check (AdminAuthService::
 * lookupCredentials), which stops the next LOGIN and does nothing about the
 * session already open in the leaver's browser. Offboarding does not have this
 * hole - the row is soft-deleted, and the model's SoftDeletes scope makes the
 * guard fail to resolve it - so this covers the one path that stayed open.
 */
class EnsureAdminIsActive
{
    public function handle(Request $request, Closure $next): Response
    {
        $admin = $request->user('admin');

        if ($admin === null || $admin->is_active) {
            return $next($request);
        }

        /*
         * Only the admin guard, exactly like AdminAuthController::logout. The
         * same session may still be carrying a signed-in customer, and losing
         * console access is not a reason to sign somebody out of their own
         * account.
         */
        Auth::guard('admin')->logout();

        // The console's tables fetch as JSON. A 302 to the login page there
        // reads as an empty table rather than as a door being closed.
        if ($request->expectsJson()) {
            abort(401, 'Your staff access has been withdrawn.');
        }

        return redirect()->route('admin.login')
            ->withErrors(['email' => 'Your staff access has been withdrawn.']);
    }
}
