import { router, useForm } from '@inertiajs/react';
import { LoaderCircle } from 'lucide-react';

import InputError from '@/components/input-error';
import { PasswordInput } from '@/components/password-input';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import AppLayout from '@/layouts/app-layout';
import { FormResponse } from '@/lib/constant';
import { index, store, update } from '@/routes/backoffice/setting/user';
import type { User } from '@/types/auth';
import type { Role } from '@/types/role';

type UserWithRole = User & {
    roles?: Role[];
};

type Props = {
    user?: UserWithRole;
    roles?: { id: number; name: string }[];
};

export default function UserForm({ user, roles = [] }: Props) {
    const currentRole = user?.roles?.[0]?.id;

    const { data, setData, post, put, errors, processing } = useForm({
        name: user?.name ?? '',
        email: user?.email ?? '',
        password: '',
        role: currentRole ?? (null as number | null),
    });

    const onSubmit = (e: React.FormEvent<HTMLFormElement>) => {
        e.preventDefault();
        if (user) {
            put(update(user.id).url, FormResponse);
        } else {
            post(store().url, FormResponse);
        }
    };

    return (
        <div className="flex flex-col gap-4 rounded-xl border border-border bg-card p-5 shadow-sm">
            <h1 className="text-xl font-semibold">
                {user ? 'Edit User' : 'New User'}
            </h1>
            <form onSubmit={onSubmit} className="space-y-4">
                <div className="flex flex-col gap-1.5">
                    <Label>Name</Label>
                    <Input
                        value={data.name}
                        onChange={(e) => setData('name', e.target.value)}
                    />
                    <InputError message={errors?.name} />
                </div>

                <div className="flex flex-col gap-1.5">
                    <Label>Email</Label>
                    <Input
                        type="email"
                        value={data.email}
                        onChange={(e) => setData('email', e.target.value)}
                    />
                    <InputError message={errors?.email} />
                </div>

                <div className="flex flex-col gap-1.5">
                    <Label>
                        Password
                        {user && (
                            <span className="ml-1 text-xs font-normal text-muted-foreground">
                                (leave blank to keep current)
                            </span>
                        )}
                    </Label>
                    <PasswordInput
                        value={data.password}
                        onChange={(e) => setData('password', e.target.value)}
                    />
                    <InputError message={errors?.password} />
                </div>

                <div className="flex flex-col gap-1.5">
                    <Label>Role</Label>
                    <Select
                        value={data.role?.toString() ?? ''}
                        onValueChange={(value) =>
                            setData('role', value ? Number(value) : null)
                        }
                    >
                        <SelectTrigger>
                            <SelectValue placeholder="Select a role" />
                        </SelectTrigger>
                        <SelectContent>
                            {roles.map((role) => (
                                <SelectItem
                                    key={role.id}
                                    value={role.id.toString()}
                                >
                                    {role.name}
                                </SelectItem>
                            ))}
                        </SelectContent>
                    </Select>
                    <InputError message={errors?.role} />
                </div>

                <div className="flex flex-col gap-2 sm:flex-row">
                    <Button
                        type="button"
                        variant="outline"
                        onClick={() => router.visit(index().url)}
                    >
                        Cancel
                    </Button>
                    <Button type="submit" disabled={processing}>
                        {processing && (
                            <LoaderCircle className="size-4 animate-spin" />
                        )}
                        Save
                    </Button>
                </div>
            </form>
        </div>
    );
}

UserForm.layout = (page: React.ReactNode) => <AppLayout>{page}</AppLayout>;
