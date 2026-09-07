<?php

namespace App\Exceptions\Domain;

/**
 * Section 10 is specific: "Enter a CUSTOMER'S WORKSPACE as them." Both halves
 * have to hold - the person must actually be in that workspace, and the
 * workspace must be one they could sign into themselves.
 */
class CannotImpersonate extends DomainException
{
    public function __construct(private readonly string $why)
    {
        parent::__construct("Impersonation refused: {$why}.");
    }

    public function userMessage(): string
    {
        return "This account cannot be entered: {$this->why}.";
    }
}
