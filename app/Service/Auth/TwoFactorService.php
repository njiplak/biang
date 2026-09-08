<?php

namespace App\Service\Auth;

use App\Contract\Auth\TwoFactorContract;
use App\Exceptions\Domain\TwoFactorNotPending;
use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PragmaRX\Google2FA\Google2FA;

class TwoFactorService implements TwoFactorContract
{
    /** Eight is enough to survive losing a phone; more is just more to store. */
    private const RECOVERY_CODE_COUNT = 8;

    public function __construct(private readonly Google2FA $google2fa) {}

    public function beginEnrolment(Authenticatable $user): void
    {
        DB::transaction(function () use ($user) {
            $user->forceFill([
                'two_factor_secret' => $this->google2fa->generateSecretKey(),
                'two_factor_recovery_codes' => $this->freshRecoveryCodes(),
                // Deliberately null: issuing a secret must not start gating a
                // login that nobody has proved they can pass yet.
                'two_factor_confirmed_at' => null,
            ])->save();
        });
    }

    public function qrCodeSvg(Authenticatable $user): string
    {
        $renderer = new ImageRenderer(new RendererStyle(232, 0), new SvgImageBackEnd);

        return (new Writer($renderer))->writeString($this->otpauthUri($user));
    }

    public function setupKey(Authenticatable $user): string
    {
        // Grouped in fours: this is read off a screen and typed into a phone by
        // anyone whose camera will not scan.
        return trim(chunk_split($this->pendingSecret($user), 4, ' '));
    }

    public function confirm(Authenticatable $user, string $code): bool
    {
        if (! $this->matchesTotp($user, $code)) {
            return false;
        }

        $user->forceFill(['two_factor_confirmed_at' => now()])->save();

        return true;
    }

    /**
     * Order matters: a TOTP code is six digits and a recovery code is not, so
     * they can never be confused - but checking TOTP first means the common case
     * never touches the recovery list.
     */
    public function verify(Authenticatable $user, string $code): bool
    {
        if ($this->matchesTotp($user, $code)) {
            return true;
        }

        return $this->spendRecoveryCode($user, $code);
    }

    /** @return string[] */
    public function regenerateRecoveryCodes(Authenticatable $user): array
    {
        $codes = $this->freshRecoveryCodes();

        $user->forceFill(['two_factor_recovery_codes' => $codes])->save();

        return $codes;
    }

    public function disable(Authenticatable $user): void
    {
        $user->forceFill([
            'two_factor_secret' => null,
            'two_factor_recovery_codes' => null,
            'two_factor_confirmed_at' => null,
        ])->save();
    }

    private function matchesTotp(Authenticatable $user, string $code): bool
    {
        $code = preg_replace('/\s+/', '', $code) ?? '';

        if (! preg_match('/^\d{6}$/', $code)) {
            return false;
        }

        // verifyKey, not verify: it accepts the adjacent window, which is what
        // makes a clock a few seconds out usable instead of mysteriously broken.
        return (bool) $this->google2fa->verifyKey($this->pendingSecret($user), $code);
    }

    /**
     * A recovery code is single use. Spending it inside a transaction with a
     * locked row is what stops the same code being redeemed twice by two
     * requests that arrive together.
     */
    private function spendRecoveryCode(Authenticatable $user, string $code): bool
    {
        $candidate = trim($code);

        if ($candidate === '') {
            return false;
        }

        return DB::transaction(function () use ($user, $candidate) {
            $fresh = $user->newQuery()->lockForUpdate()->find($user->getKey());

            $remaining = $fresh?->twoFactorRecoveryCodes() ?? [];

            $matched = null;
            foreach ($remaining as $stored) {
                if (hash_equals($stored, $candidate)) {
                    $matched = $stored;
                    break;
                }
            }

            if ($matched === null) {
                return false;
            }

            $fresh->forceFill([
                'two_factor_recovery_codes' => array_values(array_filter(
                    $remaining,
                    fn (string $stored) => $stored !== $matched,
                )),
            ])->save();

            $user->setAttribute('two_factor_recovery_codes', $fresh->two_factor_recovery_codes);

            return true;
        });
    }

    private function pendingSecret(Authenticatable $user): string
    {
        $secret = $user->two_factor_secret;

        if ($secret === null) {
            throw new TwoFactorNotPending;
        }

        return $secret;
    }

    private function otpauthUri(Authenticatable $user): string
    {
        return $this->google2fa->getQRCodeUrl(
            config('app.name'),
            $user->email,
            $this->pendingSecret($user),
        );
    }

    /** @return string[] */
    private function freshRecoveryCodes(): array
    {
        return collect(range(1, self::RECOVERY_CODE_COUNT))
            ->map(fn () => Str::lower(Str::random(10)).'-'.Str::lower(Str::random(10)))
            ->all();
    }
}
