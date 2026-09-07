import { Head, router, usePage } from '@inertiajs/react';
import { useState } from 'react';
import { CreditCard, ExternalLink } from 'lucide-react';
import AppLayout from '@/layouts/app-layout';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';

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
        // false on the free tier, which never reaches the provider at all
        has_payment_account: boolean;
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
    // section 12: one trial per person, ever - so this is about the viewer
    can_start_trial: boolean;
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
    addons,
    can_start_trial,
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

                <CurrentPlan
                    subscription={subscription}
                    hasPaymentAccount={workspace.has_payment_account}
                />

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

                <Plans
                    plans={plans}
                    subscription={subscription}
                    canStartTrial={can_start_trial}
                />

                <Addons
                    addons={addons}
                    hasSubscription={subscription !== null}
                />
            </div>
        </AppLayout>
    );
}

/**
 * Section 5: "buttons to ... open the payment provider's page for cards and
 * invoices". Section 8 puts the card and the invoice document on Dodo's side,
 * so this link is the only way a customer can change what we charge - and
 * section 9's past-due banner sends them here expecting exactly that.
 */
function CurrentPlan({
    subscription,
    hasPaymentAccount,
}: {
    subscription: Props['subscription'];
    hasPaymentAccount: boolean;
}) {
    return (
        <section className="flex flex-col gap-2">
            <h2 className="text-sm font-medium text-muted-foreground">
                Current plan
            </h2>

            {subscription ? (
                <div className="flex flex-wrap items-center justify-between gap-2 border-t border-border py-3 text-sm">
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
                    <span className="flex items-center gap-1">
                        <Button
                            variant="ghost"
                            size="sm"
                            onClick={() => router.delete('/billing')}
                        >
                            Cancel
                        </Button>
                    </span>
                </div>
            ) : (
                <p className="border-t border-border py-3 text-sm text-muted-foreground">
                    Free tier. No card on file.
                </p>
            )}

            {hasPaymentAccount && (
                <div className="flex flex-wrap items-center justify-between gap-2 rounded-md border border-border bg-muted/40 px-3 py-2 text-sm">
                    <span className="flex items-center gap-2">
                        <CreditCard className="size-4 shrink-0" />
                        Your card and every invoice live with our payment
                        provider.
                    </span>
                    {/* A full page load, not an Inertia visit: the destination
                        is another origin, and the link is minted per click. */}
                    <Button variant="outline" size="sm" asChild>
                        <a href="/billing/portal">
                            Manage cards &amp; invoices
                            <ExternalLink className="ml-1 size-3.5" />
                        </a>
                    </Button>
                </div>
            )}
        </section>
    );
}

/**
 * Three different asks, and confusing them is what made this page unusable:
 * switching an existing subscription is our own PUT (section 7 keeps plan
 * changes inside the app), whereas a first purchase has to go through Dodo's
 * checkout because that is where the card is entered.
 */
function Plans({
    plans,
    subscription,
    canStartTrial,
}: {
    plans: Plan[];
    subscription: Props['subscription'];
    canStartTrial: boolean;
}) {
    return (
        <section className="flex flex-col gap-2">
            <h2 className="text-sm font-medium text-muted-foreground">Plans</h2>

            {plans.map((plan) => (
                <div
                    key={plan.code}
                    className="flex flex-wrap items-center justify-between gap-2 border-t border-border py-3 text-sm"
                >
                    <span>{plan.name}</span>

                    <span className="flex flex-wrap items-center gap-2">
                        {plan.prices.map((price) => (
                            <Button
                                key={price.id}
                                variant="outline"
                                size="sm"
                                onClick={() =>
                                    subscription
                                        ? router.put(
                                              '/billing/plan',
                                              { plan_price_id: price.id },
                                              { preserveScroll: true },
                                          )
                                        : router.post(
                                              '/billing/checkout',
                                              { plan_price_id: price.id },
                                              { preserveScroll: true },
                                          )
                                }
                            >
                                {subscription ? 'Switch to' : 'Subscribe'}{' '}
                                {money(price.amount_minor, price.currency)}/
                                {price.interval}
                            </Button>
                        ))}

                        {/* Section 4: a card is taken up front and it
                            auto-charges on day 15, so this is only offered to
                            someone who has never spent their one trial. */}
                        {!plan.is_free &&
                            !subscription &&
                            canStartTrial &&
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
    );
}

/**
 * Section 5: "buttons to change plan, BUY ADD-ONS, or open the payment
 * provider's page". The server has always sent these; nothing rendered them,
 * so the only way to buy a seat was the offer inside the invite flow.
 */
function Addons({
    addons,
    hasSubscription,
}: {
    addons: Props['addons'];
    hasSubscription: boolean;
}) {
    // Section 12: nothing to attach a paid add-on to on the free tier.
    if (!hasSubscription) {
        return null;
    }

    if (addons.owned.length === 0 && addons.available.length === 0) {
        return null;
    }

    return (
        <section className="flex flex-col gap-2">
            <h2 className="text-sm font-medium text-muted-foreground">
                Add-ons
            </h2>

            {addons.owned.map((addon) => (
                <OwnedAddonRow key={addon.addon_id} addon={addon} />
            ))}

            {addons.available.map((addon) => (
                <div
                    key={addon.addon_id}
                    className="flex flex-wrap items-center justify-between gap-2 border-t border-border py-3 text-sm"
                >
                    <span className="flex flex-col">
                        <span>{addon.name}</span>
                        <span className="text-xs text-muted-foreground">
                            {money(addon.amount_minor, addon.currency)}/
                            {addon.interval}
                            {addon.kind === 'metered' && ' · billed on usage'}
                        </span>
                    </span>
                    <Button
                        variant="outline"
                        size="sm"
                        onClick={() =>
                            router.post(
                                '/billing/addons',
                                { addon_price_id: addon.price_id, quantity: 1 },
                                { preserveScroll: true },
                            )
                        }
                    >
                        Add
                    </Button>
                </div>
            ))}
        </section>
    );
}

/**
 * Section 4: only a QUANTITY add-on has a number to change. An unlock is on or
 * off and a metered one is billed on what was used, so offering either a
 * quantity box would be offering a control that does nothing.
 */
function OwnedAddonRow({ addon }: { addon: OwnedAddon }) {
    const [quantity, setQuantity] = useState(String(addon.quantity));

    /*
     * Whole numbers of at least one, checked here rather than left to the
     * input's own min/max - those do not stop a typed value reaching onClick.
     * An emptied box reads as Number('') === 0, and zero is how this route
     * REMOVES an add-on, so without this a cleared field plus Update silently
     * cancels the thing they were trying to buy more of. Removing is a
     * deliberate button, never a side effect of an empty box.
     */
    const wanted = Number(quantity);
    const valid = Number.isInteger(wanted) && wanted >= 1 && wanted <= 1000;
    const changed = valid && wanted !== addon.quantity;

    const update = (value: number) =>
        router.put(
            '/billing/addons',
            { addon_id: addon.addon_id, quantity: value },
            { preserveScroll: true },
        );

    return (
        <div className="flex flex-wrap items-center justify-between gap-2 border-t border-border py-3 text-sm">
            <span className="flex flex-col">
                <span>{addon.name}</span>
                <span className="text-xs text-muted-foreground">
                    {addon.kind === 'quantity'
                        ? `${addon.quantity} included`
                        : 'Active'}
                </span>
            </span>

            <span className="flex items-center gap-2">
                {addon.kind === 'quantity' && (
                    <>
                        <Input
                            type="number"
                            min={1}
                            max={1000}
                            value={quantity}
                            onChange={(e) => setQuantity(e.target.value)}
                            className="h-8 w-20"
                            aria-label={`${addon.name} quantity`}
                        />
                        {/* Section 7: a reduction that would strand people in
                            use is refused by the service, which says how many
                            to remove first. */}
                        <Button
                            size="sm"
                            disabled={!changed}
                            onClick={() => update(wanted)}
                        >
                            Update
                        </Button>
                    </>
                )}
                {/* Zero removes it entirely - the same route, per
                    AddonQuantityRequest. */}
                <Button variant="ghost" size="sm" onClick={() => update(0)}>
                    Remove
                </Button>
            </span>
        </div>
    );
}
