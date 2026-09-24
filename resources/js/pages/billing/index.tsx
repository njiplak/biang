import { Head, router, usePage } from '@inertiajs/react';
import { useState } from 'react';
import { CreditCard, ExternalLink } from 'lucide-react';
import AppLayout from '@/layouts/app-layout';
import type { SharedData } from '@/types';
import { CancelDialog } from './cancel-dialog';
import { SwitchPlanDialog } from './switch-plan-dialog';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';

type Price = {
    id: number;
    interval: string;
    currency: string;
    amount_minor: number;
};
// Every plan on this page carries a price: the floor plan is not public and
// never reaches it.
type Plan = {
    code: string;
    name: string;
    is_current: boolean;
    // How many people have to go before this plan is buyable. 0 means it fits.
    seat_overage: number;
    prices: Price[];
};

type Invoice = {
    id: number;
    number: string | null;
    status: string;
    currency: string;
    total_minor: number;
    tax_minor: number;
    issued_at: string | null;
    hosted_url: string | null;
};

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
        // false until they have bought something: a workspace that has never
        // paid has no account with the provider at all
        has_payment_account: boolean;
    };
    subscription: {
        plan: string;
        status: string;
        billing_source: string;
        trial_ends_at: string | null;
        current_period_end: string | null;
        // cancelled at the period end; access runs until paid_through
        cancel_at_period_end: boolean;
        // when cancelling would take effect; null means immediately
        paid_through: string | null;
        // a downgrade waiting for the renewal
        scheduled_change: {
            plan: string;
            price_id: number;
            amount_minor: number;
            currency: string;
            interval: string;
            effective_at: string;
        } | null;
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
    // Section 8: we keep the summary, the document stays with the provider.
    invoices: Invoice[];
    addons: { available: AvailableAddon[]; owned: OwnedAddon[] };
    // section 12: one trial per person, ever - so this is about the viewer
    can_start_trial: boolean;
    // Section 11: the plan carried here from the marketing site, if any.
    preselected_plan: string | null;
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
    invoices,
    addons,
    can_start_trial,
    preselected_plan,
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
                    preselected={preselected_plan}
                />

                <Invoices invoices={invoices} />

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
    const supportUrl = usePage<SharedData>().props.support?.url ?? null;
    const [resuming, setResuming] = useState(false);

    const resume = () =>
        router.post(
            '/billing/resume',
            {},
            {
                preserveScroll: true,
                onStart: () => setResuming(true),
                onFinish: () => setResuming(false),
            },
        );

    return (
        <section className="flex flex-col gap-2">
            <h2 className="text-sm font-medium text-muted-foreground">
                Current plan
            </h2>

            {subscription ? (
                <div className="flex flex-col gap-1 border-t border-border py-3 text-sm">
                    <div className="flex flex-wrap items-center justify-between gap-2">
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
                            {subscription.cancel_at_period_end ? (
                                /* Only while there is time left to keep. */
                                subscription.paid_through && (
                                    <Button
                                        size="sm"
                                        disabled={resuming}
                                        onClick={resume}
                                    >
                                        Resume subscription
                                    </Button>
                                )
                            ) : (
                                /* Never a bare button: it says what happens
                                   and when before anything is cancelled. */
                                <CancelDialog
                                    paidThrough={subscription.paid_through}
                                />
                            )}
                        </span>
                    </div>
                    {/* "When will I be charged, and how much?" answered on
                        the page instead of in a support ticket. */}
                    <p className="text-muted-foreground">
                        <RenewalLine subscription={subscription} />
                    </p>
                    {subscription.scheduled_change && (
                        <div className="flex flex-wrap items-center justify-between gap-2 text-muted-foreground">
                            <span>
                                Switching to{' '}
                                <strong>
                                    {subscription.scheduled_change.plan}
                                </strong>{' '}
                                (
                                {money(
                                    subscription.scheduled_change.amount_minor,
                                    subscription.scheduled_change.currency,
                                )}
                                /{subscription.scheduled_change.interval}) on{' '}
                                {date(subscription.scheduled_change.effective_at)}
                                . Nothing is charged until then.
                            </span>
                            <Button
                                variant="outline"
                                size="sm"
                                onClick={() =>
                                    router.delete('/billing/plan/scheduled', {
                                        preserveScroll: true,
                                    })
                                }
                            >
                                Keep current plan
                            </Button>
                        </div>
                    )}
                </div>
            ) : (
                <p className="border-t border-border py-3 text-sm text-muted-foreground">
                    No active subscription. This workspace is read-only — your
                    data is all still here. Choose a plan below to start making
                    changes again.
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

            {supportUrl && (
                <p className="text-sm text-muted-foreground">
                    Questions about your plan or a charge?{' '}
                    <a
                        href={supportUrl}
                        className="underline underline-offset-4"
                        target="_blank"
                        rel="noreferrer"
                    >
                        Contact us
                    </a>
                </p>
            )}
        </section>
    );
}

const date = (value: string) => new Date(value).toLocaleDateString();

/** One sentence: what happens next, on what date, for how much. */
function RenewalLine({
    subscription,
}: {
    subscription: NonNullable<Props['subscription']>;
}) {
    const price = `${money(subscription.amount_minor, subscription.currency)}/${subscription.interval}`;

    if (subscription.cancel_at_period_end) {
        // No date left means the period has run out and the provider's
        // confirmation has not reached us yet.
        return subscription.paid_through ? (
            <>
                Cancelled. Everything keeps working until{' '}
                {date(subscription.paid_through)}, then the workspace becomes
                read-only. You will not be charged again.
            </>
        ) : (
            <>Cancelled and ending now. You will not be charged again.</>
        );
    }

    if (subscription.billing_source === 'manual') {
        return <>Granted by our team — you are not charged for it.</>;
    }

    if (subscription.status === 'past_due') {
        return (
            <>
                The last payment did not go through. Update your card to keep
                this plan.
            </>
        );
    }

    if (subscription.status === 'trialing' && subscription.trial_ends_at) {
        return (
            <>
                Free trial until {date(subscription.trial_ends_at)}. Your card
                is then charged {price} automatically, plus any add-ons and tax.
            </>
        );
    }

    if (subscription.current_period_end) {
        return (
            <>
                Renews on {date(subscription.current_period_end)} at {price},
                plus any add-ons and tax.
            </>
        );
    }

    return null;
}

/**
 * Three different asks, and confusing them is what made this page unusable:
 * switching an existing subscription is our own PUT (section 7 keeps plan
 * changes inside the app), whereas a first purchase has to go through Dodo's
 * checkout because that is where the card is entered.
 *
 * The interval toggle is here rather than one button per price for a
 * commercial reason: annual is the cheaper-per-month option and the whole
 * point of offering it, and a row of four buttons hides that behind arithmetic
 * the customer has to do themselves.
 */
function Plans({
    plans,
    subscription,
    canStartTrial,
    preselected,
}: {
    plans: Plan[];
    subscription: Props['subscription'];
    canStartTrial: boolean;
    preselected: string | null;
}) {
    const intervals = Array.from(
        new Set(plans.flatMap((plan) => plan.prices.map((p) => p.interval))),
    );

    // Whatever they are already paying on, so the page opens on the terms they
    // actually have rather than resetting them to monthly.
    const [interval, setInterval] = useState(
        subscription?.interval ??
            (intervals.includes('year') ? 'year' : intervals[0]),
    );

    if (plans.length === 0) return null;

    return (
        <section className="flex flex-col gap-2">
            <div className="flex flex-wrap items-center justify-between gap-2">
                <h2 className="text-sm font-medium text-muted-foreground">
                    Plans
                </h2>

                {intervals.length > 1 && (
                    <div className="flex items-center gap-1 rounded-md border border-border p-0.5">
                        {intervals.map((option) => (
                            <button
                                key={option}
                                type="button"
                                onClick={() => setInterval(option)}
                                className={`rounded px-2 py-1 text-xs capitalize ${
                                    interval === option
                                        ? 'bg-muted font-medium'
                                        : 'text-muted-foreground'
                                }`}
                            >
                                {option === 'year' ? 'Annual' : 'Monthly'}
                            </button>
                        ))}
                    </div>
                )}
            </div>

            {plans.map((plan) => {
                const price =
                    plan.prices.find((p) => p.interval === interval) ??
                    plan.prices[0];

                if (!price) return null;

                // Section 7: a plan that cannot hold the people already here is
                // not an offer. Said before it is clicked, not after the charge.
                const blocked = plan.seat_overage > 0;
                const scheduled = plan.prices.some(
                    (p) => p.id === subscription?.scheduled_change?.price_id,
                );

                return (
                    <div
                        key={plan.code}
                        className={`flex flex-wrap items-center justify-between gap-2 border-t border-border py-3 text-sm ${
                            plan.code === preselected && !plan.is_current
                                ? 'bg-muted/40'
                                : ''
                        }`}
                    >
                        <span className="flex flex-col gap-0.5">
                            <span className="flex items-center gap-2">
                                {plan.name}
                                {plan.is_current && (
                                    <span className="rounded-full border border-border px-2 py-0.5 text-xs text-muted-foreground">
                                        Current plan
                                    </span>
                                )}
                                {scheduled && (
                                    <span className="rounded-full border border-border px-2 py-0.5 text-xs text-muted-foreground">
                                        Starts at renewal
                                    </span>
                                )}
                                {plan.code === preselected &&
                                    !plan.is_current && (
                                        <span className="rounded-full border border-border px-2 py-0.5 text-xs text-muted-foreground">
                                            The plan you picked
                                        </span>
                                    )}
                            </span>
                            {blocked && (
                                <span className="text-xs text-destructive">
                                    Remove {plan.seat_overage} member
                                    {plan.seat_overage === 1 ? '' : 's'} before
                                    choosing this plan — nothing is deleted by
                                    doing so.
                                </span>
                            )}
                        </span>

                        <span className="flex flex-wrap items-center gap-2">
                            <span className="text-muted-foreground">
                                {money(price.amount_minor, price.currency)}/
                                {price.interval}
                            </span>

                            {!plan.is_current &&
                                !scheduled &&
                                (subscription ? (
                                    /* Prorated by Dodo, so the number has to
                                       come from them before it is charged. */
                                    <SwitchPlanDialog
                                        planName={plan.name}
                                        priceId={price.id}
                                        disabled={blocked}
                                    />
                                ) : (
                                    <Button
                                        variant="outline"
                                        size="sm"
                                        disabled={blocked}
                                        onClick={() =>
                                            router.post(
                                                '/billing/checkout',
                                                { plan_price_id: price.id },
                                                { preserveScroll: true },
                                            )
                                        }
                                    >
                                        Subscribe
                                    </Button>
                                ))}

                            {/* Section 4: a card is taken up front and it
                                auto-charges on day 15, so this is only offered
                                to someone who has never spent their one trial. */}
                            {!subscription && canStartTrial && (
                                <Button
                                    size="sm"
                                    disabled={blocked}
                                    onClick={() =>
                                        router.post(
                                            '/billing/trial',
                                            { plan_price_id: price.id },
                                            { preserveScroll: true },
                                        )
                                    }
                                >
                                    Start trial
                                </Button>
                            )}
                        </span>
                    </div>
                );
            })}
        </section>
    );
}

/**
 * Section 8: "We keep a summary; the document itself stays with the provider."
 *
 * So this is the summary and nothing more - what was charged and when. The
 * document lives on Dodo's portal, which the button above opens, and a link
 * straight to one is shown only when they have given us a URL for it.
 */
function Invoices({ invoices }: { invoices: Invoice[] }) {
    if (invoices.length === 0) return null;

    return (
        <section className="flex flex-col gap-2">
            <h2 className="text-sm font-medium text-muted-foreground">
                Invoices
            </h2>

            <table className="w-full text-sm">
                <tbody>
                    {invoices.map((invoice) => (
                        <tr key={invoice.id} className="border-t border-border">
                            <td className="py-2">
                                {invoice.issued_at
                                    ? new Date(
                                          invoice.issued_at,
                                      ).toLocaleDateString()
                                    : '—'}
                                {invoice.number && (
                                    <span className="ml-2 text-muted-foreground">
                                        {invoice.number}
                                    </span>
                                )}
                            </td>
                            <td className="py-2 text-muted-foreground capitalize">
                                {invoice.status}
                            </td>
                            <td className="py-2 text-right">
                                {money(invoice.total_minor, invoice.currency)}
                            </td>
                            <td className="py-2 text-right">
                                {invoice.hosted_url && (
                                    <a
                                        href={invoice.hosted_url}
                                        className="underline underline-offset-4"
                                        target="_blank"
                                        rel="noreferrer"
                                    >
                                        View
                                    </a>
                                )}
                            </td>
                        </tr>
                    ))}
                </tbody>
            </table>
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
    // Nothing to attach a paid add-on to without a subscription.
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
