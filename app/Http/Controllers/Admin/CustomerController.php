<?php

namespace App\Http\Controllers\Admin;

use App\Contract\Admin\AuditContract;
use App\Contract\Admin\CustomerContract;
use App\Contract\Billing\EntitlementContract;
use App\Contract\Billing\SubscriptionContract;
use App\Contract\Workspace\WorkspaceContract;
use App\Exports\CustomerDirectoryExport;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\ExtendTrialRequest;
use App\Http\Requests\Admin\GrantPlanRequest;
use App\Http\Requests\Admin\OverrideRequest;
use App\Http\Requests\Admin\SuspendWorkspaceRequest;
use App\Models\AdminUser;
use App\Models\Feature;
use App\Models\PlanPrice;
use App\Models\Workspace;
use App\Models\WorkspaceEntitlementOverride;
use App\Service\Admin\CustomerService;
use App\Support\TablePayload;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Maatwebsite\Excel\Facades\Excel;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Section 10's customer-facing staff jobs, on one screen:
 * "Answer a support ticket in under a minute", "Close a deal / rescue a
 * customer" and "Stop abuse".
 *
 * Every write here goes through the existing domain services rather than
 * touching a model directly - the seat rules, the hard block and the one-live-
 * subscription rule all live there, and a second implementation of any of them
 * would drift.
 */
class CustomerController extends Controller
{
    public function __construct(
        private readonly CustomerContract $customers,
        private readonly SubscriptionContract $subscriptions,
        private readonly EntitlementContract $entitlements,
        private readonly WorkspaceContract $workspaces,
        private readonly AuditContract $audit,
    ) {}

    public function index(): Response
    {
        return Inertia::render('admin/customer/index');
    }

    public function fetch(Request $request): JsonResponse
    {
        // `filter[search]` is what NextTable sends, and what every other index
        // screen in the app already reads.
        $paginator = $this->customers->search(
            $request->string('filter.search')->toString() ?: null,
            (int) $request->get('per_page', 10),
        );

        return response()->json([
            'items' => collect($paginator->items())
                ->map(fn (Workspace $workspace) => $this->customers->summarise($workspace))
                ->all(),
            'prev_page' => $paginator->currentPage() > 1 ? $paginator->currentPage() - 1 : null,
            'current_page' => $paginator->currentPage(),
            'next_page' => $paginator->hasMorePages() ? $paginator->currentPage() + 1 : null,
            'total_page' => $paginator->lastPage(),
            'per_page' => $paginator->perPage(),
        ]);
    }

    /** Section 14 phase 6: the export staff ask for first. */
    public function export(Request $request): BinaryFileResponse
    {
        $term = $request->string('filter.search')->toString() ?: null;

        $this->audit->record('customer.directory_exported', null, null, ['search' => $term]);

        return Excel::download(
            new CustomerDirectoryExport($this->customers, $term),
            'customers-'.now()->toDateString().'.xlsx',
        );
    }

    public function show(Workspace $workspace): Response
    {
        return Inertia::render('admin/customer/show', $this->customers->overview($workspace));
    }

    // ------------------------------------------------------------ stop abuse

    /**
     * One of the detail page's lists. Gated on customer.view like show(), and
     * withTrashed for the same reason: a ticket about a workspace that vanished
     * is exactly when support needs to read it.
     */
    public function fetchDetail(Request $request, Workspace $workspace): JsonResponse
    {
        $validated = $request->validate([
            'list' => ['required', Rule::in(CustomerService::LISTS)],
        ]);

        return response()->json(TablePayload::from(
            $this->customers->paginateDetail(
                $workspace,
                $validated['list'],
                $request->string('filter.search')->toString() ?: null,
                (int) $request->get('per_page', 15),
            ),
            fn (array $row) => $row,
        ));
    }

    public function suspend(SuspendWorkspaceRequest $request, Workspace $workspace): RedirectResponse
    {
        $this->workspaces->suspend($workspace, $this->admin(), $request->validated('reason'));

        $this->audit->record('workspace.suspended', $workspace, $workspace, [
            'reason' => $request->validated('reason'),
        ]);

        return back();
    }

    public function unsuspend(Workspace $workspace): RedirectResponse
    {
        $this->workspaces->unsuspend($workspace);

        $this->audit->record('workspace.unsuspended', $workspace, $workspace);

        return back();
    }

    // --------------------------------------------------------- close a deal

    /**
     * One button for what staff think of as one job: "put this customer on this
     * plan". Whether that opens a subscription or moves an existing one is a
     * detail of our own table, and making sales pick the right verb is exactly
     * the friction section 10 is complaining about.
     *
     * changePlan still enforces the seat rules, so a downgrade that would
     * strand people is refused here the same as anywhere else.
     */
    public function grantPlan(GrantPlanRequest $request, Workspace $workspace): RedirectResponse
    {
        $price = PlanPrice::findOrFail($request->validated('plan_price_id'));

        $existing = $workspace->subscription()->withoutWorkspaceScope()->exists();

        $existing
            ? $this->subscriptions->changePlan($workspace, $price)
            : $this->subscriptions->grantPlan($workspace, $price, $this->admin(), $request->validated('reason'));

        $this->audit->record($existing ? 'plan.changed' : 'plan.granted', $workspace, $price, [
            'plan_price_id' => $price->id,
            'reason' => $request->validated('reason'),
        ]);

        return back();
    }

    public function extendTrial(ExtendTrialRequest $request, Workspace $workspace): RedirectResponse
    {
        $this->subscriptions->extendTrial(
            $workspace,
            (int) $request->validated('days'),
            $this->admin(),
            $request->validated('reason'),
        );

        $this->audit->record('trial.extended', $workspace, $workspace, [
            'days' => (int) $request->validated('days'),
            'reason' => $request->validated('reason'),
        ]);

        return back();
    }

    public function storeOverride(OverrideRequest $request, Workspace $workspace): RedirectResponse
    {
        $override = $this->entitlements->override(
            $workspace,
            $feature = Feature::findOrFail($request->validated('feature_id')),
            $request->validated('value'),
            $this->admin(),
            $request->validated('reason'),
            $request->date('expires_at'),
        );

        $this->audit->record('entitlement.overridden', $workspace, $override, [
            'feature' => $feature->key,
            // Null is unlimited, and that distinction is the whole point of the
            // record - "gave them everything" reads very differently to "gave
            // them 50".
            'value' => $request->validated('value'),
            'reason' => $request->validated('reason'),
        ]);

        return back();
    }

    /**
     * Resolved by hand rather than by route-model binding. The override table is
     * tenant-scoped, so an implicit binding would run through the global scope
     * and 404 whenever the staff member also holds a customer session. Looking
     * it up under the workspace from the URL is both scope-free and the
     * ownership check.
     */
    public function destroyOverride(Workspace $workspace, int $override): RedirectResponse
    {
        $model = WorkspaceEntitlementOverride::withoutWorkspaceScope()
            ->where('workspace_id', $workspace->id)
            ->find($override);

        if ($model === null) {
            throw new NotFoundHttpException('Override does not belong to this workspace.');
        }

        $this->entitlements->revokeOverride($model);

        $this->audit->record('entitlement.override_revoked', $workspace, $model, [
            'feature' => $model->feature?->key,
        ]);

        return back();
    }

    /** Every write here is recorded against the staff member who made it. */
    private function admin(): AdminUser
    {
        return auth()->guard('admin')->user();
    }
}
