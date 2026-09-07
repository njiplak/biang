import { Head, Link, router } from '@inertiajs/react';
import { createColumnHelper, type ColumnDef } from '@tanstack/react-table';
import { CheckCircle2, RefreshCw } from 'lucide-react';
import { useCallback, useState } from 'react';

import NextTable from '@/components/next-table';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import AdminLayout from '@/layouts/admin-layout';
import admin from '@/routes/admin';
import type { Base } from '@/types/base';
import type {
    BillingOpsOverview,
    DunningRow,
    FailedWebhook,
    IntegrityAlarm,
    RecentWebhook,
} from '@/types/catalog';

function when(value: string | null) {
    if (!value) return '-';
    return new Date(value).toLocaleString('en-GB', {
        day: '2-digit',
        month: 'short',
        hour: '2-digit',
        minute: '2-digit',
    });
}

/**
 * A link to the workspace a row is about. Every list here is ultimately "which
 * customer is affected", so getting there has to be one click from any of them.
 */
function WorkspaceLink({
    ulid,
    name,
}: {
    ulid: string | null;
    name: string | null;
}) {
    if (!ulid) {
        return <span className="text-muted-foreground">{name ?? '-'}</span>;
    }

    return (
        <Link
            className="font-medium underline underline-offset-4"
            href={admin.customer.show(ulid).url}
        >
            {name ?? ulid}
        </Link>
    );
}

/**
 * Section 8's operational half. Everything the payment integration records when
 * something goes wrong - and, until this screen, could only be read with a
 * database query.
 *
 * Each list is a paginated table of its own. They used to be capped at a fixed
 * limit with no way to see past it, which on the screen whose job is catching
 * payment failures meant the oldest ones were simply invisible.
 */
export default function BillingOpsIndex({
    failed_webhooks,
    dunning,
    integrity,
}: BillingOpsOverview) {
    // Bumped after a retry so every table re-reads: replaying an event can move
    // a row out of "failed" and into "recently received" at the same time.
    const [refresh, setRefresh] = useState(0);
    const reload = () => setRefresh((n) => n + 1);

    const loader = useCallback(
        <T,>(list: string) =>
            async (params: Record<string, any>) =>
                (await window
                    .fetch(
                        admin.billingOps.fetch({ query: { ...params, list } })
                            .url,
                        { headers: { Accept: 'application/json' } },
                    )
                    .then((r) => r.json())) as Base<T[]>,
        [],
    );

    /*
     * The counts come from the page payload, not the tables. They are
     * properties of the whole set, and a paginated table can only ever speak
     * for the page it happens to be showing.
     */
    const healthy =
        failed_webhooks.length === 0 &&
        integrity.length === 0 &&
        dunning.length === 0;

    const failedColumns: ColumnDef<FailedWebhook, any>[] = [
        failedHelper.accessor('event_type', {
            id: 'event_type',
            header: 'Event',
            enableColumnFilter: false,
            cell: (ctx) => (
                <div className="flex flex-col">
                    <span className="font-medium">
                        {ctx.row.original.event_type}
                    </span>
                    <span className="text-xs text-muted-foreground">
                        {ctx.row.original.event_id}
                    </span>
                </div>
            ),
        }),
        failedHelper.display({
            id: 'error',
            header: 'Why',
            cell: (ctx) => (
                <span className="text-muted-foreground">
                    {ctx.row.original.error ?? 'No reason recorded'}
                </span>
            ),
        }),
        failedHelper.accessor('attempts', {
            id: 'attempts',
            header: 'Attempts',
            enableColumnFilter: false,
        }),
        failedHelper.display({
            id: 'failed_at',
            header: 'Failed',
            cell: (ctx) => (
                <span className="text-muted-foreground">
                    {when(ctx.row.original.failed_at)}
                </span>
            ),
        }),
        failedHelper.display({
            id: 'actions',
            header: 'Retry',
            cell: (ctx) =>
                // Only an event we verified at intake can be replayed - a retry
                // must not be a way to apply something the signature check
                // already refused.
                ctx.row.original.can_retry ? (
                    <Button
                        variant="outline"
                        size="sm"
                        onClick={() =>
                            router.post(
                                admin.billingOps.retry({
                                    event: ctx.row.original.id,
                                }).url,
                                {},
                                { preserveScroll: true, onSuccess: reload },
                            )
                        }
                    >
                        <RefreshCw className="mr-1 size-3.5" />
                        Retry
                    </Button>
                ) : (
                    <Badge variant="outline">Unverified</Badge>
                ),
        }),
    ];

    const dunningColumns: ColumnDef<DunningRow, any>[] = [
        dunningHelper.display({
            id: 'workspace',
            header: 'Customer',
            cell: (ctx) => (
                <WorkspaceLink
                    ulid={ctx.row.original.workspace_ulid}
                    name={ctx.row.original.workspace_name}
                />
            ),
        }),
        dunningHelper.accessor('plan', {
            id: 'plan',
            header: 'Plan',
            enableColumnFilter: false,
            cell: (ctx) => ctx.row.original.plan ?? '-',
        }),
        dunningHelper.display({
            id: 'started_at',
            header: 'Since',
            cell: (ctx) => (
                <span className="text-muted-foreground">
                    {when(ctx.row.original.started_at)}
                </span>
            ),
        }),
        dunningHelper.display({
            id: 'grace_ends_at',
            header: 'Grace ends',
            cell: (ctx) => (
                <span className="flex items-center gap-2">
                    <span className="text-muted-foreground">
                        {when(ctx.row.original.grace_ends_at)}
                    </span>
                    {/* Section 9 keeps access during grace on purpose, so the
                        expiry is the date staff actually have to act before. */}
                    {ctx.row.original.grace_expired && (
                        <Badge variant="destructive">Expired</Badge>
                    )}
                </span>
            ),
        }),
        dunningHelper.display({
            id: 'last_failure_message',
            header: 'Last failure',
            cell: (ctx) => (
                <span className="text-muted-foreground">
                    {ctx.row.original.last_failure_message ?? '-'}
                </span>
            ),
        }),
    ];

    const integrityColumns: ColumnDef<IntegrityAlarm, any>[] = [
        integrityHelper.display({
            id: 'workspace',
            header: 'Customer',
            cell: (ctx) => (
                <WorkspaceLink
                    ulid={ctx.row.original.workspace_ulid}
                    name={ctx.row.original.workspace_name}
                />
            ),
        }),
        integrityHelper.accessor('plan', {
            id: 'plan',
            header: 'Plan',
            enableColumnFilter: false,
            cell: (ctx) => ctx.row.original.plan ?? '-',
        }),
        integrityHelper.accessor('status', {
            id: 'status',
            header: 'Status',
            enableColumnFilter: false,
            cell: (ctx) => (
                <Badge variant="outline">{ctx.row.original.status}</Badge>
            ),
        }),
        integrityHelper.display({
            id: 'created_at',
            header: 'Opened',
            cell: (ctx) => (
                <span className="text-muted-foreground">
                    {when(ctx.row.original.created_at)}
                </span>
            ),
        }),
    ];

    const recentColumns: ColumnDef<RecentWebhook, any>[] = [
        recentHelper.accessor('event_type', {
            id: 'event_type',
            header: 'Event',
            enableColumnFilter: false,
            cell: (ctx) => (
                <span className="font-medium">
                    {ctx.row.original.event_type}
                </span>
            ),
        }),
        recentHelper.display({
            id: 'received_at',
            header: 'Received',
            cell: (ctx) => (
                <span className="text-muted-foreground">
                    {when(ctx.row.original.received_at)}
                </span>
            ),
        }),
        recentHelper.display({
            id: 'result',
            header: 'Result',
            cell: (ctx) => {
                const row = ctx.row.original;
                if (row.processed_at) return <Badge>Applied</Badge>;
                if (row.failed_at)
                    return <Badge variant="destructive">Failed</Badge>;

                // Neither: accepted at intake and not yet reconciled.
                return <Badge variant="outline">Pending</Badge>;
            },
        }),
    ];

    return (
        <div className="flex flex-col gap-6">
            <Head title="Billing operations" />

            <div className="flex flex-col">
                <h1 className="text-xl font-semibold">Billing operations</h1>
                <p className="text-sm text-muted-foreground">
                    What the payment provider told us, and what we could not act
                    on.
                </p>
            </div>

            {healthy && (
                <div className="flex items-center gap-2 rounded-md border border-green-200 bg-green-50 p-3 text-sm text-green-900 dark:border-green-900 dark:bg-green-950 dark:text-green-200">
                    <CheckCircle2 className="size-4 shrink-0" />
                    Nothing needs attention. Every webhook applied, nobody is in
                    dunning, and no subscription is missing its provider record.
                </div>
            )}

            {/* A webhook that would not apply is money we did not record. */}
            <NextTable<FailedWebhook>
                id="id"
                title="Webhooks that failed"
                description="Accepted at intake but never applied. Retrying re-runs reconciliation, which is idempotent."
                columns={failedColumns}
                load={loader<FailedWebhook>('failed_webhooks')}
                params={{ _refresh: refresh }}
                searchPlaceholder="Search by event type, id or error..."
            />

            <NextTable<DunningRow>
                id="id"
                title="In dunning"
                description="Section 9: a failed payment keeps full access until the grace period ends."
                columns={dunningColumns}
                load={loader<DunningRow>('dunning')}
                params={{ _refresh: refresh }}
                searchPlaceholder="Search by workspace..."
            />

            {/* Section 8: a subscription marked as the provider's with no
                provider id means the sync broke. A comp is SUPPOSED to have
                none, which is the whole reason billing_source exists. */}
            <NextTable<IntegrityAlarm>
                id="id"
                title="Missing provider records"
                description="Provider-backed subscriptions with no provider id. Comped plans are not counted — they are supposed to have none."
                columns={integrityColumns}
                load={loader<IntegrityAlarm>('integrity')}
                params={{ _refresh: refresh }}
                searchPlaceholder="Search by workspace..."
            />

            <NextTable<RecentWebhook>
                id="id"
                title="Recently received"
                description="Context for the failures above: what has been arriving at all."
                columns={recentColumns}
                load={loader<RecentWebhook>('recent_webhooks')}
                params={{ _refresh: refresh }}
                searchPlaceholder="Search by event type..."
            />
        </div>
    );
}

const failedHelper = createColumnHelper<FailedWebhook>();
const dunningHelper = createColumnHelper<DunningRow>();
const integrityHelper = createColumnHelper<IntegrityAlarm>();
const recentHelper = createColumnHelper<RecentWebhook>();

BillingOpsIndex.layout = (page: React.ReactNode) => (
    <AdminLayout>{page}</AdminLayout>
);
