import { useForm } from '@inertiajs/react';
import { Coins } from 'lucide-react';
import { useState } from 'react';

import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
    DialogTrigger,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { createFormResponse } from '@/lib/constant';
import admin from '@/routes/admin';
import type { CatalogAddon, CatalogPlan } from '@/types/catalog';

/**
 * Adding a price archives any live one for the same interval and currency.
 * Prices are immutable once sold, so "editing" is always insert-plus-archive -
 * a subscription keeps pointing at the exact row it was sold on.
 */
export function PriceDialog({
    plan,
    addon,
}: {
    plan?: CatalogPlan;
    addon?: CatalogAddon;
}) {
    const [open, setOpen] = useState(false);
    const form = useForm({
        billing_interval: 'month',
        currency: 'USD',
        // Entered in major units; converted on submit, because money is stored
        // as integer minor units and never as a float.
        amount: 0,
    });

    const target = plan
        ? admin.catalog.plan.price.store(plan.id).url
        : admin.catalog.addon.price.store(addon!.id).url;

    const submit = (event: React.FormEvent) => {
        event.preventDefault();

        form.transform((data: any) => ({
            billing_interval: data.billing_interval,
            currency: data.currency,
            amount_minor: Math.round(Number(data.amount) * 100),
        }));

        form.post(target, {
            ...createFormResponse('Price added; the previous one is archived.'),
            onSuccess: () => {
                setOpen(false);
                form.reset();
            },
        });
    };

    return (
        <Dialog open={open} onOpenChange={setOpen}>
            <DialogTrigger asChild>
                <Button
                    variant="outline"
                    size="sm"
                    className={addon ? 'mt-3' : undefined}
                >
                    <Coins className="size-4" />
                    {plan ? 'Add price' : 'Add price'}
                </Button>
            </DialogTrigger>
            <DialogContent>
                <form onSubmit={submit} className="flex flex-col gap-4">
                    <DialogHeader>
                        <DialogTitle>
                            Price for {plan?.name ?? addon?.name}
                        </DialogTitle>
                        <DialogDescription>
                            Any live price on the same interval and currency is
                            archived. Customers already on it keep what they
                            bought.
                        </DialogDescription>
                    </DialogHeader>

                    <div className="flex flex-col gap-1.5">
                        <Label htmlFor="interval">Interval</Label>
                        <Select
                            value={form.data.billing_interval}
                            onValueChange={(value) =>
                                form.setData('billing_interval', value)
                            }
                        >
                            <SelectTrigger id="interval">
                                <SelectValue />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem value="month">Monthly</SelectItem>
                                <SelectItem value="year">Yearly</SelectItem>
                            </SelectContent>
                        </Select>
                        <InputError message={form.errors.billing_interval} />
                    </div>

                    <div className="flex gap-3">
                        <div className="flex flex-col gap-1.5">
                            <Label htmlFor="currency">Currency</Label>
                            <Input
                                id="currency"
                                className="w-28 uppercase"
                                maxLength={3}
                                value={form.data.currency}
                                onChange={(e) =>
                                    form.setData(
                                        'currency',
                                        e.target.value.toUpperCase(),
                                    )
                                }
                            />
                            <InputError message={form.errors.currency} />
                        </div>
                        <div className="flex flex-1 flex-col gap-1.5">
                            <Label htmlFor="amount">Amount</Label>
                            <Input
                                id="amount"
                                type="number"
                                min={0}
                                step="0.01"
                                value={form.data.amount}
                                onChange={(e) =>
                                    form.setData(
                                        'amount',
                                        Number(e.target.value),
                                    )
                                }
                            />
                            {/* The field is entered as `amount` but validated
                                server-side as `amount_minor`. */}
                            <InputError
                                message={
                                    (form.errors as Record<string, string>)
                                        .amount_minor
                                }
                            />
                        </div>
                    </div>

                    <DialogFooter>
                        <Button
                            type="button"
                            variant="outline"
                            onClick={() => setOpen(false)}
                        >
                            Cancel
                        </Button>
                        <Button type="submit" disabled={form.processing}>
                            Add price
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}
