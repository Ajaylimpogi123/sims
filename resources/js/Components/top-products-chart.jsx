// components/top-products-chart.jsx
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from "@/Components/ui/card";
import { BarChart, Bar, XAxis, YAxis, CartesianGrid, Tooltip, ResponsiveContainer, Cell } from "recharts";

const COLORS = ['#10b981', '#3b82f6', '#f59e0b', '#ef4444', '#8b5cf6'];

export function TopProductsChart({ data }) {
    // Default data if none provided
    const chartData = data && data.length > 0 ? data : [
        { name: "No data", quantity: 0, revenue: 0 }
    ];

    return (
        <Card>
            <CardHeader>
                <CardTitle>Top Selling Products</CardTitle>
                <CardDescription>Best performing items by quantity</CardDescription>
            </CardHeader>
            <CardContent>
                <div className="h-[300px]">
                    <ResponsiveContainer width="100%" height="100%">
                        <BarChart
                            data={chartData.slice(0, 5)}
                            layout="vertical"
                            margin={{ top: 5, right: 30, left: 20, bottom: 5 }}
                        >
                            <CartesianGrid strokeDasharray="3 3" />
                            <XAxis type="number" />
                            <YAxis type="category" dataKey="name" width={100} />
                            <Tooltip 
                                formatter={(value, name) => [
                                    value, 
                                    name === "quantity" ? "Units Sold" : "Revenue"
                                ]}
                            />
                            <Bar dataKey="quantity" fill="#10b981" radius={[0, 4, 4, 0]}>
                                {chartData.slice(0, 5).map((entry, index) => (
                                    <Cell key={`cell-${index}`} fill={COLORS[index % COLORS.length]} />
                                ))}
                            </Bar>
                        </BarChart>
                    </ResponsiveContainer>
                </div>
            </CardContent>
        </Card>
    );
}