<?php

namespace App\Enums;

/**
 * Spec section 3. Fixed set, defined by us, not editable by customers - which is
 * why this is an enum on the membership row rather than a spatie team role.
 */
enum WorkspaceRole: string
{
    case Owner = 'owner';
    case Admin = 'admin';
    case BillingManager = 'billing_manager';
    case Member = 'member';
    case Viewer = 'viewer';

    public function label(): string
    {
        return match ($this) {
            self::Owner => 'Owner',
            self::Admin => 'Admin',
            self::BillingManager => 'Billing manager',
            self::Member => 'Member',
            self::Viewer => 'Viewer',
        };
    }

    /** Owner only: transfer ownership, close the workspace. */
    public function canAdministerWorkspace(): bool
    {
        return $this === self::Owner;
    }

    /** Owner and Admin. Admin explicitly cannot see billing. */
    public function canManageMembers(): bool
    {
        return in_array($this, [self::Owner, self::Admin], true);
    }

    /** Owner and Billing manager. Billing manager explicitly cannot manage people. */
    public function canManageBilling(): bool
    {
        return in_array($this, [self::Owner, self::BillingManager], true);
    }

    /** Viewer is read-only; billing manager is billing-only, not a product user. */
    public function canWrite(): bool
    {
        return in_array($this, [self::Owner, self::Admin, self::Member], true);
    }

    /**
     * Section 9: only the owner and billing managers hear about card problems.
     * Regular members should not learn about their company's billing from us.
     */
    public function receivesBillingNotifications(): bool
    {
        return $this->canManageBilling();
    }
}
