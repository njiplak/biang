<?php

namespace App\Contract\Auth;

use Illuminate\Contracts\Auth\Authenticatable;

/**
 * TOTP (RFC 6238) enrolment and verification, shared by both guards.
 *
 * Opt-in: nothing here is ever forced on an account. What it does guarantee is
 * that once an account HAS confirmed a second factor, no password alone gets in.
 */
interface TwoFactorContract
{
    /**
     * Issues a secret and a set of recovery codes without enabling anything -
     * `two_factor_confirmed_at` stays null until confirm() is called, so a setup
     * somebody walks away from cannot lock them out.
     */
    public function beginEnrolment(Authenticatable $user): void;

    /** The otpauth:// URI for the pending secret, as an inline SVG QR code. */
    public function qrCodeSvg(Authenticatable $user): string;

    /** The pending secret in the grouped form people type in by hand. */
    public function setupKey(Authenticatable $user): string;

    /** True and stamps two_factor_confirmed_at if the code matches. */
    public function confirm(Authenticatable $user, string $code): bool;

    /** A valid TOTP code, or an unused recovery code (which is then spent). */
    public function verify(Authenticatable $user, string $code): bool;

    /** @return string[] the new codes */
    public function regenerateRecoveryCodes(Authenticatable $user): array;

    public function disable(Authenticatable $user): void;
}
