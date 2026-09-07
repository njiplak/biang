<?php

namespace App\Contract\Billing;

/**
 * Section 8: Dodo's notifications are authoritative for money, so an event we
 * cannot prove came from them must never be allowed to move billing state.
 *
 * Behind a contract because the whole security property of the webhook endpoint
 * is "this verifier said yes", and a test that fakes the verifier is testing
 * nothing. The real implementation is the SDK's own dependency.
 */
interface WebhookVerifierContract
{
    /**
     * @param  array<string, string>  $headers
     */
    public function verify(string $payload, array $headers): bool;
}
