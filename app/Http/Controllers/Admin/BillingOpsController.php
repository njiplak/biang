<?php

namespace App\Http\Controllers\Admin;

use App\Contract\Admin\AuditContract;
use App\Contract\Admin\BillingOpsContract;
use App\Http\Controllers\Controller;
use App\Models\WebhookEvent;
use Illuminate\Http\RedirectResponse;
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
