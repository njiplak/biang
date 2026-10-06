<?php

namespace App\Exceptions\Domain;

use App\Models\PlanPrice;

/**
 * A plan change that has to be paid for through a new checkout, asked for in
 * place.
 *
 * Moving onto a lifetime plan, off one, or up a lifetime tier involves a
 * payment with no Dodo subscription on at least one side, so there is nothing
 * for them to prorate. The in-place change used to move such a plan without
 * charging at all; this is the refusal that replaced it.
 */
class PlanChangeRequiresCheckout extends DomainException
{
    public function __construct(public readonly PlanPrice $price)
    {
        parent::__construct("Moving to plan price {$price->id} is paid for through checkout, not changed in place.");
    }

    public function userMessage(): string
    {
        return 'This change is a new payment, so it goes through checkout. Choose the plan from the billing page to continue. Nothing has changed.';
    }
}
