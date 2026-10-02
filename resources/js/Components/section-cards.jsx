// components/section-cards.jsx (Enhanced)
import { TrendingDownIcon, TrendingUpIcon, DollarSign, ShoppingBag, Users, TrendingUp } from "lucide-react";
import { Badge } from "@/Components/ui/badge";
import { Card, CardContent, CardDescription, CardFooter, CardHeader, CardTitle } from "@/Components/ui/card";

export function SectionCards({ data }) {
    const { revenue, orders, customers, growth } = data;

    return (
        <div className="grid grid-cols-1 gap-4 px-4 lg:grid-cols-4 lg:px-6">
            {/* Total Revenue Card */}
            <Card className="hover:shadow-lg transition-shadow">
                <CardHeader className="flex flex-row items-center justify-between space-y-0 pb-2">
                    <CardTitle className="text-sm font-medium text-muted-foreground">
                        Total Revenue
                    </CardTitle>
                    <DollarSign className="h-4 w-4 text-green-500" />
                </CardHeader>
                <CardContent>
                    <div className="text-2xl font-bold text-green-600">
                        ₱{(revenue?.total || 0).toLocaleString('en-PH', { minimumFractionDigits: 2 })}
                    </div>
                    <div className="flex items-center gap-1 mt-1">
                        {revenue?.trend >= 0 ? (
                            <TrendingUpIcon className="h-4 w-4 text-green-500" />
                        ) : (
                            <TrendingDownIcon className="h-4 w-4 text-red-500" />
                        )}
                        <span className={`text-xs ${revenue?.trend >= 0 ? 'text-green-600' : 'text-red-600'}`}>
                            {Math.abs(revenue?.trend || 0)}% from last period
                        </span>
                    </div>
                </CardContent>
                <CardFooter className="text-xs text-muted-foreground">
                    ₱{(revenue?.today || 0).toLocaleString('en-PH', { minimumFractionDigits: 2 })} today
                </CardFooter>
            </Card>

            {/* Total Orders Card */}
            <Card className="hover:shadow-lg transition-shadow">
                <CardHeader className="flex flex-row items-center justify-between space-y-0 pb-2">
                    <CardTitle className="text-sm font-medium text-muted-foreground">
                        Total Orders
                    </CardTitle>
                    <ShoppingBag className="h-4 w-4 text-blue-500" />
                </CardHeader>
                <CardContent>
                    <div className="text-2xl font-bold text-blue-600">
                        {orders?.total || 0}
                    </div>
                    <div className="flex items-center gap-1 mt-1">
                        <Badge variant="outline" className="rounded-lg text-xs">
                            {orders?.trend >= 0 ? '+' : ''}{orders?.trend || 0}%
                        </Badge>
                    </div>
                </CardContent>
                <CardFooter className="text-xs text-muted-foreground">
                    {orders?.today || 0} orders today
                </CardFooter>
            </Card>

            {/* Average Order Value Card */}
            <Card className="hover:shadow-lg transition-shadow">
                <CardHeader className="flex flex-row items-center justify-between space-y-0 pb-2">
                    <CardTitle className="text-sm font-medium text-muted-foreground">
                        Average Order Value
                    </CardTitle>
                    <TrendingUp className="h-4 w-4 text-purple-500" />
                </CardHeader>
                <CardContent>
                    <div className="text-2xl font-bold text-purple-600">
                        ₱{(orders?.averageValue || 0).toLocaleString('en-PH', { minimumFractionDigits: 2 })}
                    </div>
                </CardContent>
                <CardFooter className="text-xs text-muted-foreground">
                    Based on {orders?.total || 0} orders
                </CardFooter>
            </Card>

            {/* Customers Card */}
            <Card className="hover:shadow-lg transition-shadow">
                <CardHeader className="flex flex-row items-center justify-between space-y-0 pb-2">
                    <CardTitle className="text-sm font-medium text-muted-foreground">
                        Unique Customers
                    </CardTitle>
                    <Users className="h-4 w-4 text-orange-500" />
                </CardHeader>
                <CardContent>
                    <div className="text-2xl font-bold text-orange-600">
                        {customers?.total || 0}
                    </div>
                    <div className="flex items-center gap-1 mt-1">
                        <span className="text-xs text-green-600">
                            +{customers?.newToday || 0} today
                        </span>
                    </div>
                </CardContent>
                <CardFooter className="text-xs text-muted-foreground">
                    {customers?.newThisMonth || 0} new this month
                </CardFooter>
            </Card>
        </div>
    );
}