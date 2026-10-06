import { router } from '@inertiajs/react';
import { useState } from 'react';

import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';

type Preview = {
    amount_minor: number;
    currency: string;
    tax_minor: number | null;
    credit_minor: number;
};

/**
 * A move involving a lifetime plan. Priced by us, not prorated by the
 * provider, and each kind gives up something different - so the dialog says
 * which one it is before anyone is sent to pay.
 */
type LifetimeChange = {
    kind:
        | 'lifetime_upgrade'
        | 'lifetime_downgrade'
        | 'leave_lifetime'
        | 'buy_lifetime';
    amount_minor: number;
    currency: string;
    interval: string;
};

const money = (minor: number, currency: string) =>
    new Intl.NumberFormat(undefined, { style: 'currency', currency }).format(
        minor / 100,
    );

/**
 * Section 4 prorates a plan change, and section 8 makes that arithmetic Dodo's.
 * We were charging the result without ever showing it, which is the most
 * reliable way to turn an upgrade into a "why was I charged this?" ticket.
 *
 * The preview is fetched when the button is pressed rather than for every plan
 * on render: each one is a call to Dodo, and this page has to keep working when
 * they are down. If the quote cannot be had, the switch is still offered - just
 * without a number, which is exactly where we were before.
 */
export function SwitchPlanDialog({
    planName,
    priceId,
    disabled,
    involvesLifetime,
    leavesLifetime,
}: {
    planName: string;
    priceId: number;
    disabled: boolean;
    // Known from the page itself, so the warnings hold even when the quote
    // cannot be fetched.
    involvesLifetime: boolean;
    leavesLifetime: boolean;
}) {
    const [open, setOpen] = useState(false);
    const [loading, setLoading] = useState(false);
    const [preview, setPreview] = useState<Preview | null>(null);
    // Set for a downgrade: it waits for the renewal and charges nothing now.
    const [effectiveAt, setEffectiveAt] = useState<string | null>(null);
    const [quoteFailed, setQuoteFailed] = useState(false);
    const [switching, setSwitching] = useState(false);
    const [change, setChange] = useState<LifetimeChange | null>(null);
    // Leaving lifetime cannot be undone without buying it again, so it is
    // never one click.
    const [givesUpLifetime, setGivesUpLifetime] = useState(false);

    const ask = async () => {
        setOpen(true);
        setLoading(true);
        setQuoteFailed(false);
        setPreview(null);
        setEffectiveAt(null);
        setChange(null);
        setGivesUpLifetime(false);

        try {
            const response = await fetch(
                `/billing/plan/preview?plan_price_id=${priceId}`,
                { headers: { Accept: 'application/json' } },
            );

            if (!response.ok) throw new Error('no quote');

            const body = await response.json();
            setPreview(body.preview);
            setEffectiveAt(body.effective_at ?? null);
            setChange(body.change ?? null);
        } catch {
            // Never a blocker: the switch itself is still theirs to make.
            setQuoteFailed(true);
        } finally {
            setLoading(false);
        }
    };

    const confirm = () => {
        setSwitching(true);
        router.put(
            '/billing/plan',
            { plan_price_id: priceId },
            {
                preserveScroll: true,
                onFinish: () => {
                    setSwitching(false);
                    setOpen(false);
                },
            },
        );
    };

    return (
        <>
            <Button
                variant="outline"
                size="sm"
                disabled={disabled}
                onClick={ask}
            >
                Switch to this
            </Button>

            <Dialog open={open} onOpenChange={setOpen}>
                <DialogContent>
                    <DialogHeader>
                        <DialogTitle>Switch to {planName}?</DialogTitle>
                        <DialogDescription asChild>
                            <div className="flex flex-col gap-2 text-left">
                                {loading && <span>Working out the price…</span>}

                                {!loading && change && (
                                    <LifetimeChangeText
                                        change={change}
                                        planName={planName}
                                    />
                                )}

                                {!loading && leavesLifetime && (
                                    <label className="flex items-start gap-2 text-foreground">
                                        <Checkbox
                                            checked={givesUpLifetime}
                                            onCheckedChange={(checked) =>
                                                setGivesUpLifetime(
                                                    checked === true,
                                                )
                                            }
                                        />
                                        <span>
                                            I understand my lifetime plan ends
                                            and cannot be restored.
                                        </span>
                                    </label>
                                )}

                                {!loading && preview && (
                                    <>
                                        <span>
                                            You will be charged{' '}
                                            <strong>
                                                {money(
                                                    preview.amount_minor,
                                                    preview.currency,
                                                )}
                                            </strong>{' '}
                                            now, and the new plan starts
                                            immediately.
                                        </span>
                                        {preview.credit_minor > 0 && (
                                            <span>
                                                That is after{' '}
                                                {money(
                                                    preview.credit_minor,
                                                    preview.currency,
                                                )}{' '}
                                                credit for the part of your
                                                current plan you have not used.
                                            </span>
                                        )}
                                    </>
                                )}

                                {!loading && effectiveAt && (
                                    <span>
                                        You keep your current plan until{' '}
                                        <strong>
                                            {new Date(
                                                effectiveAt,
                                            ).toLocaleDateString()}
                                        </strong>
                                        , the end of the period you have already
                                        paid for. {planName} starts then, and
                                        nothing is charged now.
                                    </span>
                                )}

                                {!loading &&
                                    !preview &&
                                    !effectiveAt &&
                                    !change && (
                                        <span>
                                            {involvesLifetime
                                                ? 'We could not work out the price just now. Nothing is charged until you confirm it at checkout.'
                                                : quoteFailed
                                                  ? 'We could not get a price from our payment provider just now. The change will still be prorated — you will only pay the difference.'
                                                  : 'The change will be prorated: you only pay the difference for the rest of this billing period.'}
                                        </span>
                                    )}
                            </div>
                        </DialogDescription>
                    </DialogHeader>

                    <DialogFooter>
                        <Button
                            variant="outline"
                            onClick={() => setOpen(false)}
                        >
                            Not now
                        </Button>
                        <Button
                            onClick={confirm}
                            disabled={
                                loading ||
                                switching ||
                                (leavesLifetime && !givesUpLifetime)
                            }
                        >
                            {change && change.kind !== 'lifetime_downgrade'
                                ? 'Continue to checkout'
                                : 'Confirm switch'}
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>
        </>
    );
}

function LifetimeChangeText({
    change,
    planName,
}: {
    change: LifetimeChange;
    planName: string;
}) {
    const amount = money(change.amount_minor, change.currency);

    switch (change.kind) {
        case 'lifetime_upgrade':
            return (
                <span>
                    You pay the difference, <strong>{amount}</strong>, once at
                    checkout. {planName} starts as soon as the payment goes
                    through, and it stays yours for life.
                </span>
            );
        case 'lifetime_downgrade':
            return (
                <span>
                    {planName} starts now. Lifetime plans are paid once, so the
                    difference is not refunded.
                </span>
            );
        case 'leave_lifetime':
            return (
                <span>
                    You subscribe to {planName} at{' '}
                    <strong>
                        {amount}/{change.interval}
                    </strong>{' '}
                    at checkout. Your lifetime plan ends when the subscription
                    starts and cannot be restored — getting it back means buying
                    lifetime again at its full price.
                </span>
            );
        case 'buy_lifetime':
            return (
                <span>
                    You pay <strong>{amount}</strong> once at checkout and{' '}
                    {planName} is yours for life. Your current subscription is
                    cancelled when the payment goes through, with no credit for
                    the time left on it.
                </span>
            );
    }
}
