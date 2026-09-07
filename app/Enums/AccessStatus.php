<?php

namespace App\Enums;

/**
 * The access axis of spec section 6. Moved by the usage checker, by staff, and
 * by the owner - never by a payment webhook. Kept separate from BillingStatus
 * because section 6 lists "Over limit" with billing "Unchanged", meaning a
 * workspace can be Active and Over limit at the same time.
 */
enum AccessStatus: string
{
    case Active = 'active';
    case ReadOnly = 'read_only';
    case Suspended = 'suspended';
    case Deleted = 'deleted';

    public function canLogIn(): bool
    {
        return $this !== self::Deleted;
    }

    public function canRead(): bool
    {
        return $this !== self::Deleted;
    }

    public function canWrite(): bool
    {
        return $this === self::Active;
    }

    /** Section 6: a suspended workspace can still export everything. */
    public function canExport(): bool
    {
        return $this !== self::Deleted;
    }
}
