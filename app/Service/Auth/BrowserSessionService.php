<?php

namespace App\Service\Auth;

use App\Contract\Auth\BrowserSessionContract;
use App\Http\Controllers\Admin\ImpersonationController;
use App\Models\User;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * The devices a person is signed in on, and the way to end the others.
 *
 * Section 3 is the reason this is not a plain query on `sessions`. Staff and
 * customers share one session on purpose - config/auth.php says so, because
 * impersonation needs a staff member to stay authenticated as staff while
 * acting as the customer. Laravel stamps `user_id` from the DEFAULT guard,
 * which is `web`, so a staff member impersonating somebody writes a row
 * carrying the CUSTOMER's id.
 *
 * Left alone, this screen would therefore show a support session as one of the
 * customer's own devices: their own IP address and browser handed to the
 * customer they are helping, and a "log out other devices" button that ends a
 * support session mid-ticket.
 */
class BrowserSessionService implements BrowserSessionContract
{
    /**
     * @return array<int, array<string, mixed>>
     */
    public function forUser(User $user, string $currentSessionId): array
    {
        if (! $this->isDatabaseDriver()) {
            return [];
        }

        return $this->rowsFor($user)
            ->reject(fn (object $row) => $this->isImpersonation($row))
            ->map(fn (object $row) => [
                /*
                 * No session id reaches the browser. It is the bearer token
                 * for that session, so a page that prints it turns any XSS
                 * into account takeover on every listed device. This is also
                 * why there is no per-row revoke - the whole set goes at once.
                 */
                'ip_address' => $row->ip_address,
                'user_agent' => $row->user_agent,
                'last_active_at' => $row->last_activity,
                'is_current' => $row->id === $currentSessionId,
            ])
            ->values()
            ->all();
    }

    /**
     * End every session for this person except the one asking.
     *
     * The rows are deleted rather than going through
     * SessionGuard::logoutOtherDevices(), which works by rehashing the
     * password and leaning on the AuthenticateSession middleware to notice.
     * That middleware is not in this application's stack, so that call would
     * report success and leave every other session signed in. Deleting the row
     * the database driver reads is what actually ends a session here.
     */
    public function logOutOthers(User $user, string $currentSessionId): int
    {
        if (! $this->isDatabaseDriver()) {
            return 0;
        }

        $doomed = $this->rowsFor($user)
            // An impersonation session is not this customer's to end.
            ->reject(fn (object $row) => $this->isImpersonation($row))
            ->reject(fn (object $row) => $row->id === $currentSessionId)
            ->pluck('id')
            ->all();

        if ($doomed === []) {
            return 0;
        }

        return DB::table(config('session.table', 'sessions'))
            ->whereIn('id', $doomed)
            ->delete();
    }

    /**
     * Only the database driver keeps sessions somewhere we can read or delete.
     *
     * Returning nothing rather than guessing: the controller turns this into a
     * plain "not available" on the page instead of an empty list that looks
     * like the person is signed in nowhere.
     */
    public function isDatabaseDriver(): bool
    {
        return config('session.driver') === 'database';
    }

    /** @return \Illuminate\Support\Collection<int, object> */
    private function rowsFor(User $user)
    {
        return DB::table(config('session.table', 'sessions'))
            ->where('user_id', $user->getAuthIdentifier())
            ->orderByDesc('last_activity')
            ->get();
    }

    /**
     * Is this row a staff member impersonating, rather than the customer?
     *
     * There is no column to join on - impersonation_sessions records who and
     * why, not which session - so the only witness is the key the
     * impersonation flow puts in the session itself.
     *
     * Fails CLOSED. A payload we cannot read is treated as impersonation and
     * hidden, because the two mistakes are not equal: hiding one of the
     * customer's own devices is a missing row, while showing a support session
     * hands them a staff member's IP address.
     */
    private function isImpersonation(object $row): bool
    {
        $attributes = $this->decode($row->payload);

        if ($attributes === null) {
            return true;
        }

        return array_key_exists(ImpersonationController::SESSION_KEY, $attributes);
    }

    /**
     * @return array<string, mixed>|null null when the payload cannot be read
     */
    private function decode(?string $payload): ?array
    {
        if ($payload === null || $payload === '') {
            return null;
        }

        try {
            if (config('session.encrypt')) {
                $payload = Crypt::decryptString($payload);
            }

            $decoded = base64_decode($payload, true);

            if ($decoded === false) {
                return null;
            }

            /*
             * allowed_classes: false. Session payloads are attacker-influenced
             * data - anything a person can put in their own session ends up
             * here - and rebuilding arbitrary objects out of it to read one
             * array key would be an object injection gadget for free.
             */
            $attributes = @unserialize($decoded, ['allowed_classes' => false]);

            return is_array($attributes) ? $attributes : null;
        } catch (Throwable) {
            return null;
        }
    }
}
