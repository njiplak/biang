<?php

namespace App\Http\Middleware;

use App\Contract\Admin\ImpersonationContract;
use App\Http\Controllers\Admin\ImpersonationController;
use App\Models\AdminUser;
use App\Models\ImpersonationSession;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * The other half of EnsureAdminIsActive.
 *
 * Impersonation signs the WEB guard in as the customer and leaves it there, so
 * closing the console door does nothing about a staff member already inside a
 * customer's account - and that is the worse of the two doors. Nothing on the
 * customer side had ever looked at who put that session there.
 *
 * Runs on the web group, so it covers the customer screens the impersonated
 * session is actually used on, not only the console.
 */
class EndImpersonationForRevokedStaff
{
    public function __construct(private readonly ImpersonationContract $impersonation) {}

    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->hasSession()) {
            return $next($request);
        }

        $id = $request->session()->get(ImpersonationController::SESSION_KEY);

        // The overwhelmingly common case: a customer signed in as themselves,
        // who must not pay a query for this.
        if ($id === null) {
            return $next($request);
        }

        $session = ImpersonationSession::find($id);

        // find() applies the SoftDeletes scope, so an offboarded staff member
        // resolves to null for the same reason the login refuses them.
        $admin = $session === null ? null : AdminUser::find($session->admin_user_id);

        if ($session !== null && $session->isActive() && $admin !== null && $admin->is_active) {
            return $next($request);
        }

        /*
         * Deliberately the opposite of the banner's rule in
         * HandleInertiaRequests. There, a session it cannot confirm means no
         * banner. Here it means no access: a customer session nobody can still
         * account for is one to end, not one to trust.
         */
        if ($session !== null && $session->isActive()) {
            $this->impersonation->stop($session);
        }

        $request->session()->forget([ImpersonationController::SESSION_KEY, 'current_workspace_id']);

        // Both guards. The person at the keyboard is staff, and the customer
        // session is only there because the console put it there.
        Auth::guard('web')->logout();
        Auth::guard('admin')->logout();

        if ($request->expectsJson()) {
            abort(401, 'This impersonation session has ended.');
        }

        return redirect()->route('admin.login')
            ->withErrors(['email' => 'That impersonation session has ended.']);
    }
}
