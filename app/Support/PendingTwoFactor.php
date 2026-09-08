<?php

namespace App\Support;

use Illuminate\Http\Request;

/**
 * The gap between a correct password and a started session.
 *
 * What is held here is NOT an authenticated session: it is a note that somebody
 * proved a password and still owes a second factor. It carries an id, never a
 * password, and it expires - an abandoned challenge left open indefinitely would
 * turn a shared computer into a standing invitation.
 *
 * Keyed per guard because both can be live in one session at once: impersonation
 * needs the staff member to stay signed in as staff while acting as a customer.
 */
class PendingTwoFactor
{
    private const TTL_SECONDS = 300;

    public static function remember(Request $request, string $guard, int|string $id, bool $rememberMe): void
    {
        $request->session()->put(self::key($guard), [
            'id' => $id,
            'remember' => $rememberMe,
            'at' => now()->timestamp,
        ]);
    }

    /** @return array{id: int|string, remember: bool}|null */
    public static function retrieve(Request $request, string $guard): ?array
    {
        $pending = $request->session()->get(self::key($guard));

        if (! is_array($pending) || ! isset($pending['id'], $pending['at'])) {
            return null;
        }

        if (now()->timestamp - (int) $pending['at'] > self::TTL_SECONDS) {
            self::forget($request, $guard);

            return null;
        }

        return ['id' => $pending['id'], 'remember' => (bool) ($pending['remember'] ?? false)];
    }

    public static function forget(Request $request, string $guard): void
    {
        $request->session()->forget(self::key($guard));
    }

    private static function key(string $guard): string
    {
        return "two_factor.pending.{$guard}";
    }
}
