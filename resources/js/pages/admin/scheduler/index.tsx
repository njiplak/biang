import { Head } from '@inertiajs/react';
import { AlertTriangle, CheckCircle2, CircleSlash } from 'lucide-react';

import { Badge } from '@/components/ui/badge';
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
import type { SchedulerOverview } from '@/types/catalog';

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

            <Card>
                <CardHeader>
                    <CardTitle className="text-base">Tasks</CardTitle>
                </CardHeader>
                <CardContent className="overflow-x-auto p-0">
                    <Table>
                        <TableHeader>
                            <TableRow>
                                <TableHead>Task</TableHead>
                                <TableHead>Schedule</TableHead>
                                <TableHead>Last finished</TableHead>
                                <TableHead>Expected by</TableHead>
                                <TableHead>State</TableHead>
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            {tasks.length === 0 && (
                                <TableRow>
                                    <TableCell
                                        colSpan={5}
                                        className="text-muted-foreground"
                                    >
                                        Nothing registered.
                                    </TableCell>
                                </TableRow>
                            )}
                            {tasks.map((task) => (
                                <TableRow key={task.id}>
                                    <TableCell className="font-medium">
                                        {task.name}
                                    </TableCell>
                                    <TableCell className="text-muted-foreground">
                                        <code>{task.cron_expression}</code>
                                        <span className="ml-2 text-xs">
                                            +{task.grace_time_in_minutes}m grace
                                        </span>
                                    </TableCell>
                                    <TableCell className="text-muted-foreground">
                                        {when(task.last_finished_at)}
                                    </TableCell>
                                    <TableCell className="text-muted-foreground">
                                        {when(task.expected_by)}
                                    </TableCell>
                                    <TableCell>
                                        {task.is_healthy ? (
                                            <Badge>Healthy</Badge>
                                        ) : task.has_failed ? (
                                            <Badge variant="destructive">
                                                Failed
                                            </Badge>
                                        ) : (
                                            <Badge variant="destructive">
                                                Overdue
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

SchedulerIndex.layout = (page: React.ReactNode) => (
    <AdminLayout>{page}</AdminLayout>
);
