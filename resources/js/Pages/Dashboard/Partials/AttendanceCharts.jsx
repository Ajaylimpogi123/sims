import { Cell, Line, LineChart, Pie, PieChart, CartesianGrid, XAxis, YAxis } from "recharts";
import { Card, CardContent, CardHeader, CardTitle } from "@/Components/ui/card";
import {
    ChartContainer,
    ChartTooltip,
    ChartTooltipContent,
} from "@/Components/ui/chart";
import EmptyState from "@/Components/EmptyState";
import { formatDate } from "@/lib/dates";

const OUTCOME_COLORS = {
    present: "hsl(160, 84%, 39%)",
    rejected: "hsl(0, 84%, 60%)",
};

const chartConfig = {
    present: { label: "Present", color: OUTCOME_COLORS.present },
    rejected: { label: "Rejected", color: OUTCOME_COLORS.rejected },
};

/**
 * Attendance outcomes ship as Present + Rejected only — there is no
 * absent/late status or structured schedule in this codebase, so no such
 * segment is fabricated here (see App\Services\DashboardAnalyticsService).
 */
export default function AttendanceCharts({ data }) {
    const outcomes = data?.outcomes ?? [];
    const trend = data?.trend ?? [];
    const frequentRejections = data?.frequentRejections ?? [];
    const hasOutcomes = outcomes.some((row) => row.total > 0);

    return (
        <div className="grid grid-cols-1 gap-4 lg:grid-cols-3">
            <Card className="border-0 shadow-sm">
                <CardHeader>
                    <CardTitle>Attendance Outcomes</CardTitle>
                </CardHeader>
                <CardContent>
                    {!hasOutcomes ? (
                        <EmptyState message="No attendance recorded yet." />
                    ) : (
                        <ChartContainer config={chartConfig} className="mx-auto aspect-square h-64">
                            <PieChart>
                                <ChartTooltip content={<ChartTooltipContent hideLabel />} />
                                <Pie data={outcomes} dataKey="total" nameKey="outcome" innerRadius={50}>
                                    {outcomes.map((row) => (
                                        <Cell key={row.outcome} fill={OUTCOME_COLORS[row.outcome]} />
                                    ))}
                                </Pie>
                            </PieChart>
                        </ChartContainer>
                    )}
                </CardContent>
            </Card>

            <Card className="border-0 shadow-sm">
                <CardHeader>
                    <CardTitle>Attendance Trend</CardTitle>
                </CardHeader>
                <CardContent>
                    {trend.length === 0 ? (
                        <EmptyState message="No attendance recorded in this range." />
                    ) : (
                        <ChartContainer config={chartConfig} className="aspect-auto h-64 w-full">
                            <LineChart data={trend}>
                                <CartesianGrid vertical={false} />
                                <XAxis
                                    dataKey="date"
                                    tickLine={false}
                                    axisLine={false}
                                    tickFormatter={(value) => formatDate(value, value)}
                                />
                                <YAxis allowDecimals={false} tickLine={false} axisLine={false} />
                                <ChartTooltip content={<ChartTooltipContent />} />
                                <Line type="monotone" dataKey="present" stroke="var(--color-present)" strokeWidth={2} dot={false} />
                                <Line type="monotone" dataKey="rejected" stroke="var(--color-rejected)" strokeWidth={2} dot={false} />
                            </LineChart>
                        </ChartContainer>
                    )}
                </CardContent>
            </Card>

            <Card className="border-0 shadow-sm">
                <CardHeader>
                    <CardTitle>Frequent Rejections</CardTitle>
                </CardHeader>
                <CardContent>
                    {frequentRejections.length === 0 ? (
                        <EmptyState message="No students with repeated rejections." />
                    ) : (
                        <ul className="divide-y">
                            {frequentRejections.map((row) => (
                                <li
                                    key={row.student_id}
                                    className="flex items-center justify-between py-2 text-sm"
                                >
                                    <span>{row.name}</span>
                                    <span className="font-medium text-red-600">
                                        {row.rejection_count} rejected
                                    </span>
                                </li>
                            ))}
                        </ul>
                    )}
                </CardContent>
            </Card>
        </div>
    );
}
