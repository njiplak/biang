<?php

namespace App\Exceptions\Domain;

/** The confirmation link outlived the change it was issued for. */
class EmailChangeNotPending extends DomainException
{
    public function __construct()
    {
        parent::__construct('No email change is pending for this account.');
    }

    public function userMessage(): string
    {
        return 'That confirmation link is no longer valid. Request the change again from your profile.';
    }
}
