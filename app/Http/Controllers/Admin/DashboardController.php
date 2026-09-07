<?php

namespace App\Http\Controllers\Admin;

use App\Contract\Admin\RevenueContract;
use App\Http\Controllers\Controller;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Section 10 lists what staff need from here: understand the business, answer a
 * ticket in under a minute, reproduce a complaint, close a deal, stop abuse,
 * talk to everyone. This is the shell those land in.
 */
class DashboardController extends Controller
{
    public function __construct(private readonly RevenueContract $revenue) {}

    public function index(): Response
    {
        $admin = auth()->guard('admin')->user();

        return Inertia::render('admin/dashboard', [
            'admin' => $admin->only(['name', 'email']),
            // Section 10 splits the console's jobs across roles, and the money
            // is finance's. Everyone else gets the shell without the figures.
            // hasAnyPermission, not hasPermissionTo: the latter throws when the
            // permission row does not exist yet, which is every unseeded install.
            'revenue' => $admin->hasAnyPermission('revenue.view')
                ? $this->revenue->summary()
                : null,
        ]);
    }
}
