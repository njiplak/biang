import { useForm } from '@inertiajs/react';
import { Eye } from 'lucide-react';
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
import { Textarea } from '@/components/ui/textarea';
import { createFormResponse } from '@/lib/constant';
import admin from '@/routes/admin';
import type { CustomerMember, CustomerWorkspace } from '@/types/customer';

/**
 * Section 10: "Reproduce a complaint. Enter a customer's workspace as them."
 *
 * Whose account matters - a viewer sees a different product to an owner, and
 * reproducing the complaint means entering as the person who reported it.
 */
export function ImpersonateDialog({
    workspace,
    members,
}: {
    workspace: CustomerWorkspace;
    members: CustomerMember[];
}) {
    const [open, setOpen] = useState(false);
    const form = useForm({ user_id: '', reason: '', ticket_reference: '' });

    const submit = (event: React.FormEvent) => {
        event.preventDefault();
        form.post(admin.customer.impersonate(workspace.ulid).url, {
            ...createFormResponse('Entering the customer account...'),
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
                    <Eye className="size-4" />
                    Enter as customer
                </Button>
            </DialogTrigger>
            <DialogContent>
                <form onSubmit={submit} className="flex flex-col gap-4">
                    <DialogHeader>
                        <DialogTitle>Enter {workspace.name}</DialogTitle>
                        <DialogDescription>
                            You will see the product exactly as they do, with a
                            banner saying so. The reason and your name are kept
                            permanently.
                        </DialogDescription>
                    </DialogHeader>

                    <div className="flex flex-col gap-1.5">
                        <Label htmlFor="user">Enter as</Label>
                        <Select
                            value={form.data.user_id}
                            onValueChange={(value) =>
                                form.setData('user_id', value)
                            }
                        >
                            <SelectTrigger id="user">
                                <SelectValue placeholder="Pick a person" />
                            </SelectTrigger>
                            <SelectContent>
                                {members.map((member) => (
                                    <SelectItem
                                        key={member.id}
                                        value={String(member.user_id)}
                                    >
                                        {member.name} ({member.role_label})
                                    </SelectItem>
                                ))}
                            </SelectContent>
                        </Select>
                        <InputError message={form.errors.user_id} />
                    </div>

                    <div className="flex flex-col gap-1.5">
                        <Label htmlFor="ticket">
                            Ticket reference (optional)
                        </Label>
                        <Input
                            id="ticket"
                            value={form.data.ticket_reference}
                            onChange={(e) =>
                                form.setData('ticket_reference', e.target.value)
                            }
                            placeholder="SUP-1234"
                        />
                        <InputError message={form.errors.ticket_reference} />
                    </div>

                    <div className="flex flex-col gap-1.5">
                        <Label htmlFor="impersonate-reason">Reason</Label>
                        <Textarea
                            id="impersonate-reason"
                            value={form.data.reason}
                            onChange={(e) =>
                                form.setData('reason', e.target.value)
                            }
                            placeholder="What are you reproducing?"
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
                            Enter account
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}
