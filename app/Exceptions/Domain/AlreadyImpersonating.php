<?php

namespace App\Exceptions\Domain;

use App\Models\AdminUser;

/**
 * A partial unique index allows one open impersonation session per staff member,
 * and that is not an arbitrary limit: audit_logs links an action to the session
 * that authorised it, so two open sessions would make "which customer were we
 * acting as" unanswerable.
 */
class AlreadyImpersonating extends DomainException
{
    public function __construct(public readonly AdminUser $admin)
    {
        parent::__construct("Admin {$admin->id} already has an open impersonation session.");
    }

    public function userMessage(): string
    {
        return 'You are already inside a customer account. End that session before starting another.';
    }
}
