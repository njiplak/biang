import { Head, Link, router, usePage } from '@inertiajs/react';
import { createColumnHelper, type ColumnDef } from '@tanstack/react-table';
import { ArrowLeft, ShieldOff, ShieldCheck } from 'lucide-react';
import { useCallback, useState } from 'react';

import NextTable from '@/components/next-table';

import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import AdminLayout from '@/layouts/admin-layout';
import admin from '@/routes/admin';
import type { SharedData } from '@/types';
import type { Base } from '@/types/base';
import type {
    CustomerEntitlement,
    CustomerInvoice,
    CustomerMember,
    CustomerOverride,
    CustomerOverview,
} from '@/types/customer';
import { GrantPlanDialog } from './actions/grant-plan-dialog';
import { ImpersonateDialog } from './actions/impersonate-dialog';
import { ExtendTrialDialog } from './actions/extend-trial-dialog';
import { OverrideDialog } from './actions/override-dialog';
import { SuspendDialog } from './actions/suspend-dialog';
import { StateBadge } from './state-badge';

function formatDate(value: string | null) {
    if (!value) return '-';
    return new Date(value).toLocaleDateString('en-GB', {
        day: '2-digit',
        month: 'short',
        year: 'numeric',
    });
}

function formatMoney(minor: number, currency: string) {
    return `${currency} ${(minor / 100).toFixed(2)}`;
}

/** Null is unlimited everywhere in the entitlement layer. */
function formatLimit(limit: number | null) {
    return limit === null ? 'Unlimited' : String(limit);
}

const entitlementHelper = createColumnHelper<CustomerEntitlement>();
const overrideHelper = createColumnHelper<CustomerOverride>();
const memberHelper = createColumnHelper<CustomerMember>();
const invoiceHelper = createColumnHelper<CustomerInvoice>();

export default function CustomerShow({
    workspace,
    subscription,
    seats,
    members,
    plans,
    features,
}: CustomerOverview) {
    const { permissions } = usePage<SharedData>().props.auth;
    const can = (permission: string) => permissions.includes(permission);

    // Bumped after an override is granted or revoked, so the tables re-read
    // rather than showing the state the page was first rendered with.
    const [refresh, setRefresh] = useState(0);

    /*
     * One loader for all four lists. They are different views of the same
     * customer, so they share an endpoint and differ only by `list`, which the
     * server validates against its own allow-list.
     */
    const detail = useCallback(
        <T,>(list: string) =>
            async (params: Record<string, any>) =>
                (await window
                    .fetch(
                        // The route parameter and the query string are separate
                        // arguments; folding them into one object puts `query`
                        // in the URL path instead of after the `?`.
                        admin.customer.fetchDetail(
                            { workspace: workspace.ulid },
                            { query: { ...params, list } },
                        ).url,
                        { headers: { Accept: 'application/json' } },
                    )
                    .then((r) => r.json())) as Base<T[]>,
        [workspace.ulid],
    );

    const entitlementColumns: ColumnDef<CustomerEntitlement, any>[] = [
        entitlementHelper.accessor('feature', {
            id: 'feature',
            header: 'Feature',
            enableColumnFilter: false,
        }),
        entitlementHelper.accessor('used', {
            id: 'used',
            header: 'Used',
            enableColumnFilter: false,
        }),
        entitlementHelper.display({
            id: 'limit',
            header: 'Limit',
            cell: (ctx) => formatLimit(ctx.row.original.limit),
        }),
        entitlementHelper.display({
            id: 'source',
            header: 'From',
            cell: (ctx) => (
                <span className="text-muted-foreground">
                    {ctx.row.original.source}
                </span>
            ),
        }),
    ];

    const overrideColumns: ColumnDef<CustomerOverride, any>[] = [
        overrideHelper.display({
            id: 'feature',
            header: 'Feature',
            cell: (ctx) =>
                ctx.row.original.feature_name ?? ctx.row.original.feature,
        }),
        overrideHelper.display({
            id: 'value',
            header: 'Value',
            cell: (ctx) => formatLimit(ctx.row.original.value),
        }),
        overrideHelper.accessor('reason', {
            id: 'reason',
            header: 'Reason',
            enableColumnFilter: false,
        }),
        overrideHelper.display({
            id: 'granted_by',
            header: 'Granted by',
            cell: (ctx) => (
                <span className="text-muted-foreground">
                    {ctx.row.original.granted_by ?? '-'}
                </span>
            ),
        }),
        overrideHelper.display({
            id: 'expires_at',
            header: 'Expires',
            cell: (ctx) => (
                <span className="text-muted-foreground">
                    {ctx.row.original.expires_at
                        ? formatDate(ctx.row.original.expires_at)
                        : 'Never'}
                </span>
            ),
        }),
    ];

    const memberColumns: ColumnDef<CustomerMember, any>[] = [
        memberHelper.display({
            id: 'name',
            header: 'Name',
            cell: (ctx) => ctx.row.original.name ?? '-',
        }),
        memberHelper.display({
            id: 'email',
            header: 'Email',
            cell: (ctx) => ctx.row.original.email ?? '-',
        }),
        memberHelper.display({
            id: 'role',
            header: 'Role',
            cell: (ctx) => (
                <span>
                    {ctx.row.original.role_label}
                    {/* Section 9: the owner is who hears about card problems. */}
                    {ctx.row.original.is_owner && (
                        <span className="ml-2 rounded bg-neutral-100 px-1.5 py-0.5 text-xs text-neutral-700 dark:bg-neutral-800 dark:text-neutral-300">
                            billing contact
                        </span>
                    )}
                </span>
            ),
        }),
        memberHelper.display({
            id: 'joined_at',
            header: 'Joined',
            cell: (ctx) => (
                <span className="text-muted-foreground">
                    {formatDate(ctx.row.original.joined_at)}
                </span>
            ),
        }),
    ];

    const invoiceColumns: ColumnDef<CustomerInvoice, any>[] = [
        invoiceHelper.display({
            id: 'number',
            header: 'Invoice',
            cell: (ctx) => {
                const invoice = ctx.row.original;
                const label = invoice.number ?? invoice.id;

                // Section 8: the document itself is the provider's, so the only
                // thing we can offer is a link out to it.
                return invoice.hosted_url ? (
                    <a
                        className="underline"
                        href={invoice.hosted_url}
                        target="_blank"
                        rel="noreferrer"
                    >
                        {label}
                    </a>
                ) : (
                    label
                );
            },
        }),
        invoiceHelper.accessor('status', {
            id: 'status',
            header: 'Status',
            enableColumnFilter: false,
        }),
        invoiceHelper.display({
            id: 'total',
            header: 'Total',
            cell: (ctx) =>
                formatMoney(
                    ctx.row.original.total_minor,
                    ctx.row.original.currency,
                ),
        }),
        invoiceHelper.display({
            id: 'issued_at',
            header: 'Issued',
            cell: (ctx) => (
                <span className="text-muted-foreground">
                    {formatDate(ctx.row.original.issued_at)}
                </span>
            ),
        }),
        invoiceHelper.display({
            id: 'paid_at',
            header: 'Paid',
            cell: (ctx) => (
                <span className="text-muted-foreground">
                    {formatDate(ctx.row.original.paid_at)}
                </span>
            ),
        }),
    ];

    const isDeleted = workspace.state === 'deleted';
    const isSuspended = workspace.state === 'suspended';

    return (
        <div className="flex flex-col gap-4">
            <Head title={workspace.name} />

            <div className="flex flex-wrap items-start justify-between gap-3">
                <div className="flex flex-col gap-1">
                    <Link
                        href={admin.customer.index.url()}
                        className="flex w-fit items-center gap-1 text-xs text-muted-foreground hover:text-foreground"
                    >
                        <ArrowLeft className="size-3" />
                        All customers
                    </Link>
                    <div className="flex items-center gap-2">
                        <h1 className="text-xl font-semibold">
                            {workspace.name}
                        </h1>
                        <StateBadge
                            state={workspace.state}
                            label={workspace.state_label}
                        />
                    </div>
                    <p className="text-sm text-muted-foreground">
                        {workspace.slug} · signed up{' '}
                        {formatDate(workspace.created_at)}
                    </p>
                </div>

                {/*
                 * A closed workspace can be read but not operated on - the
                 * routes behind these actions do not resolve a trashed one.
                 */}
                {!isDeleted && (
                    <div className="flex flex-wrap gap-2">
                        {can('billing.grant') && (
                            <>
                                <GrantPlanDialog
                                    workspace={workspace}
                                    plans={plans}
                                    hasSubscription={subscription !== null}
                                />
                                {subscription?.status === 'trialing' && (
                                    <ExtendTrialDialog workspace={workspace} />
                                )}
                            </>
                        )}
                        {can('billing.override') && (
                            <OverrideDialog
                                workspace={workspace}
                                features={features}
                                onSaved={() => setRefresh((n) => n + 1)}
                            />
                        )}
                        {can('customer.impersonate') && members.length > 0 && (
                            <ImpersonateDialog
                                workspace={workspace}
                                members={members}
                            />
                        )}
                        {can('workspace.suspend') &&
                            (isSuspended ? (
                                <Button
                                    variant="outline"
                                    onClick={() =>
                                        router.delete(
                                            admin.customer.unsuspend(
                                                workspace.ulid,
                                            ).url,
                                        )
                                    }
                                >
                                    <ShieldCheck className="size-4" />
                                    Lift suspension
                                </Button>
                            ) : (
                                <SuspendDialog workspace={workspace} />
                            ))}
                    </div>
                )}
            </div>

            {isSuspended && (
                <div className="flex items-start gap-2 rounded-md border border-red-200 bg-red-50 p-3 text-sm text-red-800 dark:border-red-900 dark:bg-red-950 dark:text-red-200">
                    <ShieldOff className="mt-0.5 size-4 shrink-0" />
                    <div>
                        <p className="font-medium">
                            Suspended {formatDate(workspace.suspended_at)}
                            {workspace.suspended_by
                                ? ` by ${workspace.suspended_by}`
                                : ''}
                        </p>
                        <p>{workspace.suspension_reason}</p>
                    </div>
                </div>
            )}

            {/* Section 7: name the specific limits, not "over quota". */}
            {workspace.over_limit_features && (
                <div className="rounded-md border border-orange-200 bg-orange-50 p-3 text-sm text-orange-900 dark:border-orange-900 dark:bg-orange-950 dark:text-orange-200">
                    Writing is blocked. Over limit on{' '}
                    <span className="font-medium">
                        {workspace.over_limit_features.join(', ')}
                    </span>
                    .
                </div>
            )}

            {isDeleted && (
                <div className="rounded-md border border-neutral-300 bg-neutral-50 p-3 text-sm text-muted-foreground dark:border-neutral-700 dark:bg-neutral-900">
                    Closed. Recoverable until{' '}
                    {formatDate(workspace.purge_after)}, then anonymised.
                </div>
            )}

            <div className="grid gap-4 lg:grid-cols-3">
                <Card className="lg:col-span-2">
                    <CardHeader>
                        <CardTitle className="text-base">Plan</CardTitle>
                    </CardHeader>
                    <CardContent className="text-sm">
                        {subscription === null ? (
                            <p className="text-muted-foreground">
                                No subscription. This workspace is on the free
                                tier and does not exist at the payment provider.
                            </p>
                        ) : (
                            <dl className="grid gap-x-6 gap-y-2 sm:grid-cols-2">
                                <Field label="Plan" value={subscription.plan} />
                                <Field
                                    label="Status"
                                    value={subscription.status}
                                />
                                <Field
                                    label="Price"
                                    value={`${formatMoney(subscription.amount_minor, subscription.currency)} / ${subscription.interval}`}
                                />
                                <Field
                                    label="Source"
                                    value={
                                        subscription.billing_source === 'manual'
                                            ? 'Granted by hand'
                                            : 'Payment provider'
                                    }
                                />
                                {subscription.trial_ends_at && (
                                    <Field
                                        label="Trial ends"
                                        value={formatDate(
                                            subscription.trial_ends_at,
                                        )}
                                    />
                                )}
                                <Field
                                    label="Period ends"
                                    value={formatDate(
                                        subscription.current_period_end,
                                    )}
                                />
                                {subscription.grant_reason && (
                                    <Field
                                        className="sm:col-span-2"
                                        label={`Granted${subscription.granted_by ? ` by ${subscription.granted_by}` : ''}`}
                                        value={subscription.grant_reason}
                                    />
                                )}
                            </dl>
                        )}

                        {/*
                         * `dodo` with no provider id is a broken sync, not a
                         * comp - that distinction is the whole reason
                         * billing_source exists.
                         */}
                        {subscription?.is_missing_provider_record && (
                            <p className="mt-3 rounded-md bg-red-50 p-2 text-xs text-red-800 dark:bg-red-950 dark:text-red-200">
                                This subscription claims a provider record but
                                has no provider id. The sync is broken.
                            </p>
                        )}
                    </CardContent>
                </Card>

                <Card>
                    <CardHeader>
                        <CardTitle className="text-base">Seats</CardTitle>
                    </CardHeader>
                    <CardContent>
                        <p className="text-2xl font-semibold">
                            {seats.used}
                            <span className="text-base font-normal text-muted-foreground">
                                {' '}
                                / {formatLimit(seats.limit)}
                            </span>
                        </p>
                        <p className="mt-1 text-xs text-muted-foreground">
                            Pending invitations reserve a seat.
                        </p>
                    </CardContent>
                </Card>
            </div>

            {/* Section 5: usage against EVERY limit, not just a breached one. */}
            <NextTable<CustomerEntitlement>
                id="feature"
                title="Limits and usage"
                description="Every limit this workspace resolves against, not just one it has breached."
                columns={entitlementColumns}
                load={detail<CustomerEntitlement>('entitlements')}
                params={{ _refresh: refresh }}
                searchPlaceholder="Search features..."
            />

            <NextTable<CustomerOverride>
                id="id"
                title="Staff overrides"
                description="Section 10: a limit lifted for this one customer, and who signed it off."
                columns={overrideColumns}
                load={detail<CustomerOverride>('overrides')}
                params={{ _refresh: refresh }}
                searchPlaceholder="Search features..."
            />

            <NextTable<CustomerMember>
                id="id"
                title="People"
                description="Everyone with access, and the role deciding what they may do."
                columns={memberColumns}
                load={detail<CustomerMember>('members')}
                params={{ _refresh: refresh }}
                searchPlaceholder="Search by name or email..."
            />

            {/* Section 8: we keep a summary; the document stays with the provider. */}
            <NextTable<CustomerInvoice>
                id="id"
                title="Payment history"
                description="A summary of what was charged. The invoice document itself lives with the payment provider."
                columns={invoiceColumns}
                load={detail<CustomerInvoice>('invoices')}
                params={{ _refresh: refresh }}
                searchPlaceholder="Search by number or status..."
            />
        </div>
    );
}

function Field({
    label,
    value,
    className,
}: {
    label: string;
    value: string;
    className?: string;
}) {
    return (
        <div className={className}>
            <dt className="text-xs text-muted-foreground">{label}</dt>
            <dd className="font-medium">{value}</dd>
        </div>
    );
}

CustomerShow.layout = (page: React.ReactNode) => (
    <AdminLayout>{page}</AdminLayout>
);
