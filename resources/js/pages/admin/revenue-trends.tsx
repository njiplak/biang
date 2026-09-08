import {
    Bar,
    BarChart,
    CartesianGrid,
    Line,
    LineChart,
    XAxis,
    YAxis,
} from 'recharts';

import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import {
    ChartContainer,
    ChartLegend,
    ChartLegendContent,
    ChartTooltip,
    ChartTooltipContent,
    type ChartConfig,
} from '@/components/ui/chart';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import type { RevenueMonth, RevenueSummary } from '@/types/customer';

/**
 * Section 15 asks for movement, not a snapshot: "trial to paid conversion - the
 * number this whole build exists to move", and "monthly churn, and how much of
 * it is voluntary versus failed payments".
 *
 * Three charts rather than one, because these are three different units. A
 * percentage and a headcount on one pair of axes is a dual-axis chart, which
 * can be made to tell any story you like by choosing the two scales.
 *
 * Colours come from the design system's own --chart-1 and --chart-2 in fixed
 * order, never cycled. The table underneath is not decoration: --chart-1 in
 * dark mode sits at 2.9:1 against the card, under the 3:1 that would let colour
 * carry a value on its own, so the exact numbers are always readable as text.
 */

const conversionConfig = {
    conversion_rate: { label: 'Converted', color: 'var(--chart-1)' },
} satisfies ChartConfig;

const signupConfig = {
    signups_users: { label: 'People', color: 'var(--chart-1)' },
    signups_workspaces: { label: 'Workspaces', color: 'var(--chart-2)' },
} satisfies ChartConfig;

const churnConfig = {
    churn_voluntary: { label: 'Left by choice', color: 'var(--chart-1)' },
    churn_involuntary: { label: 'Payment failed', color: 'var(--chart-2)' },
} satisfies ChartConfig;

const axis = {
    tickLine: false,
    axisLine: false,
    tickMargin: 8,
} as const;

export default function RevenueTrends({
    trends,
    recovery,
}: Pick<RevenueSummary, 'trends' | 'recovery'>) {
    const quiet = trends.every(
        (month) =>
            month.signups_users === 0 &&
            month.trials_ended === 0 &&
            month.churn_voluntary === 0 &&
            month.churn_involuntary === 0,
    );

    return (
        <div className="flex flex-col gap-4">
            <div className="flex flex-wrap items-baseline justify-between gap-2">
                <h2 className="text-base font-semibold">
                    How it is moving
                    <span className="ml-2 text-sm font-normal text-muted-foreground">
                        last {trends.length} months
                    </span>
                </h2>
                <p className="text-sm text-muted-foreground">
                    {/* A single headline number is a stat, not a chart. */}
                    Failed payments recovered:{' '}
                    <span className="font-medium text-foreground">
                        {recovery.rate === null
                            ? 'No data'
                            : `${recovery.rate}%`}
                    </span>{' '}
                    ({recovery.recovered} of {recovery.resolved} episodes)
                </p>
            </div>

            {quiet ? (
                <Card>
                    <CardContent className="py-6 text-sm text-muted-foreground">
                        Nothing has happened yet in the last {trends.length}{' '}
                        months — no signups, no trials ending, nobody leaving.
                    </CardContent>
                </Card>
            ) : (
                <div className="grid gap-4 lg:grid-cols-2">
                    {/*
                     * The number section 15 says the build exists to move, so
                     * it goes first and gets the full width.
                     */}
                    <Card className="lg:col-span-2">
                        <CardHeader>
                            <CardTitle className="text-base">
                                Trial to paid
                            </CardTitle>
                            <p className="text-sm text-muted-foreground">
                                Measured on the month a trial ran out, which is
                                when it either converted or did not. A month
                                with no trials ending has no rate, and is drawn
                                as a gap rather than joined up.
                            </p>
                        </CardHeader>
                        <CardContent>
                            <ChartContainer
                                config={conversionConfig}
                                className="aspect-auto h-56 w-full"
                            >
                                <LineChart
                                    data={trends}
                                    margin={{ left: 4, right: 12 }}
                                >
                                    <CartesianGrid vertical={false} />
                                    <XAxis dataKey="label" {...axis} />
                                    <YAxis
                                        {...axis}
                                        width={44}
                                        domain={[0, 100]}
                                        tickFormatter={(v) => `${v}%`}
                                    />
                                    <ChartTooltip
                                        content={
                                            <ChartTooltipContent
                                                labelKey="label"
                                                formatter={(value, _n, item) =>
                                                    conversionLabel(
                                                        value,
                                                        item?.payload as RevenueMonth,
                                                    )
                                                }
                                            />
                                        }
                                    />
                                    <Line
                                        dataKey="conversion_rate"
                                        type="monotone"
                                        stroke="var(--color-conversion_rate)"
                                        strokeWidth={2}
                                        // A month with no ended trials is a gap
                                        // in what we know, not a drop to zero.
                                        connectNulls={false}
                                        dot={{ r: 4 }}
                                        activeDot={{ r: 5 }}
                                    />
                                </LineChart>
                            </ChartContainer>
                        </CardContent>
                    </Card>

                    <Card>
                        <CardHeader>
                            <CardTitle className="text-base">Signups</CardTitle>
                            <p className="text-sm text-muted-foreground">
                                Section 15 counts both: somebody who signs up
                                and never names a workspace has not become a
                                customer, and the gap is the thing to watch.
                            </p>
                        </CardHeader>
                        <CardContent>
                            <ChartContainer
                                config={signupConfig}
                                className="aspect-auto h-56 w-full"
                            >
                                <BarChart
                                    data={trends}
                                    margin={{ left: 4, right: 12 }}
                                >
                                    <CartesianGrid vertical={false} />
                                    <XAxis dataKey="label" {...axis} />
                                    <YAxis
                                        {...axis}
                                        width={32}
                                        allowDecimals={false}
                                    />
                                    <ChartTooltip
                                        content={
                                            <ChartTooltipContent labelKey="label" />
                                        }
                                    />
                                    <ChartLegend
                                        content={<ChartLegendContent />}
                                    />
                                    <Bar
                                        dataKey="signups_users"
                                        fill="var(--color-signups_users)"
                                        radius={[4, 4, 0, 0]}
                                    />
                                    <Bar
                                        dataKey="signups_workspaces"
                                        fill="var(--color-signups_workspaces)"
                                        radius={[4, 4, 0, 0]}
                                    />
                                </BarChart>
                            </ChartContainer>
                        </CardContent>
                    </Card>

                    <Card>
                        <CardHeader>
                            <CardTitle className="text-base">
                                Customers lost
                            </CardTitle>
                            <p className="text-sm text-muted-foreground">
                                Stacked because the two are parts of one total.
                                A cancelled trial is not in here — it was never
                                a customer to lose.
                            </p>
                        </CardHeader>
                        <CardContent>
                            <ChartContainer
                                config={churnConfig}
                                className="aspect-auto h-56 w-full"
                            >
                                <BarChart
                                    data={trends}
                                    margin={{ left: 4, right: 12 }}
                                >
                                    <CartesianGrid vertical={false} />
                                    <XAxis dataKey="label" {...axis} />
                                    <YAxis
                                        {...axis}
                                        width={32}
                                        allowDecimals={false}
                                    />
                                    <ChartTooltip
                                        content={
                                            <ChartTooltipContent labelKey="label" />
                                        }
                                    />
                                    <ChartLegend
                                        content={<ChartLegendContent />}
                                    />
                                    {/*
                                     * stroke in the card colour is the 2px gap
                                     * between stacked segments - without it
                                     * two fills meet and read as one block.
                                     */}
                                    <Bar
                                        dataKey="churn_voluntary"
                                        stackId="lost"
                                        fill="var(--color-churn_voluntary)"
                                        stroke="var(--card)"
                                        strokeWidth={2}
                                    />
                                    <Bar
                                        dataKey="churn_involuntary"
                                        stackId="lost"
                                        fill="var(--color-churn_involuntary)"
                                        stroke="var(--card)"
                                        strokeWidth={2}
                                        radius={[4, 4, 0, 0]}
                                    />
                                </BarChart>
                            </ChartContainer>
                        </CardContent>
                    </Card>
                </div>
            )}

            {/*
             * The same numbers as text. Required rather than optional: the
             * dark palette's first slot does not clear 3:1 against the card,
             * so a value must never depend on reading a colour.
             */}
            <Card>
                <CardHeader>
                    <CardTitle className="text-base">Month by month</CardTitle>
                </CardHeader>
                <CardContent className="overflow-x-auto">
                    <Table>
                        <TableHeader>
                            <TableRow>
                                <TableHead>Month</TableHead>
                                <TableHead className="text-right">
                                    People
                                </TableHead>
                                <TableHead className="text-right">
                                    Workspaces
                                </TableHead>
                                <TableHead className="text-right">
                                    Trials ended
                                </TableHead>
                                <TableHead className="text-right">
                                    Converted
                                </TableHead>
                                <TableHead className="text-right">
                                    Rate
                                </TableHead>
                                <TableHead className="text-right">
                                    Left by choice
                                </TableHead>
                                <TableHead className="text-right">
                                    Payment failed
                                </TableHead>
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            {trends.map((month) => (
                                <TableRow key={month.month}>
                                    <TableCell className="font-medium">
                                        {month.label}
                                    </TableCell>
                                    <TableCell className="text-right">
                                        {month.signups_users}
                                    </TableCell>
                                    <TableCell className="text-right">
                                        {month.signups_workspaces}
                                    </TableCell>
                                    <TableCell className="text-right">
                                        {month.trials_ended}
                                    </TableCell>
                                    <TableCell className="text-right">
                                        {month.trials_converted}
                                    </TableCell>
                                    <TableCell className="text-right">
                                        {month.conversion_rate === null
                                            ? '—'
                                            : `${month.conversion_rate}%`}
                                    </TableCell>
                                    <TableCell className="text-right">
                                        {month.churn_voluntary}
                                    </TableCell>
                                    <TableCell className="text-right">
                                        {month.churn_involuntary}
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

/** The rate on its own hides how few trials it can be measured over. */
function conversionLabel(value: unknown, month: RevenueMonth | undefined) {
    if (value === null || value === undefined) return 'No trials ended';

    return `${value}% — ${month?.trials_converted ?? 0} of ${month?.trials_ended ?? 0}`;
}
