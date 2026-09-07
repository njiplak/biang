import { Head, Link, router, usePage } from '@inertiajs/react';
import { ArrowLeft, ShieldOff, ShieldCheck } from 'lucide-react';

import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
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
import type { SharedData } from '@/types';
import type { CustomerOverview } from '@/types/customer';
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

export default function CustomerShow({
    workspace,
    subscription,
    seats,
    members,
    entitlements,
    overrides,
    invoices,
    plans,
    features,
}: CustomerOverview) {
    const { permissions } = usePage<SharedData>().props.auth;
    const can = (permission: string) => permissions.includes(permission);

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
            <Card>
                <CardHeader>
                    <CardTitle className="text-base">Limits and usage</CardTitle>
                </CardHeader>
                <CardContent className="overflow-x-auto p-0">
                    <Table>
                        <TableHeader>
                            <TableRow>
                                <TableHead>Feature</TableHead>
                                <TableHead>Used</TableHead>
                                <TableHead>Limit</TableHead>
                                <TableHead>From</TableHead>
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            {entitlements.length === 0 && (
                                <TableRow>
                                    <TableCell
                                        colSpan={4}
                                        className="text-muted-foreground"
                                    >
                                        No entitlements resolved yet.
                                    </TableCell>
                                </TableRow>
                            )}
                            {entitlements.map((entitlement) => (
                                <TableRow key={entitlement.feature}>
                                    <TableCell>{entitlement.feature}</TableCell>
                                    <TableCell>{entitlement.used}</TableCell>
                                    <TableCell>
                                        {formatLimit(entitlement.limit)}
                                    </TableCell>
                                    <TableCell className="text-muted-foreground">
                                        {entitlement.source}
                                    </TableCell>
                                </TableRow>
                            ))}
                        </TableBody>
                    </Table>
                </CardContent>
            </Card>

            {overrides.length > 0 && (
                <Card>
                    <CardHeader>
                        <CardTitle className="text-base">
                            Staff overrides
                        </CardTitle>
                    </CardHeader>
                    <CardContent className="overflow-x-auto p-0">
                        <Table>
                            <TableHeader>
                                <TableRow>
                                    <TableHead>Feature</TableHead>
                                    <TableHead>Value</TableHead>
                                    <TableHead>Reason</TableHead>
                                    <TableHead>Granted by</TableHead>
                                    <TableHead>Expires</TableHead>
                                    <TableHead />
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                {overrides.map((override) => (
                                    <TableRow key={override.id}>
                                        <TableCell>
                                            {override.feature_name}
                                        </TableCell>
                                        <TableCell>
                                            {formatLimit(override.value)}
                                        </TableCell>
                                        <TableCell>{override.reason}</TableCell>
                                        <TableCell className="text-muted-foreground">
                                            {override.granted_by ?? '-'}
                                        </TableCell>
                                        <TableCell className="text-muted-foreground">
                                            {formatDate(override.expires_at)}
                                        </TableCell>
                                        <TableCell className="text-right">
                                            {can('billing.override') &&
                                                !isDeleted && (
                                                    <Button
                                                        variant="ghost"
                                                        size="sm"
                                                        className="text-red-600 hover:text-red-600"
                                                        onClick={() =>
                                                            router.delete(
                                                                admin.customer.override.destroy(
                                                                    {
                                                                        workspace:
                                                                            workspace.ulid,
                                                                        override:
                                                                            override.id,
                                                                    },
                                                                ).url,
                                                            )
                                                        }
                                                    >
                                                        Revoke
                                                    </Button>
                                                )}
                                        </TableCell>
                                    </TableRow>
                                ))}
                            </TableBody>
                        </Table>
                    </CardContent>
                </Card>
            )}

            <Card>
                <CardHeader>
                    <CardTitle className="text-base">
                        People ({members.length})
                    </CardTitle>
                </CardHeader>
                <CardContent className="overflow-x-auto p-0">
                    <Table>
                        <TableHeader>
                            <TableRow>
                                <TableHead>Name</TableHead>
                                <TableHead>Email</TableHead>
                                <TableHead>Role</TableHead>
                                <TableHead>Joined</TableHead>
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            {members.map((member) => (
                                <TableRow key={member.id}>
                                    <TableCell>{member.name ?? '-'}</TableCell>
                                    <TableCell>{member.email ?? '-'}</TableCell>
                                    <TableCell>
                                        {member.role_label}
                                        {member.is_owner && (
                                            <span className="ml-2 rounded bg-neutral-100 px-1.5 py-0.5 text-xs text-neutral-700 dark:bg-neutral-800 dark:text-neutral-300">
                                                billing contact
                                            </span>
                                        )}
                                    </TableCell>
                                    <TableCell className="text-muted-foreground">
                                        {formatDate(member.joined_at)}
                                    </TableCell>
                                </TableRow>
                            ))}
                        </TableBody>
                    </Table>
                </CardContent>
            </Card>

            {/* Section 8: we keep a summary; the document stays with the provider. */}
            <Card>
                <CardHeader>
                    <CardTitle className="text-base">Payment history</CardTitle>
                </CardHeader>
                <CardContent className="overflow-x-auto p-0">
                    <Table>
                        <TableHeader>
                            <TableRow>
                                <TableHead>Invoice</TableHead>
                                <TableHead>Status</TableHead>
                                <TableHead>Total</TableHead>
                                <TableHead>Issued</TableHead>
                                <TableHead>Paid</TableHead>
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            {invoices.length === 0 && (
                                <TableRow>
                                    <TableCell
                                        colSpan={5}
                                        className="text-muted-foreground"
                                    >
                                        No invoices. A workspace that has never
                                        paid has none.
                                    </TableCell>
                                </TableRow>
                            )}
                            {invoices.map((invoice) => (
                                <TableRow key={invoice.id}>
                                    <TableCell>
                                        {invoice.hosted_url ? (
                                            <a
                                                className="underline"
                                                href={invoice.hosted_url}
                                                target="_blank"
                                                rel="noreferrer"
                                            >
                                                {invoice.number ?? invoice.id}
                                            </a>
                                        ) : (
                                            (invoice.number ?? invoice.id)
                                        )}
                                    </TableCell>
                                    <TableCell>{invoice.status}</TableCell>
                                    <TableCell>
                                        {formatMoney(
                                            invoice.total_minor,
                                            invoice.currency,
                                        )}
                                    </TableCell>
                                    <TableCell className="text-muted-foreground">
                                        {formatDate(invoice.issued_at)}
                                    </TableCell>
                                    <TableCell className="text-muted-foreground">
                                        {formatDate(invoice.paid_at)}
                                    </TableCell>
                                </TableRow>
                            ))}
                        </TableBody>
                    </Table>
                </CardContent>
            </Card>
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
