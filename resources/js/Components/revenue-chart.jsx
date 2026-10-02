// components/revenue-chart.jsx
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from "@/Components/ui/card";
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from "@/Components/ui/select";
import { ChartContainer, ChartTooltip, ChartTooltipContent } from "@/Components/ui/chart";
import { Bar, BarChart, CartesianGrid, XAxis, YAxis } from "recharts";

export function RevenueChart({ data, dateRange, setDateRange }) {
    const chartConfig = {
        revenue: { label: "Revenue", color: "#10b981" },
    };

    const handleRangeChange = (value) => {
        const to = new Date();
        let from;
        switch(value) {
            case "7d":
                from = subDays(to, 7);
                break;
            case "30d":
                from = subDays(to, 30);
                break;
            case "90d":
                from = subDays(to, 90);
                break;
            default:
                from = subDays(to, 30);
        }
        setDateRange({ from, to });
    };

    return (
        <Card>
            <CardHeader className="flex flex-col sm:flex-row items-start sm:items-center justify-between">
                <div>
                    <CardTitle>Revenue Overview</CardTitle>
                    <CardDescription>Daily revenue trends</CardDescription>
                </div>
                <Select onValueChange={handleRangeChange} defaultValue="30d">
                    <SelectTrigger className="w-[150px]">
                        <SelectValue placeholder="Select range" />
                    </SelectTrigger>
                    <SelectContent>
                        <SelectItem value="7d">Last 7 days</SelectItem>
                        <SelectItem value="30d">Last 30 days</SelectItem>
                        <SelectItem value="90d">Last 3 months</SelectItem>
                    </SelectContent>
                </Select>
            </CardHeader>
            <CardContent>
                <ChartContainer config={chartConfig} className="h-[300px]">
                    <BarChart data={data}>
                        <CartesianGrid strokeDasharray="3 3" />
                        <XAxis dataKey="date" />
                        <YAxis />
                        <ChartTooltip 
                            content={<ChartTooltipContent />}
                            formatter={(value) => [`₱${value}`, "Revenue"]}
                        />
                        <Bar dataKey="revenue" fill="#10b981" radius={[4, 4, 0, 0]} />
                    </BarChart>
                </ChartContainer>
            </CardContent>
        </Card>
    );
}