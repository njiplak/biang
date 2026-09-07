import { useForm } from '@inertiajs/react';
import { CalendarPlus } from 'lucide-react';
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
import { Textarea } from '@/components/ui/textarea';
import { createFormResponse } from '@/lib/constant';
import admin from '@/routes/admin';
import type { CustomerWorkspace } from '@/types/customer';

/** Section 10: "Extend a trial." Only offered while one is actually running. */
export function ExtendTrialDialog({
    workspace,
}: {
    workspace: CustomerWorkspace;
}) {
    const [open, setOpen] = useState(false);
    const form = useForm({ days: 7, reason: '' });

    const submit = (event: React.FormEvent) => {
        event.preventDefault();
        form.post(admin.customer.trial(workspace.ulid).url, {
            ...createFormResponse('Trial extended.'),
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
                    <CalendarPlus className="size-4" />
                    Extend trial
                </Button>
            </DialogTrigger>
            <DialogContent>
                <form onSubmit={submit} className="flex flex-col gap-4">
                    <DialogHeader>
                        <DialogTitle>Extend the trial</DialogTitle>
                        <DialogDescription>
                            Days are added on top of what is left, not counted
                            from today. The plan does not change.
                        </DialogDescription>
                    </DialogHeader>

                    <div className="flex flex-col gap-1.5">
                        <Label htmlFor="days">Extra days</Label>
                        <Input
                            id="days"
                            type="number"
                            min={1}
                            max={90}
                            value={form.data.days}
                            onChange={(e) =>
                                form.setData('days', Number(e.target.value))
                            }
                        />
                        <InputError message={form.errors.days} />
                    </div>

                    <div className="flex flex-col gap-1.5">
                        <Label htmlFor="trial-reason">Reason</Label>
                        <Textarea
                            id="trial-reason"
                            value={form.data.reason}
                            onChange={(e) =>
                                form.setData('reason', e.target.value)
                            }
                            placeholder="Why do they need longer?"
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
                            Extend
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}
