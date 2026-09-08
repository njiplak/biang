import { Head, Link } from '@inertiajs/react';
import { createColumnHelper, type ColumnDef } from '@tanstack/react-table';
import { Download, Eye } from 'lucide-react';
import { useCallback, useState } from 'react';

import NextTable from '@/components/next-table';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import AdminLayout from '@/layouts/admin-layout';
import admin from '@/routes/admin';
import type { Base } from '@/types/base';
import type { AuditRow, ImpersonationRow } from '@/types/catalog';

const helper = createColumnHelper<AuditRow>();

function when(value: string | null) {
    if (!value) return '-';
    return new Date(value).toLocaleString('en-GB', {
        day: '2-digit',
        month: 'short',
        year: 'numeric',
        hour: '2-digit',
        minute: '2-digit',
    });
}

const columns: ColumnDef<AuditRow, any>[] = [
    helper.accessor('action', {
        id: 'action',
        header: 'Action',
        enableColumnFilter: false,
        cell: (ctx) => (
            <div className="flex flex-wrap items-center gap-2">
                <span className="font-medium">{ctx.row.original.action}</span>
                {/* Section 10: "the customer did this" must stay separable
                    from "we did this as them". */}
                {ctx.row.original.via_impersonation && (
                    <Badge variant="secondary">as customer</Badge>
                )}
            </div>
        ),
    }),
    helper.display({
        id: 'actor',
        header: 'Who',
        cell: (ctx) => {
            const row = ctx.row.original;
            return (
                <div className="flex flex-col">
                    <span>{row.actor ?? 'System'}</span>
                    <span className="text-xs text-muted-foreground">
                        {row.actor_kind}
                        {row.ip_address ? ` · ${row.ip_address}` : ''}
                    </span>
                </div>
            );
        },
    }),
    helper.display({
        id: 'workspace',
        header: 'Customer',
        cell: (ctx) => {
            const row = ctx.row.original;
            if (!row.workspace_ulid)
                return <span className="text-muted-foreground">-</span>;

            return (
                <Link
                    className="underline underline-offset-4"
                    href={admin.customer.show(row.workspace_ulid).url}
                >
                    {row.workspace_name}
                </Link>
            );
        },
    }),
    helper.display({
        id: 'changes',
        header: 'Detail',
        cell: (ctx) => {
            const changes = ctx.row.original.changes;
            if (!changes)
                return <span className="text-muted-foreground">-</span>;

            return (
                <code className="text-xs text-muted-foreground">
                    {JSON.stringify(changes)}
                </code>
            );
        },
    }),
    helper.display({
        id: 'created_at',
        header: 'When',
        cell: (ctx) => (
            <span className="text-muted-foreground">
                {when(ctx.row.original.created_at)}
            </span>
        ),
    }),
];

export default function AuditIndex({
    actions,
    impersonations,
}: {
    actions: string[];
    impersonations: ImpersonationRow[];
}) {
    const [action, setAction] = useState('');

    const load = useCallback(
        async (params: Record<string, any>) => {
            const response = await window.fetch(
                admin.audit.fetch({
                    query: action
                        ? { ...params, 'filter[action]': action }
                        : params,
                }).url,
            );
            return response.json() as Promise<Base<AuditRow[]>>;
        },
        [action],
    );

    const open = impersonations.filter((row) => row.is_active);

    return (
        <div className="flex flex-col gap-4">
            <Head title="Audit trail" />

            <div className="flex flex-wrap items-start justify-between gap-3">
                <div className="flex flex-col">
                    <h1 className="text-xl font-semibold">Audit trail</h1>
                    <p className="text-sm text-muted-foreground">
                        Everything staff did, and everything done inside a
                        customer account as them.
                    </p>
                </div>
                {/* An audit trail that cannot leave the system is not much use
                    to the people who usually ask for one. */}
                <Button variant="outline" asChild>
                    <a
                        href={admin.audit.export.url(
                            action
                                ? { query: { 'filter[action]': action } }
                                : undefined,
                        )}
                    >
                        <Download className="size-4" />
                        Export
                    </a>
                </Button>
            </div>

            {/* Somebody inside a customer account right now is the one row
                worth reacting to rather than reading. */}
            {open.length > 0 && (
                <div className="flex items-start gap-2 rounded-md border border-amber-300 bg-amber-50 p-3 text-sm text-amber-950 dark:border-amber-800 dark:bg-amber-950 dark:text-amber-100">
                    <Eye className="mt-0.5 size-4 shrink-0" />
                    <div>
                        {open.map((row) => (
                            <p key={row.id}>
                                <strong>{row.admin}</strong> is inside{' '}
                                <strong>{row.workspace_name}</strong> as{' '}
                                {row.user_email} — {row.reason}
                            </p>
                        ))}
                    </div>
                </div>
            )}

            <Card>
                <CardHeader>
                    <CardTitle className="text-base">
                        Impersonation history
                    </CardTitle>
                </CardHeader>
                <CardContent className="overflow-x-auto p-0">
                    <Table>
                        <TableHeader>
                            <TableRow>
                                <TableHead>Staff</TableHead>
                                <TableHead>Entered as</TableHead>
                                <TableHead>Customer</TableHead>
                                <TableHead>Reason</TableHead>
                                <TableHead>Started</TableHead>
                                <TableHead>Ended</TableHead>
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            {impersonations.length === 0 && (
                                <TableRow>
                                    <TableCell
                                        colSpan={6}
                                        className="text-muted-foreground"
                                    >
                                        Nobody has entered a customer account.
                                    </TableCell>
                                </TableRow>
                            )}
                            {impersonations.map((row) => (
                                <TableRow key={row.id}>
                                    <TableCell>{row.admin ?? '-'}</TableCell>
                                    <TableCell>
                                        <div className="flex flex-col">
                                            <span>{row.user}</span>
                                            <span className="text-xs text-muted-foreground">
                                                {row.user_email}
                                            </span>
                                        </div>
                                    </TableCell>
                                    <TableCell>
                                        {row.workspace_ulid ? (
                                            <Link
                                                className="underline underline-offset-4"
                                                href={
                                                    admin.customer.show(
                                                        row.workspace_ulid,
                                                    ).url
                                                }
                                            >
                                                {row.workspace_name}
                                            </Link>
                                        ) : (
                                            (row.workspace_name ?? '-')
                                        )}
                                    </TableCell>
                                    <TableCell>
                                        {row.reason}
                                        {row.ticket_reference && (
                                            <span className="ml-1 text-xs text-muted-foreground">
                                                ({row.ticket_reference})
                                            </span>
                                        )}
                                    </TableCell>
                                    <TableCell className="text-muted-foreground">
                                        {when(row.started_at)}
                                    </TableCell>
                                    <TableCell>
                                        {row.is_active ? (
                                            <Badge variant="destructive">
                                                Still open
                                            </Badge>
                                        ) : (
                                            <span className="text-muted-foreground">
                                                {when(row.ended_at)}
                                            </span>
                                        )}
                                    </TableCell>
                                </TableRow>
                            ))}
                        </TableBody>
                    </Table>
                </CardContent>
            </Card>

            <div className="flex items-center justify-between gap-3">
                <h2 className="text-lg font-semibold">Everything else</h2>
                <Select
                    value={action || 'all'}
                    onValueChange={(value) =>
                        setAction(value === 'all' ? '' : value)
                    }
                >
                    <SelectTrigger className="w-64">
                        <SelectValue placeholder="All actions" />
                    </SelectTrigger>
                    <SelectContent>
                        <SelectItem value="all">All actions</SelectItem>
                        {actions.map((name) => (
                            <SelectItem key={name} value={name}>
                                {name}
                            </SelectItem>
                        ))}
                    </SelectContent>
                </Select>
            </div>

            <NextTable<AuditRow>
                load={load}
                id="id"
                columns={columns}
                mode="table"
                searchPlaceholder="Action or customer..."
            />
        </div>
    );
}

AuditIndex.layout = (page: React.ReactNode) => (
    <AdminLayout>{page}</AdminLayout>
);
