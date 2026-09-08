<?php

namespace App\Models\Concerns;

/**
 * Shared by User and AdminUser: section 3 keeps the two worlds apart, but this
 * is the same mechanism on both, and one copy means one place to get it wrong.
 *
 * The columns are cast `encrypted` by each model, so a database backup does not
 * hand over the seeds needed to mint working codes.
 */
trait TwoFactorAuthenticatable
{
    /**
     * A secret alone is not enough. Enrolment writes the secret first and only
     * stamps `two_factor_confirmed_at` once a real code has been entered - so an
     * abandoned setup can never lock somebody out of their own account.
     */
    public function hasTwoFactorEnabled(): bool
    {
        return $this->two_factor_secret !== null
            && $this->two_factor_confirmed_at !== null;
    }

    /** Mid-enrolment: a secret issued but not yet proved. */
    public function hasPendingTwoFactor(): bool
    {
        return $this->two_factor_secret !== null
            && $this->two_factor_confirmed_at === null;
    }

    /** @return string[] */
    public function twoFactorRecoveryCodes(): array
    {
        return $this->two_factor_recovery_codes ?? [];
    }
}
