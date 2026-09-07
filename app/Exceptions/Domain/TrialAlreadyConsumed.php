<?php

namespace App\Exceptions\Domain;

use App\Models\User;

/**
 * Section 12: one trial per person, EVER - not per workspace. Someone who has
 * already trialled and then creates a second workspace starts on free or paid,
 * never on trial.
 *
 * Section 16 accepts that this occasionally catches a genuine customer, and the
 * remedy is a staff grant from the admin console rather than a looser rule.
 */
class TrialAlreadyConsumed extends DomainException
{
    public function __construct(public readonly User $user)
    {
        parent::__construct("User {$user->id} has already used their trial.");
    }

    public function userMessage(): string
    {
        return 'You have already used your free trial. Choose a paid plan, or contact us if you think this is wrong.';
    }
}
