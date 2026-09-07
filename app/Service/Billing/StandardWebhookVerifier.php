<?php

namespace App\Service\Billing;

use App\Contract\Billing\WebhookVerifierContract;
use StandardWebhooks\Webhook;
use Throwable;

/**
 * Standard Webhooks verification, which is what the Dodo SDK itself uses -
 * see Dodopayments\Services\WebhooksService::unwrap().
 *
 * Used directly rather than through the SDK's unwrap() because unwrap coerces
 * the body into a union of 47 typed events and throws on anything it does not
 * recognise. We need the opposite: verify strictly, then record every event
 * including the kinds we do not act on, so the trail is complete.
 */
class StandardWebhookVerifier implements WebhookVerifierContract
{
    public function verify(string $payload, array $headers): bool
    {
        $secret = config('dodo.webhook_key');

        // No secret configured is a refusal, never a pass. Treating a missing
        // key as "skip verification" would turn a misconfigured deploy into an
        // open endpoint that anyone could use to activate a subscription.
        if (blank($secret)) {
            return false;
        }

        try {
            // Throws on a bad signature, a missing header, or a timestamp
            // outside the replay window.
            (new Webhook($secret))->verify($payload, $headers);

            return true;
        } catch (Throwable) {
            return false;
        }
    }
}
