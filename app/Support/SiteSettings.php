<?php

namespace App\Support;

use App\Models\Setting;

/**
 * Well-known keys in the `settings` table, and the reading of them.
 *
 * The table is a bare key/value store that, until now, nothing in the
 * application actually read - it was written by the staff CRUD and consumed by
 * nobody. Naming the keys the CODE depends on in one place is what stops a
 * renamed row silently turning a feature off.
 */
final class SiteSettings
{
    /**
     * Where a customer is sent when they need a human.
     *
     * Deliberately one key rather than separate email and URL keys: what
     * support looks like differs per deployment - a mailbox, a help desk, a
     * chat widget - and the screens only ever need somewhere to point.
     */
    public const SUPPORT_URL = 'support_url';

    /**
     * A link to support, or null when nobody has configured one.
     *
     * Null is a real answer, not a failure: an unset destination means the
     * screens fall back to plain text rather than rendering a link to
     * nowhere. Spec section 6 tells a suspended customer to "contact support",
     * and a dead link there is worse than a sentence.
     */
    public static function supportUrl(): ?string
    {
        $value = trim((string) Setting::query()
            ->where('key', self::SUPPORT_URL)
            ->value('value'));

        if ($value === '') {
            return null;
        }

        /*
         * An address typed without a scheme is the likely case - staff are
         * setting "support destination", and most will type an email. Sending
         * that to href as-is produces a relative link to a page that does not
         * exist, so make it a mailto rather than punishing the typing.
         */
        if (! preg_match('#^[a-z][a-z0-9+.-]*:#i', $value) && str_contains($value, '@')) {
            return 'mailto:'.$value;
        }

        return $value;
    }
}
