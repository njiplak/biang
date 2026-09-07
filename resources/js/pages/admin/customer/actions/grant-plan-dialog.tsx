import { useForm } from '@inertiajs/react';
import { BadgeCheck } from 'lucide-react';
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
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { Textarea } from '@/components/ui/textarea';
import { createFormResponse } from '@/lib/constant';
import admin from '@/routes/admin';
import type { CustomerWorkspace, PlanOption } from '@/types/customer';

/**
 * Section 10: "Grant a plan by hand. Sales cannot wait for a deploy."
 *
 * One button whether or not they already have a subscription - the server
 * decides between opening one and moving the existing one.
 */
export function GrantPlanDialog({
    workspace,
    plans,
    hasSubscription,
}: {
    workspace: CustomerWorkspace;
    plans: PlanOption[];
    hasSubscription: boolean;
}) {
    const [open, setOpen] = useState(false);
    const form = useForm({ plan_price_id: '', reason: '' });

    const submit = (event: React.FormEvent) => {
        event.preventDefault();
        form.post(admin.customer.plan(workspace.ulid).url, {
            ...createFormResponse(
                hasSubscription ? 'Plan changed.' : 'Plan granted.',
            ),
            onSuccess: () => {
                setOpen(false);
                form.reset();
            },
        });
    };

    return (
        <Dialog open={open} onOpenChange={setOpen}>
            <DialogTrigger asChild>
                <Button variant="outline">
                    <BadgeCheck className="size-4" />
                    {hasSubscription ? 'Change plan' : 'Grant plan'}
                </Button>
            </DialogTrigger>
            <DialogContent>
                <form onSubmit={submit} className="flex flex-col gap-4">
                    <DialogHeader>
                        <DialogTitle>
                            {hasSubscription ? 'Change plan' : 'Grant a plan'}
                        </DialogTitle>
                        <DialogDescription>
                            No money moves. The subscription is recorded as
                            granted by hand, against your account.
                        </DialogDescription>
                    </DialogHeader>

                    <div className="flex flex-col gap-1.5">
                        <Label htmlFor="plan">Plan and price</Label>
                        <Select
                            value={form.data.plan_price_id}
                            onValueChange={(value) =>
                                form.setData('plan_price_id', value)
                            }
                        >
                            <SelectTrigger id="plan">
                                <SelectValue placeholder="Pick a price" />
                            </SelectTrigger>
                            <SelectContent>
                                {plans.map((plan) => (
                                    <SelectItem
                                        key={plan.price_id}
                                        value={String(plan.price_id)}
                                    >
                                        {plan.label}
                                    </SelectItem>
                                ))}
                            </SelectContent>
                        </Select>
                        <InputError message={form.errors.plan_price_id} />
                    </div>

                    <div className="flex flex-col gap-1.5">
                        <Label htmlFor="grant-reason">Reason</Label>
                        <Textarea
                            id="grant-reason"
                            value={form.data.reason}
                            onChange={(e) =>
                                form.setData('reason', e.target.value)
                            }
                            placeholder="Which deal, and who agreed it?"
                        />
                        <InputError message={form.errors.reason} />
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
                            {hasSubscription ? 'Change plan' : 'Grant plan'}
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}
