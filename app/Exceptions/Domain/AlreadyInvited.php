<?php

namespace App\Exceptions\Domain;

use App\Models\Workspace;

/**
 * One live invitation per address per workspace (the pending-unique index).
 * Checked up front so the seat offer never charges for an invitation that the
 * database was always going to refuse.
 */
class AlreadyInvited extends DomainException
{
    public function __construct(
        public readonly Workspace $workspace,
        public readonly string $email,
    ) {
        parent::__construct("Workspace {$workspace->id} already has a live invitation for {$email}.");
    }

    public function userMessage(): string
    {
        return "There is already an invitation for {$this->email}. Resend or revoke it from the list instead.";
    }
}
