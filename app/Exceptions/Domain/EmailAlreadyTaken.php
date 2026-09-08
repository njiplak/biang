<?php

namespace App\Exceptions\Domain;

/**
 * Two accounts may hold the same address pending at once - `pending_email`
 * carries no unique index on purpose - so whoever confirms first takes it and
 * the loser lands here rather than on a unique-constraint 500.
 */
class EmailAlreadyTaken extends DomainException
{
    public function __construct()
    {
        parent::__construct('That email address now belongs to another account.');
    }

    public function userMessage(): string
    {
        return 'That email address is already in use. Your account still uses your current address.';
    }
}
