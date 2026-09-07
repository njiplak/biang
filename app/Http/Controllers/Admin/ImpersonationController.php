<?php

namespace App\Http\Controllers\Admin;

use App\Contract\Admin\ImpersonationContract;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\ImpersonateRequest;
use App\Models\AdminUser;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * Section 10: "Enter a customer's workspace as them, with an obvious banner
 * saying so and a permanent record that we did it."
 *
 * The service owns the record; this owns the session swap. Two things about
 * that swap are deliberate:
 *
 * - The `admin` guard STAYS signed in. It is what authorises the way back out,
 *   so losing it would strand the staff member inside a customer account.
 * - The workspace is written into the session, not left to ResolveWorkspace's
 *   fallback. Entering "their workspace" and landing in whichever one sorts
 *   first is not reproducing the complaint.
 */
class ImpersonationController extends Controller
{
    public const SESSION_KEY = 'impersonation_session_id';

    public function __construct(private readonly ImpersonationContract $impersonation) {}

    public function start(ImpersonateRequest $request, Workspace $workspace): RedirectResponse
    {
        $session = $this->impersonation->start(
            $this->admin(),
            User::findOrFail($request->validated('user_id')),
            $workspace,
            $request->validated('reason'),
            $request->validated('ticket_reference'),
            $request->ip(),
            $request->userAgent(),
        );

        Auth::guard('web')->login($session->user);

        $request->session()->put(self::SESSION_KEY, $session->id);
        $request->session()->put('current_workspace_id', $workspace->id);

        return redirect()->route('dashboard');
    }

    /**
     * Resolved from the staff member's OPEN session rather than from the browser
     * session key. A closed tab leaves the row open while the session key is
     * gone, and that stale row would otherwise block every future impersonation
     * with no way to clear it.
     */
    public function stop(Request $request): RedirectResponse
    {
        $session = $this->impersonation->open($this->admin());

        if ($session === null) {
            return redirect()->route('admin.customer.index');
        }

        $this->impersonation->stop($session);

        // Only tear down the customer session if this browser is the one
        // carrying it; ending a stale row must not sign anyone out.
        if ($request->session()->pull(self::SESSION_KEY) !== null) {
            Auth::guard('web')->logout();
            $request->session()->forget('current_workspace_id');
        }

        $workspace = $session->workspace_id === null
            ? null
            : Workspace::withTrashed()->find($session->workspace_id);

        return $workspace === null
            ? redirect()->route('admin.customer.index')
            : redirect()->route('admin.customer.show', $workspace->ulid);
    }

    private function admin(): AdminUser
    {
        return auth()->guard('admin')->user();
    }
}
