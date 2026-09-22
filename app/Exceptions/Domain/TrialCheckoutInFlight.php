<?php

namespace App\Exceptions\Domain;

use App\Models\User;

/**
 * This person already has a trial checkout open for a different workspace.
 *
 * Section 12 sells one trial per person, ever, but that is only RECORDED when
 * Dodo's webhook lands - so between the card form and the webhook,
 * hasConsumedTrial() answers false and a second workspace could start a second
 * trial on the same card.
 *
 * Deliberately not TrialAlreadyConsumed: they have not consumed one yet, and
 * telling somebody mid-purchase that they have already used a trial they are
 * in the middle of starting is the kind of wrong answer that generates a
 * support ticket. The claim lapses on its own, so waiting is a real remedy.
 */
class TrialCheckoutInFlight extends DomainException
{
    public function __construct(public readonly User $user)
    {
        parent::__construct("User {$user->id} already has a trial checkout in flight.");
    }

    public function userMessage(): string
    {
        return 'You already have a trial starting on another workspace. Finish or abandon that one first — a trial covers one workspace, and everyone gets one.';
    }
}
