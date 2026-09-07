import { Head, router } from '@inertiajs/react';
import { Download } from 'lucide-react';
import { createColumnHelper, type ColumnDef } from '@tanstack/react-table';
import { useCallback } from 'react';

import NextTable from '@/components/next-table';
import { Button } from '@/components/ui/button';
import AdminLayout from '@/layouts/admin-layout';
import { createDateColumn } from '@/lib/column-helpers';
import admin from '@/routes/admin';
import type { Base } from '@/types/base';
import type { CustomerRow } from '@/types/customer';
import { StateBadge } from './state-badge';

const helper = createColumnHelper<CustomerRow>();

const columns: ColumnDef<CustomerRow, any>[] = [
    helper.accessor('name', {
        id: 'name',
        header: 'Workspace',
        enableColumnFilter: false,
        enableHiding: false,
        cell: (ctx) => (
            <div className="flex flex-col">
                <span className="font-medium">{ctx.row.original.name}</span>
                <span className="text-xs text-muted-foreground">
                    {ctx.row.original.slug}
                </span>
            </div>
        ),
    }),
    helper.display({
        id: 'state',
        header: 'State',
        enableColumnFilter: false,
        cell: (ctx) => (
            <StateBadge
                state={ctx.row.original.state}
                label={ctx.row.original.state_label}
            />
        ),
    }),
    helper.display({
        id: 'plan',
        header: 'Plan',
        enableColumnFilter: false,
        cell: (ctx) => {
            const { plan, billing_source } = ctx.row.original;
            if (!plan) return <span className="text-muted-foreground">Free</span>;

            return (
                <div className="flex flex-col">
                    <span>{plan}</span>
                    {/* Section 8: a comp has no payment behind it. */}
                    {billing_source === 'manual' && (
                        <span className="text-xs text-muted-foreground">
                            Granted by hand
                        </span>
                    )}
                </div>
            );
        },
    }),
    helper.accessor('members_count', {
        id: 'members_count',
        header: 'Members',
        enableColumnFilter: false,
    }),
    createDateColumn<CustomerRow>('created_at', 'Signed up'),
];

export default function CustomerIndex() {
    const load = useCallback(async (params: Record<string, any>) => {
        const response = await window.fetch(
            admin.customer.fetch({ query: params }).url,
        );
        return response.json() as Promise<Base<CustomerRow[]>>;
    }, []);

    return (
        <div className="flex flex-col gap-4">
            <Head title="Customers" />

            <div className="flex flex-wrap items-start justify-between gap-3">
                <div className="flex flex-col">
                    <h1 className="text-xl font-semibold">Customers</h1>
                    <p className="hidden text-sm text-muted-foreground sm:block">
                        Search by workspace name or the email of anyone in it.
                        Closed workspaces are included.
                    </p>
                </div>
                {/* Taking customer data out is recorded in the audit trail. */}
                <Button variant="outline" asChild>
                    <a href={admin.customer.export.url()}>
                        <Download className="size-4" />
                        Export
                    </a>
                </Button>
            </div>

            <NextTable<CustomerRow>
                load={load}
                id="ulid"
                columns={columns}
                mode="table"
                searchPlaceholder="Workspace name or member email..."
                onRowClick={(row) =>
                    router.visit(admin.customer.show(row.ulid).url)
                }
            />
        </div>
    );
}

CustomerIndex.layout = (page: React.ReactNode) => (
    <AdminLayout>{page}</AdminLayout>
);
