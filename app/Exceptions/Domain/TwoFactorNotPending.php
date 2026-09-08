<?php

namespace App\Exceptions\Domain;

/** Asked about a secret for an account that has not started enrolling. */
class TwoFactorNotPending extends DomainException
{
    public function __construct()
    {
        parent::__construct('No two-factor secret has been issued for this account.');
    }

    public function userMessage(): string
    {
        return 'Start setting up two-factor authentication before confirming it.';
    }
}
