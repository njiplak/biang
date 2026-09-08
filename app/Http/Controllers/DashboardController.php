<?php

namespace App\Http\Controllers;

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
    public function index(): Response
    {
        // The workspace list is already shared as `tenancy` on every page for
        // the switcher; shipping a second copy here was duplicate state, and
        // naming it `workspaces` silently overrode the shared one.
        //
        // No verification prop any more: the route is behind `verified`, so
        // nobody who needs telling can get here. A banner offering to resend a
        // link, on a page an unverified account cannot open, was UI for a state
        // that no longer exists.
        return Inertia::render('dashboard');
    }
}
