<?php

namespace App\Exceptions\Domain;

/**
 * The console can suspend workspaces, comp plans and enter customer accounts.
 * Two ways to lose control of it permanently, both cheap to do by accident:
 *
 *  - deactivating or deleting yourself while you are the one holding the door
 *  - removing the last super-admin, after which nobody can grant the role back
 *
 * Neither is recoverable without a deploy, which is exactly what this screen
 * exists to avoid needing.
 */
class StaffLockout extends DomainException
{
    public function __construct(private readonly string $why)
    {
        parent::__construct("Refused to change staff access: {$why}.");
    }

    public function userMessage(): string
    {
        return ucfirst($this->why).'.';
    }
}
