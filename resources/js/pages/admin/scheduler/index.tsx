import { Head } from '@inertiajs/react';
import { createColumnHelper, type ColumnDef } from '@tanstack/react-table';
import { AlertTriangle, CheckCircle2, CircleSlash } from 'lucide-react';
import { useCallback } from 'react';

import NextTable from '@/components/next-table';
import { Badge } from '@/components/ui/badge';
import AdminLayout from '@/layouts/admin-layout';
import admin from '@/routes/admin';
import type { Base } from '@/types/base';
import type { ScheduledTaskRow, SchedulerOverview } from '@/types/catalog';

const helper = createColumnHelper<ScheduledTaskRow>();

function when(value: string | null) {
    if (!value) return 'Never';
    return new Date(value).toLocaleString('en-GB', {
        day: '2-digit',
        month: 'short',
        hour: '2-digit',
        minute: '2-digit',
    });
}

/**
 * Section 16: the trial-ending emails are a launch blocker and a scheduled
 * command sends them. A scheduler that silently stopped looks exactly like one
 * with nothing to do, which is the whole reason this screen exists.
 */
export default function SchedulerIndex({
    tasks,
    is_registered,
    unhealthy_count,
}: SchedulerOverview) {
    const load = useCallback(
        async (params: Record<string, any>) =>
            (await window
                .fetch(admin.scheduler.fetch({ query: params }).url, {
                    headers: { Accept: 'application/json' },
                })
                .then((r) => r.json())) as Base<ScheduledTaskRow[]>,
        [],
    );

    const columns: ColumnDef<ScheduledTaskRow, any>[] = [
        helper.accessor('name', {
            id: 'name',
            header: 'Task',
            enableColumnFilter: false,
            cell: (ctx) => (
                <span className="font-medium">{ctx.row.original.name}</span>
            ),
        }),
        helper.display({
            id: 'schedule',
            header: 'Schedule',
            cell: (ctx) => (
                <span className="text-muted-foreground">
                    <code>{ctx.row.original.cron_expression}</code>
                    <span className="ml-2 text-xs">
                        +{ctx.row.original.grace_time_in_minutes}m grace
                    </span>
                </span>
            ),
        }),
        helper.display({
            id: 'last_finished',
            header: 'Last finished',
            cell: (ctx) => (
                <span className="text-muted-foreground">
                    {when(ctx.row.original.last_finished_at)}
                </span>
            ),
        }),
        helper.display({
            id: 'expected_by',
            header: 'Expected by',
            cell: (ctx) => (
                <span className="text-muted-foreground">
                    {when(ctx.row.original.expected_by)}
                </span>
            ),
        }),
        helper.display({
            id: 'state',
            header: 'State',
            cell: (ctx) => {
                const task = ctx.row.original;
                if (task.is_healthy) return <Badge>Healthy</Badge>;

                // Failed and overdue are different problems: one ran and threw,
                // the other never ran at all.
                return (
                    <Badge variant="destructive">
                        {task.has_failed ? 'Failed' : 'Overdue'}
                    </Badge>
                );
            },
        }),
    ];

    return (
        <div className="flex flex-col gap-4">
            <Head title="Scheduled tasks" />

            <div className="flex flex-col">
                <h1 className="text-xl font-semibold">Scheduled tasks</h1>
                <p className="text-sm text-muted-foreground">
                    Trial warnings, trial conversion, dunning expiry and the
                    workspace purge.
                </p>
            </div>

            {/* "No tasks" and "all green" render identically as an empty list. */}
            {!is_registered && (
                <div className="flex items-start gap-2 rounded-md border border-amber-300 bg-amber-50 p-3 text-sm text-amber-950 dark:border-amber-800 dark:bg-amber-950 dark:text-amber-100">
                    <CircleSlash className="mt-0.5 size-4 shrink-0" />
                    <div>
                        <p className="font-medium">
                            No task is registered for monitoring.
                        </p>
                        <p>
                            That is not the same as everything being healthy —
                            run{' '}
                            <code className="rounded bg-black/10 px-1 dark:bg-white/10">
                                php artisan schedule-monitor:sync
                            </code>
                            .
                        </p>
                    </div>
                </div>
            )}

            {is_registered && unhealthy_count === 0 && (
                <div className="flex items-center gap-2 rounded-md border border-green-200 bg-green-50 p-3 text-sm text-green-900 dark:border-green-900 dark:bg-green-950 dark:text-green-200">
                    <CheckCircle2 className="size-4 shrink-0" />
                    All {tasks.length} tasks ran inside their window.
                </div>
            )}

            {unhealthy_count > 0 && (
                <div className="flex items-center gap-2 rounded-md border border-red-200 bg-red-50 p-3 text-sm text-red-900 dark:border-red-900 dark:bg-red-950 dark:text-red-200">
                    <AlertTriangle className="size-4 shrink-0" />
                    {unhealthy_count} task
                    {unhealthy_count === 1 ? '' : 's'} did not run as expected.
                </div>
            )}

            <NextTable<ScheduledTaskRow>
                id="id"
                columns={columns}
                load={load}
                searchPlaceholder="Search tasks..."
            />
        </div>
    );
}

SchedulerIndex.layout = (page: React.ReactNode) => (
    <AdminLayout>{page}</AdminLayout>
);
