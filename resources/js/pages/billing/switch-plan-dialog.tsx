import { router } from '@inertiajs/react';
import { useState } from 'react';

import { Button } from '@/components/ui/button';
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
}: {
    planName: string;
    priceId: number;
    disabled: boolean;
}) {
    const [open, setOpen] = useState(false);
    const [loading, setLoading] = useState(false);
    const [preview, setPreview] = useState<Preview | null>(null);
    const [quoteFailed, setQuoteFailed] = useState(false);
    const [switching, setSwitching] = useState(false);

    const ask = async () => {
        setOpen(true);
        setLoading(true);
        setQuoteFailed(false);
        setPreview(null);

        try {
            const response = await fetch(
                `/billing/plan/preview?plan_price_id=${priceId}`,
                { headers: { Accept: 'application/json' } },
            );

            if (!response.ok) throw new Error('no quote');

            const body = await response.json();
            setPreview(body.preview);
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

                                {!loading && !preview && (
                                    <span>
                                        {quoteFailed
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
                            disabled={loading || switching}
                        >
                            Confirm switch
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>
        </>
    );
}
