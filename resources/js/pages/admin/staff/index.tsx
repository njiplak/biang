import { Head, router, usePage } from '@inertiajs/react';
import { createColumnHelper, type ColumnDef } from '@tanstack/react-table';
import { useCallback, useState } from 'react';

import NextTable from '@/components/next-table';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import AdminLayout from '@/layouts/admin-layout';
import admin from '@/routes/admin';
import type { SharedData } from '@/types';
import type { Base } from '@/types/base';
import type { StaffRow } from '@/types/catalog';
import { StaffDialog } from './staff-dialog';

const helper = createColumnHelper<StaffRow>();

function when(value: string | null) {
    if (!value) return 'Never';
    return new Date(value).toLocaleString('en-GB', {
        day: '2-digit',
        month: 'short',
        year: 'numeric',
        hour: '2-digit',
        minute: '2-digit',
    });
}

export default function StaffIndex({
    roles,
}: {
    roles: { name: string }[];
}) {
    const me = usePage<SharedData>().props.auth.admin;
    const [editing, setEditing] = useState<StaffRow | null>(null);
    const [refresh, setRefresh] = useState(0);

    const load = useCallback(
        async (params: Record<string, any>) => {
            const response = await window.fetch(
                admin.staff.fetch({ query: params }).url,
            );
            return response.json() as Promise<Base<StaffRow[]>>;
        },
        // eslint-disable-next-line react-hooks/exhaustive-deps
        [refresh],
    );

    const columns: ColumnDef<StaffRow, any>[] = [
        helper.accessor('name', {
            id: 'name',
            header: 'Name',
            enableColumnFilter: false,
            cell: (ctx) => (
                <div className="flex flex-col">
                    <span className="font-medium">{ctx.row.original.name}</span>
                    <span className="text-xs text-muted-foreground">
                        {ctx.row.original.email}
                    </span>
                </div>
            ),
        }),
        helper.display({
            id: 'role',
            header: 'Role',
            cell: (ctx) =>
                ctx.row.original.role ? (
                    <Badge variant="secondary">{ctx.row.original.role}</Badge>
                ) : (
                    <span className="text-muted-foreground">No role</span>
                ),
        }),
        helper.display({
            id: 'status',
            header: 'Status',
            cell: (ctx) => {
                const row = ctx.row.original;
                if (row.is_offboarded)
                    return <Badge variant="outline">Offboarded</Badge>;
                if (!row.is_active)
                    return <Badge variant="destructive">Deactivated</Badge>;
                return <Badge>Active</Badge>;
            },
        }),
        helper.display({
            id: 'last_login',
            header: 'Last signed in',
            cell: (ctx) => (
                <div className="flex flex-col">
                    <span>{when(ctx.row.original.last_login_at)}</span>
                    {ctx.row.original.last_login_ip && (
                        <span className="text-xs text-muted-foreground">
                            {ctx.row.original.last_login_ip}
                        </span>
                    )}
                </div>
            ),
        }),
        helper.display({
            id: 'actions',
            header: 'Action',
            cell: (ctx) => {
                const row = ctx.row.original;

                // An offboarded account is history, not something to edit, and
                // the server refuses a self-change anyway - hiding the buttons
                // just avoids offering an action that cannot work.
                if (row.is_offboarded) return null;

                const isSelf = me?.id === row.id;

                return (
                    <div className="flex gap-2">
                        <Button
                            variant="outline"
                            size="sm"
                            onClick={() => setEditing(row)}
                        >
                            Edit
                        </Button>
                        {!isSelf && (
                            <Button
                                variant="ghost"
                                size="sm"
                                className="text-red-600 hover:text-red-600"
                                onClick={() =>
                                    router.delete(
                                        admin.staff.destroy(row.id).url,
                                        {
                                            preserveScroll: true,
                                            onSuccess: () =>
                                                setRefresh((n) => n + 1),
                                        },
                                    )
                                }
                            >
                                Offboard
                            </Button>
                        )}
                    </div>
                );
            },
        }),
    ];

    return (
        <div className="flex flex-col gap-4">
            <Head title="Staff" />

            <div className="flex flex-wrap items-start justify-between gap-3">
                <div className="flex flex-col">
                    <h1 className="text-xl font-semibold">Staff</h1>
                    <p className="text-sm text-muted-foreground">
                        Who can reach this console. Offboarding keeps the
                        account for the audit trail and removes the access.
                    </p>
                </div>
                <StaffDialog
                    roles={roles}
                    onSaved={() => setRefresh((n) => n + 1)}
                />
            </div>

            <NextTable<StaffRow>
                load={load}
                id="id"
                columns={columns}
                mode="table"
                searchPlaceholder="Name or email..."
            />

            {editing && (
                <StaffDialog
                    key={editing.id}
                    staff={editing}
                    roles={roles}
                    open
                    onOpenChange={(next) => !next && setEditing(null)}
                    onSaved={() => {
                        setEditing(null);
                        setRefresh((n) => n + 1);
                    }}
                />
            )}
        </div>
    );
}

StaffIndex.layout = (page: React.ReactNode) => <AdminLayout>{page}</AdminLayout>;
