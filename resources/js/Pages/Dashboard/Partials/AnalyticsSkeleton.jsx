import { Skeleton } from "@/Components/ui/skeleton";
import { Card, CardContent, CardHeader } from "@/Components/ui/card";

export default function AnalyticsSkeleton() {
    return (
        <div className="space-y-4">
            <Skeleton className="h-16 w-full rounded-lg" />
            <div className="grid grid-cols-1 gap-4 lg:grid-cols-3">
                {[0, 1, 2].map((i) => (
                    <Card key={i} className="border-0 shadow-sm">
                        <CardHeader>
                            <Skeleton className="h-5 w-2/3" />
                        </CardHeader>
                        <CardContent>
                            <Skeleton className="h-64 w-full" />
                        </CardContent>
                    </Card>
                ))}
            </div>
        </div>
    );
}
