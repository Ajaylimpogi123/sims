import { Bar, BarChart, CartesianGrid, Line, LineChart, XAxis, YAxis } from "recharts";
import { Card, CardContent, CardHeader, CardTitle } from "@/Components/ui/card";
import {
    ChartContainer,
    ChartTooltip,
    ChartTooltipContent,
} from "@/Components/ui/chart";
import EmptyState from "@/Components/EmptyState";
import { formatDate } from "@/lib/dates";

const STATUS_LABELS = {
    pending: "Pending",
    reviewed: "Reviewed",
};

const chartConfig = {
    total: { label: "Reports", color: "hsl(199, 89%, 48%)" },
};

/**
 * InternshipReport::status only ever has pending/reviewed values in this
 * codebase — there is no "returned/rejected" concept, so the funnel never
 * shows such a segment (see App\Services\DashboardAnalyticsService).
 */
export default function ReportsCharts({ data }) {
    const funnel = (data?.funnel ?? []).map((row) => ({
        status: STATUS_LABELS[row.status] ?? row.status,
        total: row.total,
    }));
    const submissionTrend = data?.submissionTrend ?? [];

    return (
        <div className="grid grid-cols-1 gap-4 lg:grid-cols-2">
            <Card className="border-0 shadow-sm">
                <CardHeader>
                    <CardTitle>Reports Funnel</CardTitle>
                </CardHeader>
                <CardContent>
                    {funnel.length === 0 ? (
                        <EmptyState message="No reports submitted yet." />
                    ) : (
                        <ChartContainer config={chartConfig} className="aspect-auto h-64 w-full">
                            <BarChart data={funnel}>
                                <CartesianGrid vertical={false} />
                                <XAxis dataKey="status" tickLine={false} axisLine={false} />
                                <YAxis allowDecimals={false} tickLine={false} axisLine={false} />
                                <ChartTooltip content={<ChartTooltipContent />} />
                                <Bar dataKey="total" fill="var(--color-total)" radius={4} />
                            </BarChart>
                        </ChartContainer>
                    )}
                </CardContent>
            </Card>

            <Card className="border-0 shadow-sm">
                <CardHeader>
                    <CardTitle>Submission Trend</CardTitle>
                </CardHeader>
                <CardContent>
                    {submissionTrend.length === 0 ? (
                        <EmptyState message="No reports submitted in this range." />
                    ) : (
                        <ChartContainer config={chartConfig} className="aspect-auto h-64 w-full">
                            <LineChart data={submissionTrend}>
                                <CartesianGrid vertical={false} />
                                <XAxis
                                    dataKey="date"
                                    tickLine={false}
                                    axisLine={false}
                                    tickFormatter={(value) => formatDate(value, value)}
                                />
                                <YAxis allowDecimals={false} tickLine={false} axisLine={false} />
                                <ChartTooltip content={<ChartTooltipContent />} />
                                <Line type="monotone" dataKey="total" stroke="var(--color-total)" strokeWidth={2} dot={false} />
                            </LineChart>
                        </ChartContainer>
                    )}
                </CardContent>
            </Card>
        </div>
    );
}
