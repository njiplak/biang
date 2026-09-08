import { Head, Link } from '@inertiajs/react';
import { Megaphone, Tags, Users } from 'lucide-react';

import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import AdminLayout from '@/layouts/admin-layout';
import RevenueTrends from './revenue-trends';
import admin from '@/routes/admin';
import type { RevenueSummary } from '@/types/customer';

type Props = {
    admin: { name: string; email: string };
    revenue: RevenueSummary | null;
};

function money(minor: number, currency: string) {
    return `${currency} ${(minor / 100).toLocaleString(undefined, {
        minimumFractionDigits: 0,
        maximumFractionDigits: 0,
    })}`;
}

/** Section 10's six jobs. Each links to the screen that does it. */
export default function AdminDashboard({ admin: staff, revenue }: Props) {
    return (
        <div className="flex flex-col gap-4">
            <Head title="Staff console" />

            <div className="flex flex-col">
                <h1 className="text-xl font-semibold">Staff console</h1>
                <p className="text-sm text-muted-foreground">
                    Signed in as {staff.name} ({staff.email})
                </p>
            </div>

            {/* Section 10: "Understand the business." Finance only. */}
            {revenue && (
                <>
                    <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                        <Stat
                            label="MRR"
                            value={money(revenue.mrr_minor, revenue.currency)}
                            hint={`ARR ${money(revenue.arr_minor, revenue.currency)}`}
                        />
                        <Stat
                            label="Trial to paid"
                            value={
                                revenue.trials.conversion_rate === null
                                    ? 'No data'
                                    : `${revenue.trials.conversion_rate}%`
                            }
                            hint={`${revenue.trials.converted_total} of ${revenue.trials.ended_total} ended trials`}
                        />
                        <Stat
                            label="Trials running"
                            value={String(revenue.trials.running)}
                            hint={`${revenue.signups.workspaces_this_week} new workspaces this week`}
                        />
                        <Stat
                            label="Churn (30d)"
                            value={
                                revenue.churn.rate === null
                                    ? 'No data'
                                    : `${revenue.churn.rate}%`
                            }
                            hint={`${revenue.churn.canceled_30d} cancelled, ${revenue.churn.live} live`}
                        />
                    </div>

                    <div className="grid gap-4 lg:grid-cols-2">
                        <Card>
                            <CardHeader>
                                <CardTitle className="text-base">
                                    Which plans are selling
                                </CardTitle>
                            </CardHeader>
                            <CardContent className="text-sm">
                                {revenue.plans.length === 0 ? (
                                    <p className="text-muted-foreground">
                                        Nobody is on a paid plan yet.
                                    </p>
                                ) : (
                                    <ul className="flex flex-col gap-2">
                                        {revenue.plans.map((plan) => (
                                            <li
                                                key={plan.plan}
                                                className="flex items-center justify-between gap-3"
                                            >
                                                <span>{plan.plan}</span>
                                                <span className="text-muted-foreground">
                                                    {plan.customers} ·{' '}
                                                    {money(
                                                        plan.mrr_minor,
                                                        revenue.currency,
                                                    )}
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
                                    Not counted as revenue
                                </CardTitle>
                            </CardHeader>
                            <CardContent className="flex flex-col gap-2 text-sm">
                                <div className="flex items-center justify-between">
                                    <span>
                                        Past due
                                        <span className="ml-1 text-muted-foreground">
                                            (keeps full access)
                                        </span>
                                    </span>
                                    <span className="text-muted-foreground">
                                        {revenue.at_risk_count} ·{' '}
                                        {money(
                                            revenue.at_risk_minor,
                                            revenue.currency,
                                        )}
                                    </span>
                                </div>
                                <div className="flex items-center justify-between">
                                    <span>
                                        Comped
                                        <span className="ml-1 text-muted-foreground">
                                            (granted by hand)
                                        </span>
                                    </span>
                                    <span className="text-muted-foreground">
                                        {revenue.comped_count}
                                    </span>
                                </div>
                                <div className="flex items-center justify-between">
                                    <span>Signups this week</span>
                                    <span className="text-muted-foreground">
                                        {revenue.signups.users_this_week} people
                                        · {revenue.signups.users_total} total
                                    </span>
                                </div>
                            </CardContent>
                        </Card>
                    </div>

                    <RevenueTrends
                        trends={revenue.trends}
                        recovery={revenue.recovery}
                    />
                </>
            )}

            <div className="grid gap-4 sm:grid-cols-3">
                <JobCard
                    href={admin.customer.index.url()}
                    icon={<Users className="size-4 text-muted-foreground" />}
                    title="Customers"
                    body="Find anyone by email or workspace name, then grant, override, suspend or enter their account."
                />
                <JobCard
                    href={admin.catalog.index.url()}
                    icon={<Tags className="size-4 text-muted-foreground" />}
                    title="Plans and add-ons"
                    body="Change what we sell. Retire a plan without breaking the customers already on it."
                />
                <JobCard
                    href={admin.announcement.index.url()}
                    icon={
                        <Megaphone className="size-4 text-muted-foreground" />
                    }
                    title="Announcements"
                    body="Tell every customer about maintenance or a new feature."
                />
            </div>
        </div>
    );
}

function Stat({
    label,
    value,
    hint,
}: {
    label: string;
    value: string;
    hint: string;
}) {
    return (
        <Card>
            <CardHeader className="pb-2">
                <CardTitle className="text-xs font-medium text-muted-foreground">
                    {label}
                </CardTitle>
            </CardHeader>
            <CardContent>
                <p className="text-2xl font-semibold">{value}</p>
                <p className="mt-1 text-xs text-muted-foreground">{hint}</p>
            </CardContent>
        </Card>
    );
}

function JobCard({
    href,
    icon,
    title,
    body,
}: {
    href: string;
    icon: React.ReactNode;
    title: string;
    body: string;
}) {
    return (
        <Link href={href}>
            <Card className="h-full transition-colors hover:border-primary">
                <CardHeader className="flex flex-row items-center gap-2 space-y-0">
                    {icon}
                    <CardTitle className="text-base">{title}</CardTitle>
                </CardHeader>
                <CardContent className="text-sm text-muted-foreground">
                    {body}
                </CardContent>
            </Card>
        </Link>
    );
}

AdminDashboard.layout = (page: React.ReactNode) => (
    <AdminLayout>{page}</AdminLayout>
);
