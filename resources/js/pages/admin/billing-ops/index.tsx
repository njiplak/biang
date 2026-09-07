import { Head, Link, router } from '@inertiajs/react';
import { AlertTriangle, CheckCircle2, Clock, RefreshCw } from 'lucide-react';

import { Badge } from '@/components/ui/badge';
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
import type { BillingOpsOverview } from '@/types/catalog';

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
 * Section 8's operational half. Everything the payment integration records when
 * something goes wrong - and, until this screen, could only be read with a
 * database query.
 */
export default function BillingOpsIndex({
    failed_webhooks,
    dunning,
    integrity,
    recent_webhooks,
}: BillingOpsOverview) {
    const healthy =
        failed_webhooks.length === 0 &&
        integrity.length === 0 &&
        dunning.length === 0;

    return (
        <div className="flex flex-col gap-4">
            <Head title="Billing operations" />

            <div className="flex flex-col">
                <h1 className="text-xl font-semibold">Billing operations</h1>
                <p className="text-sm text-muted-foreground">
                    What the payment provider told us, and what we could not
                    act on.
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
            <Card>
                <CardHeader>
                    <CardTitle className="flex items-center gap-2 text-base">
                        <AlertTriangle className="size-4 text-muted-foreground" />
                        Webhooks that failed
                        {failed_webhooks.length > 0 && (
                            <Badge variant="destructive">
                                {failed_webhooks.length}
                            </Badge>
                        )}
                    </CardTitle>
                </CardHeader>
                <CardContent className="overflow-x-auto p-0">
                    <Table>
                        <TableHeader>
                            <TableRow>
                                <TableHead>Event</TableHead>
                                <TableHead>Why</TableHead>
                                <TableHead>Attempts</TableHead>
                                <TableHead>Failed</TableHead>
                                <TableHead />
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            {failed_webhooks.length === 0 && (
                                <TableRow>
                                    <TableCell
                                        colSpan={5}
                                        className="text-muted-foreground"
                                    >
                                        Nothing failed.
                                    </TableCell>
                                </TableRow>
                            )}
                            {failed_webhooks.map((event) => (
                                <TableRow key={event.id}>
                                    <TableCell>
                                        <div className="flex flex-col">
                                            <span>{event.event_type}</span>
                                            <span className="text-xs text-muted-foreground">
                                                {event.event_id}
                                            </span>
                                        </div>
                                    </TableCell>
                                    <TableCell>{event.error ?? '-'}</TableCell>
                                    <TableCell>{event.attempts}</TableCell>
                                    <TableCell className="text-muted-foreground">
                                        {when(event.failed_at)}
                                    </TableCell>
                                    <TableCell className="text-right">
                                        {event.can_retry ? (
                                            <Button
                                                variant="outline"
                                                size="sm"
                                                onClick={() =>
                                                    router.post(
                                                        admin['billing-ops'].retry(
                                                            event.id,
                                                        ).url,
                                                    )
                                                }
                                            >
                                                <RefreshCw className="size-4" />
                                                Retry
                                            </Button>
                                        ) : (
                                            /* Never verified at intake, so
                                               replaying it would apply
                                               something we refused. */
                                            <span className="text-xs text-muted-foreground">
                                                Unverified
                                            </span>
                                        )}
                                    </TableCell>
                                </TableRow>
                            ))}
                        </TableBody>
                    </Table>
                </CardContent>
            </Card>

            {/* Section 9: access is kept during grace, so the deadline is what
                staff have to act before. */}
            <Card>
                <CardHeader>
                    <CardTitle className="flex items-center gap-2 text-base">
                        <Clock className="size-4 text-muted-foreground" />
                        Failed payments in grace
                        {dunning.length > 0 && (
                            <Badge variant="secondary">{dunning.length}</Badge>
                        )}
                    </CardTitle>
                </CardHeader>
                <CardContent className="overflow-x-auto p-0">
                    <Table>
                        <TableHeader>
                            <TableRow>
                                <TableHead>Customer</TableHead>
                                <TableHead>Plan</TableHead>
                                <TableHead>Since</TableHead>
                                <TableHead>Grace ends</TableHead>
                                <TableHead>Last failure</TableHead>
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            {dunning.length === 0 && (
                                <TableRow>
                                    <TableCell
                                        colSpan={5}
                                        className="text-muted-foreground"
                                    >
                                        Nobody is in dunning.
                                    </TableCell>
                                </TableRow>
                            )}
                            {dunning.map((row) => (
                                <TableRow key={row.id}>
                                    <TableCell>
                                        {row.workspace_ulid ? (
                                            <Link
                                                className="underline underline-offset-4"
                                                href={admin.customer.show(
                                                    row.workspace_ulid,
                                                ).url}
                                            >
                                                {row.workspace_name}
                                            </Link>
                                        ) : (
                                            (row.workspace_name ?? '-')
                                        )}
                                    </TableCell>
                                    <TableCell>{row.plan ?? '-'}</TableCell>
                                    <TableCell className="text-muted-foreground">
                                        {when(row.started_at)}
                                    </TableCell>
                                    <TableCell>
                                        {row.grace_expired ? (
                                            <Badge variant="destructive">
                                                Expired
                                            </Badge>
                                        ) : (
                                            <span className="text-muted-foreground">
                                                {when(row.grace_ends_at)}
                                            </span>
                                        )}
                                    </TableCell>
                                    <TableCell className="text-muted-foreground">
                                        {row.last_failure_message ?? '-'}
                                    </TableCell>
                                </TableRow>
                            ))}
                        </TableBody>
                    </Table>
                </CardContent>
            </Card>

            {/*
             * `source = dodo` with no provider id means the sync broke. A comp
             * is SUPPOSED to have no provider record, which is the whole reason
             * billing_source exists.
             */}
            <Card>
                <CardHeader>
                    <CardTitle className="flex items-center gap-2 text-base">
                        <AlertTriangle className="size-4 text-muted-foreground" />
                        Missing provider records
                        {integrity.length > 0 && (
                            <Badge variant="destructive">
                                {integrity.length}
                            </Badge>
                        )}
                    </CardTitle>
                </CardHeader>
                <CardContent className="text-sm">
                    {integrity.length === 0 ? (
                        <p className="text-muted-foreground">
                            Every provider-backed subscription has its provider
                            id. Comped plans are not counted — they are
                            supposed to have none.
                        </p>
                    ) : (
                        <ul className="flex flex-col gap-2">
                            {integrity.map((row) => (
                                <li
                                    key={row.id}
                                    className="flex items-center justify-between gap-3"
                                >
                                    <Link
                                        className="underline underline-offset-4"
                                        href={
                                            row.workspace_ulid
                                                ? admin.customer.show(
                                                      row.workspace_ulid,
                                                  ).url
                                                : '#'
                                        }
                                    >
                                        {row.workspace_name}
                                    </Link>
                                    <span className="text-muted-foreground">
                                        {row.plan} · {row.status}
                                    </span>
                                </li>
                            ))}
                        </ul>
                    )}
                </CardContent>
            </Card>

            <Card>
                <CardHeader>
                    <CardTitle className="text-base">
                        Recently received
                    </CardTitle>
                </CardHeader>
                <CardContent className="overflow-x-auto p-0">
                    <Table>
                        <TableHeader>
                            <TableRow>
                                <TableHead>Event</TableHead>
                                <TableHead>Received</TableHead>
                                <TableHead>Result</TableHead>
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            {recent_webhooks.length === 0 && (
                                <TableRow>
                                    <TableCell
                                        colSpan={3}
                                        className="text-muted-foreground"
                                    >
                                        Nothing has arrived yet. If you expect
                                        traffic, check the webhook URL
                                        registered with the provider.
                                    </TableCell>
                                </TableRow>
                            )}
                            {recent_webhooks.map((event) => (
                                <TableRow key={event.id}>
                                    <TableCell>{event.event_type}</TableCell>
                                    <TableCell className="text-muted-foreground">
                                        {when(event.received_at)}
                                    </TableCell>
                                    <TableCell>
                                        {event.processed_at ? (
                                            <span className="text-muted-foreground">
                                                Applied
                                            </span>
                                        ) : event.failed_at ? (
                                            <Badge variant="destructive">
                                                Failed
                                            </Badge>
                                        ) : (
                                            <Badge variant="outline">
                                                Pending
                                            </Badge>
                                        )}
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

BillingOpsIndex.layout = (page: React.ReactNode) => (
    <AdminLayout>{page}</AdminLayout>
);
