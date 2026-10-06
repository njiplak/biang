<?php

namespace App\Http\Controllers\Admin;

use App\Contract\Admin\RevenueContract;
use App\Http\Controllers\Controller;
use App\Models\ProductEvent;
use App\Support\ProductEvents;
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
            // Section 15: where people drop out between signing up and paying.
            'funnel' => $admin->hasAnyPermission('revenue.view')
                ? $this->funnel()
                : null,
        ]);
    }

    /**
     * Each funnel step's count over the last 30 days, in funnel order.
     *
     * @return list<array{step: string, count: int}>
     */
    private function funnel(): array
    {
        $counts = ProductEvent::query()
            ->whereIn('name', ProductEvents::FUNNEL)
            ->where('occurred_at', '>=', now()->subDays(30))
            ->groupBy('name')
            ->selectRaw('name, count(*) as total')
            ->pluck('total', 'name');

        return array_map(
            fn (string $step) => ['step' => $step, 'count' => (int) ($counts[$step] ?? 0)],
            ProductEvents::FUNNEL,
        );
    }
}
