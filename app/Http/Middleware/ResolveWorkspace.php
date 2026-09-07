<?php

namespace App\Http\Middleware;

use App\Models\Workspace;
use App\Support\CurrentWorkspace;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Establishes the tenant context for the request.
 *
 * Spec section 11 puts the app on a single address, so there is no subdomain to
 * read the tenant from - it comes from what the person picked in the switcher.
 * That makes the value user-controlled, which is why membership is re-checked
 * on every request rather than trusted from the session.
 */
class ResolveWorkspace
{
    public function handle(Request $request, Closure $next): Response
    {
        $context = app(CurrentWorkspace::class);
        $context->forget();

        // Explicitly the WEB guard. Workspace context is a customer concept,
        // and on admin routes the default guard is `admin` - an AdminUser has
        // no workspaces at all, so resolving the default here would fatal on
        // every page of the admin console.
        if ($user = $request->user('web')) {
            $context->set($this->resolveFor($request, $user));
        }

        return $next($request);
    }

    private function resolveFor(Request $request, $user): ?Workspace
    {
        $candidates = array_filter([
            $request->hasSession() ? $request->session()->get('current_workspace_id') : null,
            $user->current_workspace_id,
        ]);

        foreach ($candidates as $workspaceId) {
            if ($workspace = $this->membershipFor($user, $workspaceId)) {
                return $workspace;
            }
        }

        // Nothing chosen, or every choice was stale: fall back to any membership
        // so a signed-in person is never left without a context they can use.
        return $user->workspaces()->first();
    }

    /**
     * Returns the workspace only if this user is actually a member of it.
     * Without this check, editing one session value is a horizontal privilege
     * escalation into someone else's workspace.
     */
    private function membershipFor($user, $workspaceId): ?Workspace
    {
        return $user->workspaces()->whereKey($workspaceId)->first();
    }
}
