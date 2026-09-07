<?php

namespace App\Exceptions\Domain;

/** Section 3: a workspace must always have at least one owner. */
class LastOwnerCannotLeave extends DomainException
{
    public function __construct()
    {
        parent::__construct('The last owner cannot be removed or demoted.');
    }

    public function userMessage(): string
    {
        return 'Transfer ownership to someone else before leaving this workspace.';
    }
}
