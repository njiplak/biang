import { useForm } from '@inertiajs/react';
import { ShieldOff } from 'lucide-react';
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
import { Textarea } from '@/components/ui/textarea';
import { createFormResponse } from '@/lib/constant';
import admin from '@/routes/admin';
import type { CustomerWorkspace } from '@/types/customer';

/** Section 10: "Stop abuse. Suspend a workspace immediately." */
export function SuspendDialog({ workspace }: { workspace: CustomerWorkspace }) {
    const [open, setOpen] = useState(false);
    const form = useForm({ reason: '' });

    const submit = (event: React.FormEvent) => {
        event.preventDefault();
        form.post(admin.customer.suspend(workspace.ulid).url, {
            ...createFormResponse('Workspace suspended.'),
            onSuccess: () => {
                setOpen(false);
                form.reset();
            },
        });
    };

    return (
        <Dialog open={open} onOpenChange={setOpen}>
            <DialogTrigger asChild>
                <Button variant="destructive">
                    <ShieldOff className="size-4" />
                    Suspend
                </Button>
            </DialogTrigger>
            <DialogContent>
                <form onSubmit={submit} className="flex flex-col gap-4">
                    <DialogHeader>
                        <DialogTitle>Suspend {workspace.name}</DialogTitle>
                        <DialogDescription>
                            They lose access immediately but can still export
                            their data. The reason is kept on the workspace.
                        </DialogDescription>
                    </DialogHeader>

                    <div className="flex flex-col gap-1.5">
                        <Label htmlFor="reason">Reason</Label>
                        <Textarea
                            id="reason"
                            value={form.data.reason}
                            onChange={(e) =>
                                form.setData('reason', e.target.value)
                            }
                            placeholder="What did they do, and where is it recorded?"
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
                        <Button
                            type="submit"
                            variant="destructive"
                            disabled={form.processing}
                        >
                            Suspend
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}
