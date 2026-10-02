// components/recent-orders-table.jsx
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from "@/Components/ui/table";
import { Badge } from "@/Components/ui/badge";
import { Card, CardContent, CardHeader, CardDescription, CardTitle } from "@/Components/ui/card";
import { formatDate } from "@/lib/dates";
export function RecentOrdersTable({ orders }) {
    return (
        <Card>
            <CardHeader>
                <CardTitle>Recent Orders</CardTitle>
                <CardDescription>Latest 10 orders from your store</CardDescription>
            </CardHeader>
            <CardContent>
                <Table>
                    <TableHeader>
                        <TableRow>
                            <TableHead>Invoice No</TableHead>
                            <TableHead>Date</TableHead>
                            <TableHead>Customer</TableHead>
                            <TableHead>Table</TableHead>
                            <TableHead>Amount</TableHead>
                            <TableHead>Payment</TableHead>
                            <TableHead>Status</TableHead>
                        </TableRow>
                    </TableHeader>
                    <TableBody>
                        {orders.map((order) => (
                            <TableRow key={order.od_id}>
                                <TableCell className="font-medium">{order.invoice_no}</TableCell>
                                <TableCell>{formatDate(order.created_at, "")}</TableCell>
                                <TableCell>{order.cust_fullname || "Walk-in"}</TableCell>
                                <TableCell>{order.table_number}</TableCell>
                                <TableCell className="text-green-600 font-medium">
                                    ₱{parseFloat(order.od_total_amt_due).toLocaleString('en-PH', { minimumFractionDigits: 2 })}
                                </TableCell>
                                <TableCell className="capitalize">{order.payment_method}</TableCell>
                                <TableCell>
                                    <Badge variant="outline" className="bg-green-50 text-green-600">
                                        Completed
                                    </Badge>
                                </TableCell>
                            </TableRow>
                        ))}
                    </TableBody>
                </Table>
            </CardContent>
        </Card>
    );
}