<?php

namespace App\Exceptions\Domain;

/**
 * Covers unknown, expired, revoked and already-used tokens with one message on
 * purpose: distinguishing them tells an attacker which tokens exist.
 */
class InvitationNotAcceptable extends DomainException
{
    public function __construct(string $reason)
    {
        parent::__construct("Invitation cannot be accepted: {$reason}.");
    }

    public function userMessage(): string
    {
        return 'This invitation is no longer valid. Ask for a new one.';
    }
}
