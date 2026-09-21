import { Bar, BarChart, Cell, Pie, PieChart, CartesianGrid, XAxis, YAxis } from "recharts";
import { Card, CardContent, CardHeader, CardTitle } from "@/Components/ui/card";
import {
    ChartContainer,
    ChartTooltip,
    ChartTooltipContent,
} from "@/Components/ui/chart";
import EmptyState from "@/Components/EmptyState";

const STATUS_LABELS = {
    draft: "Draft",
    submitted: "Submitted",
    locked: "Locked",
};

const STATUS_COLORS = {
    draft: "hsl(220, 9%, 60%)",
    submitted: "hsl(217, 91%, 60%)",
    locked: "hsl(160, 84%, 39%)",
};

const chartConfig = {
    total: { label: "Evaluations", color: "hsl(160, 84%, 39%)" },
    average_rating: { label: "Avg. Rating", color: "hsl(262, 83%, 58%)" },
};

export default function EvaluationCharts({ data }) {
    const completion = data?.completion ?? [];
    const byCategory = data?.byCategory ?? [];

    return (
        <div className="grid grid-cols-1 gap-4 lg:grid-cols-2">
            <Card className="border-0 shadow-sm">
                <CardHeader>
                    <CardTitle>Evaluation Completion</CardTitle>
                </CardHeader>
                <CardContent>
                    {completion.length === 0 ? (
                        <EmptyState message="No evaluations yet." />
                    ) : (
                        <ChartContainer config={chartConfig} className="mx-auto aspect-square h-64">
                            <PieChart>
                                <ChartTooltip content={<ChartTooltipContent hideLabel />} />
                                <Pie data={completion} dataKey="total" nameKey="status" innerRadius={50}>
                                    {completion.map((row) => (
                                        <Cell
                                            key={row.status}
                                            fill={STATUS_COLORS[row.status] ?? "hsl(220, 9%, 60%)"}
                                        />
                                    ))}
                                </Pie>
                            </PieChart>
                        </ChartContainer>
                    )}
                    <ul className="mt-3 flex flex-wrap justify-center gap-3 text-xs text-muted-foreground">
                        {completion.map((row) => (
                            <li key={row.status} className="flex items-center gap-1.5">
                                <span
                                    className="h-2 w-2 rounded-full"
                                    style={{
                                        backgroundColor:
                                            STATUS_COLORS[row.status] ?? "hsl(220, 9%, 60%)",
                                    }}
                                />
                                {STATUS_LABELS[row.status] ?? row.status}: {row.total}
                            </li>
                        ))}
                    </ul>
                </CardContent>
            </Card>

            <Card className="border-0 shadow-sm">
                <CardHeader>
                    <CardTitle>Average Rating by Category</CardTitle>
                </CardHeader>
                <CardContent>
                    {byCategory.length === 0 ? (
                        <EmptyState message="No rated evaluations yet." />
                    ) : (
                        <ChartContainer config={chartConfig} className="aspect-auto h-64 w-full">
                            <BarChart data={byCategory} layout="vertical">
                                <CartesianGrid horizontal={false} />
                                <XAxis type="number" domain={[0, 5]} tickLine={false} axisLine={false} />
                                <YAxis
                                    dataKey="category"
                                    type="category"
                                    tickLine={false}
                                    axisLine={false}
                                    width={120}
                                />
                                <ChartTooltip content={<ChartTooltipContent />} />
                                <Bar dataKey="average_rating" fill="var(--color-average_rating)" radius={4} />
                            </BarChart>
                        </ChartContainer>
                    )}
                </CardContent>
            </Card>
        </div>
    );
}
