<?php

namespace App\Exceptions\Domain;

use App\Models\PlanPrice;
use App\Service\Billing\SubscriptionService;

/**
 * This price is not sold with a trial, so there are no free days to start.
 *
 * Annual is the case this exists for. A trial auto-charges at the end (section
 * 4), and a whole year's fee arriving unannounced on day 15 is the shape that
 * turns a conversion into a dispute - which, section 8 making Dodo merchant of
 * record, reaches us as a chargeback rather than a refund request.
 *
 * The UI does not offer the button for such a price, so reaching here means a
 * hand-posted price id. It still answers plainly rather than failing oddly: the
 * honest instruction is "buy it, or trial the monthly one".
 */
class TrialNotOffered extends DomainException
{
    /** Decided at the refusal: a page loaded before trials were switched off still has the button. */
    private readonly bool $trialsSwitchedOff;

    public function __construct(public readonly PlanPrice $price)
    {
        $this->trialsSwitchedOff = SubscriptionService::trialLength() === null;

        parent::__construct("Price {$price->id} is not sold with a trial.");
    }

    public function userMessage(): string
    {
        // Pointing them at the monthly trial would send them to a button that is not there.
        if ($this->trialsSwitchedOff) {
            return 'Free trials are not available right now. You can subscribe to this plan directly.';
        }

        return 'This plan is not offered with a free trial on that billing period. You can subscribe to it directly, or start a trial on the monthly price and switch whenever you like.';
    }
}
