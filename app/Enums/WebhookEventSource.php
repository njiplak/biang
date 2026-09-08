<?php

namespace App\Enums;

/**
 * How a provider event reached us.
 *
 * Section 8 makes Dodo's records authoritative for money, and a webhook is only
 * one way of hearing them. The other is asking directly - on the checkout
 * return, and on the scheduled drift check - which matters because a webhook
 * that never arrives is indistinguishable from nothing having happened.
 *
 * Recorded rather than inferred because the two differ in exactly one way that
 * decides whether an event may move billing state: a webhook is trusted because
 * its SIGNATURE verified, and a pull is trusted because WE placed the call. A
 * pulled event carries no signature at all, and marking one `signature_verified`
 * would be a lie in the audit trail.
 */
enum WebhookEventSource: string
{
    case Webhook = 'webhook';
    case Pull = 'pull';

    /**
     * Whether an event from this source is evidence without a signature.
     *
     * Only a pull is: it is the answer to a request we made over TLS to an
     * address we configured, which is a stronger claim than a signed body from
     * an unauthenticated caller, not a weaker one.
     */
    public function isSelfOriginated(): bool
    {
        return $this === self::Pull;
    }
}
