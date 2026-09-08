import { useForm } from '@inertiajs/react';
import { useState } from 'react';

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

/**
 * Cancelling used to be one click on a ghost button, straight to the provider.
 * That was survivable while cancelling landed on a usable free tier; it is not
 * now, because a misclick takes away the ability to work.
 *
 * This is a confirmation, NOT a retention flow. `routes/web/billing.php`
 * commits to never blocking the exit, and with a merchant of record an
 * obstructed cancellation does not become a retained customer - it becomes a
 * chargeback, which is worse for everyone. So: state the consequence plainly,
 * ask one optional question, and get out of the way.
 */
const REASONS = [
    { value: 'too_expensive', label: 'Too expensive' },
    { value: 'missing_features', label: 'Missing features I need' },
    { value: 'switched_service', label: 'Switched to something else' },
    { value: 'unused', label: 'We were not using it' },
    { value: 'customer_service', label: 'Support was not good enough' },
    { value: 'low_quality', label: 'It did not work well enough' },
    { value: 'too_complex', label: 'Too complicated' },
    { value: 'other', label: 'Something else' },
];

export function CancelDialog() {
    const [open, setOpen] = useState(false);
    const form = useForm<{ feedback: string | null; comment: string }>({
        feedback: null,
        comment: '',
    });

    const submit = (event: React.FormEvent) => {
        event.preventDefault();

        form.delete('/billing', {
            preserveScroll: true,
            onSuccess: () => setOpen(false),
        });
    };

    return (
        <Dialog open={open} onOpenChange={setOpen}>
            <DialogTrigger asChild>
                <Button variant="ghost" size="sm">
                    Cancel
                </Button>
            </DialogTrigger>

            <DialogContent>
                <form onSubmit={submit}>
                    <DialogHeader>
                        <DialogTitle>Cancel this subscription?</DialogTitle>
                        {/* The three facts that stop "I did not know I would
                            lose access" becoming a dispute. */}
                        <DialogDescription asChild>
                            <div className="flex flex-col gap-2 text-left">
                                <span>
                                    Billing stops straight away and you will not
                                    be charged again.
                                </span>
                                <span>
                                    The workspace becomes{' '}
                                    <strong>read-only</strong>. Everything in it
                                    stays, stays readable, and is never deleted
                                    — nobody is removed and nothing is trimmed.
                                </span>
                                <span>
                                    You can choose a plan again at any time and
                                    pick up exactly where you left off.
                                </span>
                            </div>
                        </DialogDescription>
                    </DialogHeader>

                    <div className="flex flex-col gap-4 py-4">
                        {/* Optional, and never required to proceed - a
                            question you must answer to leave is a blocked exit. */}
                        <div className="flex flex-col gap-1.5">
                            <Label htmlFor="feedback">
                                Why are you leaving?{' '}
                                <span className="text-muted-foreground">
                                    (optional)
                                </span>
                            </Label>
                            <Select
                                value={form.data.feedback ?? undefined}
                                onValueChange={(value) =>
                                    form.setData('feedback', value)
                                }
                            >
                                <SelectTrigger id="feedback">
                                    <SelectValue placeholder="Rather not say" />
                                </SelectTrigger>
                                <SelectContent>
                                    {REASONS.map((reason) => (
                                        <SelectItem
                                            key={reason.value}
                                            value={reason.value}
                                        >
                                            {reason.label}
                                        </SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                        </div>

                        <div className="flex flex-col gap-1.5">
                            <Label htmlFor="comment">
                                Anything else?{' '}
                                <span className="text-muted-foreground">
                                    (optional)
                                </span>
                            </Label>
                            <Textarea
                                id="comment"
                                rows={3}
                                maxLength={1000}
                                value={form.data.comment}
                                onChange={(event) =>
                                    form.setData('comment', event.target.value)
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
                            Keep my plan
                        </Button>
                        <Button
                            type="submit"
                            variant="destructive"
                            disabled={form.processing}
                        >
                            Cancel subscription
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}
