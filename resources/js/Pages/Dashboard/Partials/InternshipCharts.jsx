import { Bar, BarChart, CartesianGrid, XAxis, YAxis } from "recharts";
import { Card, CardContent, CardHeader, CardTitle } from "@/Components/ui/card";
import {
    ChartContainer,
    ChartTooltip,
    ChartTooltipContent,
} from "@/Components/ui/chart";
import EmptyState from "@/Components/EmptyState";

const STATUS_LABELS = {
    not_started: "Not Started",
    ongoing: "Ongoing",
    completed: "Completed",
};

const chartConfig = {
    total: { label: "Students", color: "hsl(160, 84%, 39%)" },
};

export default function InternshipCharts({ data }) {
    const statusBreakdown = (data?.statusBreakdown ?? []).map((row) => ({
        status: STATUS_LABELS[row.status] ?? row.status,
        total: row.total,
    }));
    const studentsByCompany = data?.studentsByCompany ?? [];
    const completionProgressBuckets = data?.completionProgressBuckets ?? [];

    return (
        <div className="grid grid-cols-1 gap-4 lg:grid-cols-3">
            <Card className="border-0 shadow-sm">
                <CardHeader>
                    <CardTitle>Internship Status Breakdown</CardTitle>
                </CardHeader>
                <CardContent>
                    {statusBreakdown.length === 0 ? (
                        <EmptyState message="No students yet." />
                    ) : (
                        <ChartContainer config={chartConfig} className="aspect-auto h-64 w-full">
                            <BarChart data={statusBreakdown}>
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
                    <CardTitle>Students by Company</CardTitle>
                </CardHeader>
                <CardContent>
                    {studentsByCompany.length === 0 ? (
                        <EmptyState message="No company assignments yet." />
                    ) : (
                        <ChartContainer config={chartConfig} className="aspect-auto h-64 w-full">
                            <BarChart data={studentsByCompany} layout="vertical">
                                <CartesianGrid horizontal={false} />
                                <XAxis type="number" allowDecimals={false} tickLine={false} axisLine={false} />
                                <YAxis
                                    dataKey="company"
                                    type="category"
                                    tickLine={false}
                                    axisLine={false}
                                    width={110}
                                />
                                <ChartTooltip content={<ChartTooltipContent />} />
                                <Bar dataKey="total" fill="var(--color-total)" radius={4} />
                            </BarChart>
                        </ChartContainer>
                    )}
                </CardContent>
            </Card>

            <Card className="border-0 shadow-sm">
                <CardHeader>
                    <CardTitle>Completion Progress</CardTitle>
                </CardHeader>
                <CardContent>
                    {completionProgressBuckets.every((b) => b.total === 0) ? (
                        <EmptyState message="No hours logged yet." />
                    ) : (
                        <ChartContainer config={chartConfig} className="aspect-auto h-64 w-full">
                            <BarChart data={completionProgressBuckets}>
                                <CartesianGrid vertical={false} />
                                <XAxis dataKey="bucket" tickLine={false} axisLine={false} />
                                <YAxis allowDecimals={false} tickLine={false} axisLine={false} />
                                <ChartTooltip content={<ChartTooltipContent />} />
                                <Bar dataKey="total" fill="var(--color-total)" radius={4} />
                            </BarChart>
                        </ChartContainer>
                    )}
                </CardContent>
            </Card>
        </div>
    );
}
