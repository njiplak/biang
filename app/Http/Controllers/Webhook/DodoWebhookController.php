<?php

namespace App\Http\Controllers\Webhook;

use App\Contract\Billing\WebhookVerifierContract;
use App\Http\Controllers\Controller;
use App\Jobs\ReconcileWebhookEvent;
use App\Models\WebhookEvent;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Throwable;

/**
 * Intake for Dodo's notifications (spec section 8).
 *
 * Three things this endpoint must get right, all of them load-bearing:
 *
 * 1. Verify BEFORE anything is stored. An unverified event is not evidence, and
 *    an endpoint that records first would let anyone fill our audit trail.
 * 2. Be idempotent. Webhooks are redelivered by design; unique(provider,
 *    event_id) is what makes a replay a no-op rather than a double charge in
 *    our records.
 * 3. Answer 200 for anything we have accepted, including event types we do not
 *    act on. A non-2xx tells Dodo to retry forever.
 *
 * Accepting is all this does. Reconciling happens on the queue, because it is
 * not part of accepting a delivery - and doing it inline put an entitlement
 * rebuild inside Dodo's HTTP timeout, where being slow reads as having failed
 * and earns a redelivery of the event that was slowest to process.
 *
 * What that gives up is Dodo's own retry as the safety net under a reconcile
 * that throws, since by then we have already answered 200. What replaces it is
 * ours: the job retries, billing-ops can replay the row by hand, and
 * billing:reconcile-subscriptions asks Dodo directly every hour.
 */
class DodoWebhookController extends Controller
{
    public function __construct(
        private readonly WebhookVerifierContract $verifier,
    ) {}

    public function __invoke(Request $request): JsonResponse
    {
        $payload = $request->getContent();

        // The Standard Webhooks headers the SDK's own verifier reads.
        $headers = [
            'webhook-id' => $request->header('webhook-id', ''),
            'webhook-timestamp' => $request->header('webhook-timestamp', ''),
            'webhook-signature' => $request->header('webhook-signature', ''),
        ];

        if (! $this->verifier->verify($payload, $headers)) {
            // 401, not 200: a signature failure is either a misconfiguration or
            // someone probing, and both are worth seeing in Dodo's own retry
            // dashboard rather than silently swallowing.
            return response()->json(['message' => 'Invalid signature.'], 401);
        }

        $decoded = json_decode($payload, true);

        if (! is_array($decoded)) {
            return response()->json(['message' => 'Malformed payload.'], 400);
        }

        $event = $this->record($decoded, $headers['webhook-id']);

        // Already handled on an earlier delivery. Answer 200 so Dodo stops.
        if ($event->processed_at !== null) {
            return response()->json(['message' => 'Already processed.']);
        }

        try {
            ReconcileWebhookEvent::dispatch($event->id);
        } catch (Throwable $e) {
            /*
             * Only reachable on a synchronous queue, where reconciling really
             * is part of accepting the delivery - so the old answer is still
             * the right one there. The job has already recorded why.
             */
            report($e);

            return response()->json(['message' => 'Could not process.'], 500);
        }

        return response()->json(['message' => 'ok']);
    }

    /**
     * Idempotent by (provider, event_id). The id comes from the `webhook-id`
     * header, which is the one value Standard Webhooks guarantees is stable
     * across redeliveries of the same event.
     */
    private function record(array $decoded, string $eventId): WebhookEvent
    {
        return WebhookEvent::firstOrCreate(
            ['provider' => 'dodo', 'event_id' => $eventId],
            [
                'event_type' => $decoded['type'] ?? 'unknown',
                'payload' => $decoded,
                'signature_verified' => true,
                'occurred_at' => isset($decoded['timestamp'])
                    ? Carbon::parse($decoded['timestamp'])
                    : now(),
                'received_at' => now(),
                'attempts' => 0,
            ],
        );
    }
}
