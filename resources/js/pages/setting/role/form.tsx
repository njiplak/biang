import { router, useForm } from '@inertiajs/react';
import { ChevronRight, LoaderCircle } from 'lucide-react';

import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import {
    Collapsible,
    CollapsibleContent,
    CollapsibleTrigger,
} from '@/components/ui/collapsible';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AppLayout from '@/layouts/app-layout';
import { FormResponse } from '@/lib/constant';
import { index, store, update } from '@/routes/backoffice/setting/role';
import type { Role } from '@/types/role';

type PermissionItem = {
    id: number;
    name: string;
};

type PermissionGroup = {
    module: string;
    permissions: PermissionItem[];
};

type Props = {
    role?: Role;
    permissions?: PermissionGroup[];
};

export default function RoleForm({ role, permissions = [] }: Props) {
    const initialPermissions = role?.permissions?.map((p) => p.id) ?? [];

    const { data, setData, post, put, errors, processing } = useForm({
        name: role?.name ?? '',
        permissions: initialPermissions,
    });

    const onSubmit = (e: React.FormEvent<HTMLFormElement>) => {
        e.preventDefault();
        if (role) {
            put(update(role.id).url, FormResponse);
        } else {
            post(store().url, FormResponse);
        }
    };

    const togglePermission = (id: number) => {
        setData(
            'permissions',
            data.permissions.includes(id)
                ? data.permissions.filter((p) => p !== id)
                : [...data.permissions, id],
        );
    };

    const toggleModule = (group: PermissionGroup) => {
        const groupIds = group.permissions.map((p) => p.id);
        const allSelected = groupIds.every((id) => data.permissions.includes(id));

        if (allSelected) {
            setData(
                'permissions',
                data.permissions.filter((id) => !groupIds.includes(id)),
            );
        } else {
            const merged = new Set([...data.permissions, ...groupIds]);
            setData('permissions', Array.from(merged));
        }
    };

    return (
        <div className="flex flex-col gap-4 rounded-xl border border-border bg-card p-5 shadow-sm">
            <h1 className="text-xl font-semibold">
                {role ? 'Edit Role' : 'New Role'}
            </h1>
            <form onSubmit={onSubmit} className="space-y-6">
                <div className="flex flex-col gap-1.5">
                    <Label>Name</Label>
                    <Input
                        value={data.name}
                        onChange={(e) => setData('name', e.target.value)}
                    />
                    <InputError message={errors?.name} />
                </div>

                {permissions.length > 0 && (
                    <div className="flex flex-col gap-2">
                        <Label>Permissions</Label>
                        <InputError message={errors?.permissions} />
                        <div className="space-y-2 rounded-md border p-4">
                            {permissions.map((group) => {
                                const groupIds = group.permissions.map((p) => p.id);
                                const allSelected = groupIds.every((id) =>
                                    data.permissions.includes(id),
                                );
                                const someSelected =
                                    !allSelected &&
                                    groupIds.some((id) => data.permissions.includes(id));

                                return (
                                    <Collapsible key={group.module} defaultOpen>
                                        <div className="flex items-center gap-2">
                                            <Checkbox
                                                checked={
                                                    allSelected
                                                        ? true
                                                        : someSelected
                                                          ? 'indeterminate'
                                                          : false
                                                }
                                                onCheckedChange={() => toggleModule(group)}
                                            />
                                            <CollapsibleTrigger className="flex flex-1 items-center gap-1 text-sm font-medium capitalize">
                                                {group.module}
                                                <ChevronRight className="size-4 transition-transform duration-200 group-data-[state=open]/collapsible:rotate-90 [&[data-state=open]>svg]:rotate-90" />
                                            </CollapsibleTrigger>
                                        </div>
                                        <CollapsibleContent>
                                            <div className="ml-6 mt-2 grid grid-cols-2 gap-2 sm:grid-cols-3 md:grid-cols-4">
                                                {group.permissions.map((perm) => (
                                                    <label
                                                        key={perm.id}
                                                        className="flex items-center gap-2 text-sm"
                                                    >
                                                        <Checkbox
                                                            checked={data.permissions.includes(
                                                                perm.id,
                                                            )}
                                                            onCheckedChange={() =>
                                                                togglePermission(perm.id)
                                                            }
                                                        />
                                                        {perm.name.split('.')[1]}
                                                    </label>
                                                ))}
                                            </div>
                                        </CollapsibleContent>
                                    </Collapsible>
                                );
                            })}
                        </div>
                    </div>
                )}

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

RoleForm.layout = (page: React.ReactNode) => <AppLayout>{page}</AppLayout>;
