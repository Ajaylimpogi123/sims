import { Card, CardContent, CardHeader, CardTitle } from "@/Components/ui/card";
import { Link } from "@inertiajs/react";
import { AlertTriangle, ChevronRight } from "lucide-react";
import EmptyState from "@/Components/EmptyState";

export default function ActionItems({ actionItems }) {
    return (
        <Card className="border-0 shadow-sm">
            <CardHeader>
                <CardTitle>Alerts &amp; Action Items</CardTitle>
            </CardHeader>
            <CardContent>
                {actionItems.length === 0 ? (
                    <EmptyState
                        icon={AlertTriangle}
                        title="Nothing needs your attention"
                        message="You're all caught up."
                    />
                ) : (
                    <ul className="divide-y">
                        {actionItems.map((item) => (
                            <li key={item.key}>
                                <Link
                                    href={item.href}
                                    className="flex items-center justify-between gap-3 py-3 text-sm transition-colors hover:text-emerald-700"
                                >
                                    <span className="flex items-center gap-2">
                                        <AlertTriangle className="h-4 w-4 shrink-0 text-amber-500" />
                                        {item.label}
                                    </span>
                                    <ChevronRight className="h-4 w-4 shrink-0 text-muted-foreground" />
                                </Link>
                            </li>
                        ))}
                    </ul>
                )}
            </CardContent>
        </Card>
    );
}
