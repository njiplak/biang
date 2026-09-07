<?php

namespace App\Http\Controllers\Admin;

use App\Contract\Admin\AuditContract;
use App\Contract\Admin\BillingOpsContract;
use App\Http\Controllers\Controller;
use App\Models\WebhookEvent;
use App\Service\Admin\BillingOpsService;
use App\Support\TablePayload;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Section 8's operational half. A payment integration whose failures are silent
 * is one you find out about from the customer.
 */
class BillingOpsController extends Controller
{
    public function __construct(
        private readonly BillingOpsContract $ops,
        private readonly AuditContract $audit,
    ) {}

    public function index(): Response
    {
        return Inertia::render('admin/billing-ops/index', $this->ops->overview());
    }

    /**
     * One of this screen's four lists, paginated.
     *
     * `list` is validated against the service's own allow-list rather than
     * trusted: it arrives from a query string and selects a query, so an
     * unchecked value is a way to ask this endpoint for anything.
     */
    public function fetch(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'list' => ['required', Rule::in(BillingOpsService::LISTS)],
        ]);

        return response()->json(TablePayload::from(
            $this->ops->paginate(
                $validated['list'],
                $request->string('filter.search')->toString() ?: null,
                (int) $request->get('per_page', 15),
            ),
            fn (array $row) => $row,
        ));
    }

    public function retry(WebhookEvent $event): RedirectResponse
    {
        $this->ops->retry($event);

        // Replaying an event moves billing state, so it is a staff action on a
        // customer even though it looks like an infrastructure button.
        $this->audit->record('webhook.retried', $event->fresh()->workspace, $event, [
            'event_id' => $event->event_id,
            'event_type' => $event->event_type,
        ]);

        return back();
    }
}
