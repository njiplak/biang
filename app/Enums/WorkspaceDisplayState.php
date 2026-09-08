<?php

namespace App\Enums;

/**
 * The single state spec section 6 shows to product, support and engineering.
 *
 * It is DERIVED, never stored: the underlying data is two independent axes
 * (BillingStatus and AccessStatus) plus over_limit_at. Section 6 itself gives
 * the game away by listing "Over limit" with billing "Unchanged" - a workspace
 * can be Active and Over limit at the same moment, which one column cannot say.
 */
enum WorkspaceDisplayState: string
{
    /*
     * Nobody is paying for this workspace: either they never started, or their
     * subscription ended. Both read the same to the customer - everything is
     * still here and still readable, and nothing can be changed until there is
     * a live plan again.
     */
    case Expired = 'expired';
    case Trialing = 'trialing';
    case Active = 'active';
    case PastDue = 'past_due';
    case Suspended = 'suspended';
    case OverLimit = 'over_limit';
    case Deleted = 'deleted';

    public function label(): string
    {
        return match ($this) {
            self::Expired => 'Read-only',
            self::Trialing => 'Trialing',
            self::Active => 'Active',
            self::PastDue => 'Past due',
            self::Suspended => 'Suspended',
            self::OverLimit => 'Over limit',
            self::Deleted => 'Deleted',
        };
    }
}
