import { usePage } from '@inertiajs/react';
import type { SharedData } from '@/types';

export type Entitlement = {
    // false when the current plan does not include the feature at all
    included: boolean;
    // null means unlimited
    limit: number | null;
    // whether `used` more would still fit - the same rule the server applies
    allows: (used: number) => boolean;
};

/**
 * What the current workspace's plan grants for one feature, read from the
 * shared `tenancy.current.entitlements`. For deciding what to show; the server
 * enforces the same limit (EntitlementContract::assertAllows) regardless.
 */
export function useEntitlement(featureKey: string): Entitlement {
    const entitlements =
        usePage<SharedData>().props.tenancy?.current?.entitlements ?? {};
    const included = Object.prototype.hasOwnProperty.call(
        entitlements,
        featureKey,
    );
    const limit = included ? (entitlements[featureKey] ?? null) : 0;

    return {
        included,
        limit,
        allows: (used: number) => included && (limit === null || used <= limit),
    };
}
