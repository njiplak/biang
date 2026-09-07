<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Where a signed-in customer lands.
 *
 * Section 2: one person can belong to many workspaces with a different job in
 * each, so this is the switcher's home - it reads ACROSS workspaces, which is
 * why workspace_members is deliberately never tenant-scoped.
 */
class DashboardController extends Controller
{
    public function index(Request $request): Response
    {
        $user = $request->user();

        // The workspace list is already shared as `tenancy` on every page for
        // the switcher; shipping a second copy here was duplicate state, and
        // naming it `workspaces` silently overrode the shared one.
        return Inertia::render('dashboard', [
            'must_verify_email' => ! $user->hasVerifiedEmail(),
        ]);
    }
}
