import { useForm } from '@inertiajs/react';
import { UserPlus } from 'lucide-react';
import { useState } from 'react';

import InputError from '@/components/input-error';
import { PasswordInput } from '@/components/password-input';
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
import type { StaffRow } from '@/types/catalog';

/**
 * Section 3: staff roles are runtime-editable, unlike the fixed five workspace
 * roles, so the role here is a name from the admin guard rather than an id.
 */
export function StaffDialog({
    staff,
    roles,
    open: controlledOpen,
    onOpenChange,
    onSaved,
}: {
    staff?: StaffRow;
    roles: { name: string }[];
    open?: boolean;
    onOpenChange?: (open: boolean) => void;
    onSaved?: () => void;
}) {
    const [uncontrolledOpen, setUncontrolledOpen] = useState(false);
    const editing = staff !== undefined;

    const open = controlledOpen ?? uncontrolledOpen;
    const setOpen = onOpenChange ?? setUncontrolledOpen;

    const form = useForm({
        name: staff?.name ?? '',
        email: staff?.email ?? '',
        password: '',
        role: staff?.role ?? '',
        is_active: staff?.is_active ?? true,
    });

    const submit = (event: React.FormEvent) => {
        event.preventDefault();

        const options = {
            ...createFormResponse(editing ? 'Staff updated.' : 'Staff added.'),
            preserveScroll: true,
            onSuccess: () => {
                setOpen(false);
                if (!editing) form.reset();
                onSaved?.();
            },
        };

        if (editing) {
            form.put(admin.staff.update(staff.id).url, options);
        } else {
            form.post(admin.staff.store().url, options);
        }
    };

    return (
        <Dialog open={open} onOpenChange={setOpen}>
            {!editing && (
                <DialogTrigger asChild>
                    <Button>
                        <UserPlus className="size-4" />
                        Add staff
                    </Button>
                </DialogTrigger>
            )}
            <DialogContent>
                <form onSubmit={submit} className="flex flex-col gap-4">
                    <DialogHeader>
                        <DialogTitle>
                            {editing ? `Edit ${staff.name}` : 'Add staff'}
                        </DialogTitle>
                        <DialogDescription>
                            This account reaches the staff console only. It is a
                            separate login from any customer account.
                        </DialogDescription>
                    </DialogHeader>

                    <div className="flex flex-col gap-1.5">
                        <Label htmlFor="staff-name">Name</Label>
                        <Input
                            id="staff-name"
                            value={form.data.name}
                            onChange={(e) =>
                                form.setData('name', e.target.value)
                            }
                        />
                        <InputError message={form.errors.name} />
                    </div>

                    <div className="flex flex-col gap-1.5">
                        <Label htmlFor="staff-email">Email</Label>
                        <Input
                            id="staff-email"
                            type="email"
                            value={form.data.email}
                            onChange={(e) =>
                                form.setData('email', e.target.value)
                            }
                        />
                        <InputError message={form.errors.email} />
                    </div>

                    <div className="flex flex-col gap-1.5">
                        <Label htmlFor="staff-password">
                            {editing ? 'New password (optional)' : 'Password'}
                        </Label>
                        <PasswordInput
                            id="staff-password"
                            value={form.data.password}
                            onChange={(e) =>
                                form.setData('password', e.target.value)
                            }
                        />
                        {editing && (
                            <p className="text-xs text-muted-foreground">
                                Leave blank to keep the current one.
                            </p>
                        )}
                        <InputError message={form.errors.password} />
                    </div>

                    <div className="flex flex-col gap-1.5">
                        <Label htmlFor="staff-role">Role</Label>
                        <Select
                            value={form.data.role}
                            onValueChange={(value) =>
                                form.setData('role', value)
                            }
                        >
                            <SelectTrigger id="staff-role">
                                <SelectValue placeholder="No role" />
                            </SelectTrigger>
                            <SelectContent>
                                {roles.map((role) => (
                                    <SelectItem
                                        key={role.name}
                                        value={role.name}
                                    >
                                        {role.name}
                                    </SelectItem>
                                ))}
                            </SelectContent>
                        </Select>
                        <InputError message={form.errors.role} />
                    </div>

                    {editing && (
                        <label className="flex items-center gap-2 text-sm">
                            <input
                                type="checkbox"
                                checked={form.data.is_active}
                                onChange={(e) =>
                                    form.setData('is_active', e.target.checked)
                                }
                            />
                            Can sign in
                        </label>
                    )}

                    <DialogFooter>
                        <Button
                            type="button"
                            variant="outline"
                            onClick={() => setOpen(false)}
                        >
                            Cancel
                        </Button>
                        <Button type="submit" disabled={form.processing}>
                            {editing ? 'Save' : 'Add staff'}
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}
