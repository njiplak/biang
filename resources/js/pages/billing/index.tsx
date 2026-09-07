import { Head, router, usePage } from '@inertiajs/react';
import AppLayout from '@/layouts/app-layout';
import { Button } from '@/components/ui/button';

type Price = {
    id: number;
    interval: string;
    currency: string;
    amount_minor: number;
};
type Plan = { code: string; name: string; is_free: boolean; prices: Price[] };

type OwnedAddon = {
    addon_id: number;
    key: string;
    name: string;
    kind: string;
    quantity: number;
};
type AvailableAddon = OwnedAddon & {
    price_id: number;
    amount_minor: number;
    currency: string;
    interval: string;
};

type Props = {
    workspace: {
        name: string;
        state: string;
        state_label: string;
        over_limit_features: string[] | null;
    };
    subscription: {
        plan: string;
        status: string;
        billing_source: string;
        trial_ends_at: string | null;
        current_period_end: string | null;
        amount_minor: number;
        currency: string;
        interval: string;
    } | null;
    // limit is null when unlimited
    usage: {
        feature: string;
        used: number;
        limit: number | null;
        source: string;
    }[];
    plans: Plan[];
    addons: { available: AvailableAddon[]; owned: OwnedAddon[] };
};

const money = (minor: number, currency: string) =>
    new Intl.NumberFormat(undefined, { style: 'currency', currency }).format(
        minor / 100,
    );

export default function BillingIndex({
    workspace,
    subscription,
    usage,
    plans,
}: Props) {
    const errors = usePage().props.errors as Record<string, string> | undefined;

    return (
        <AppLayout>
            <Head title="Billing" />

            <div className="flex flex-col gap-8 p-6">
                <div>
                    <h1 className="text-xl font-semibold tracking-tight">
                        Billing
                    </h1>
                    <p className="text-sm text-muted-foreground">
                        {workspace.name} · {workspace.state_label}
                    </p>
                </div>

                {/* Section 7: name the specific limits, so the upgrade that
                    removes the block is obvious. */}
                {workspace.over_limit_features &&
                    workspace.over_limit_features.length > 0 && (
                        <p className="rounded-md border border-border p-3 text-sm">
                            This workspace is over its limit on{' '}
                            <strong>
                                {workspace.over_limit_features.join(', ')}
                            </strong>
                            . Writing is paused until it is back within the
                            plan.
                        </p>
                    )}

                {errors?.errors && (
                    <p className="text-sm text-destructive">{errors.errors}</p>
                )}

                <section className="flex flex-col gap-2">
                    <h2 className="text-sm font-medium text-muted-foreground">
                        Current plan
                    </h2>
                    {subscription ? (
                        <div className="flex items-center justify-between border-t border-border py-3 text-sm">
                            <span>
                                {subscription.plan} ·{' '}
                                {money(
                                    subscription.amount_minor,
                                    subscription.currency,
                                )}
                                /{subscription.interval}
                                {subscription.billing_source === 'manual' &&
                                    ' · granted by our team'}
                            </span>
                            <Button
                                variant="ghost"
                                size="sm"
                                onClick={() => router.delete('/billing')}
                            >
                                Cancel
                            </Button>
                        </div>
                    ) : (
                        <p className="border-t border-border py-3 text-sm text-muted-foreground">
                            Free tier. No card on file.
                        </p>
                    )}
                </section>

                {/* Section 5: usage against EVERY limit, not just a breached one. */}
                <section className="flex flex-col gap-2">
                    <h2 className="text-sm font-medium text-muted-foreground">
                        Usage
                    </h2>
                    <table className="w-full text-sm">
                        <tbody>
                            {usage.map((row) => (
                                <tr
                                    key={row.feature}
                                    className="border-t border-border"
                                >
                                    <td className="py-2">{row.feature}</td>
                                    <td className="py-2 text-right text-muted-foreground">
                                        {row.used} / {row.limit ?? 'unlimited'}
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </section>

                <section className="flex flex-col gap-2">
                    <h2 className="text-sm font-medium text-muted-foreground">
                        Plans
                    </h2>
                    {plans.map((plan) => (
                        <div
                            key={plan.code}
                            className="flex items-center justify-between border-t border-border py-3 text-sm"
                        >
                            <span>{plan.name}</span>
                            <span className="flex items-center gap-2">
                                {plan.prices.map((price) => (
                                    <Button
                                        key={price.id}
                                        variant="outline"
                                        size="sm"
                                        onClick={() =>
                                            router.put(
                                                '/billing/plan',
                                                { plan_price_id: price.id },
                                                { preserveScroll: true },
                                            )
                                        }
                                    >
                                        {money(
                                            price.amount_minor,
                                            price.currency,
                                        )}
                                        /{price.interval}
                                    </Button>
                                ))}
                                {!plan.is_free &&
                                    !subscription &&
                                    plan.prices[0] && (
                                        <Button
                                            size="sm"
                                            onClick={() =>
                                                router.post(
                                                    '/billing/trial',
                                                    {
                                                        plan_price_id:
                                                            plan.prices[0].id,
                                                    },
                                                    { preserveScroll: true },
                                                )
                                            }
                                        >
                                            Start trial
                                        </Button>
                                    )}
                            </span>
                        </div>
                    ))}
                </section>
            </div>
        </AppLayout>
    );
}
