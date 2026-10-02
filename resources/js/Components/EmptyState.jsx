import { Inbox } from "lucide-react";
import { Button } from "@/Components/ui/button";
import { Link } from "@inertiajs/react";

/**
 * Generic zero-data placeholder for charts/lists across the Analytics tab.
 */
export default function EmptyState({
    icon: Icon = Inbox,
    title = "Nothing to show yet",
    message,
    ctaLabel,
    ctaHref,
    className = "",
}) {
    return (
        <div
            className={`flex flex-col items-center justify-center gap-2 py-10 text-center ${className}`}
        >
            <div className="rounded-full bg-muted p-3">
                <Icon className="h-6 w-6 text-muted-foreground" />
            </div>
            <p className="text-sm font-medium text-foreground">{title}</p>
            {message && (
                <p className="max-w-sm text-xs text-muted-foreground">{message}</p>
            )}
            {ctaLabel && ctaHref && (
                <Button asChild variant="outline" size="sm" className="mt-2">
                    <Link href={ctaHref}>{ctaLabel}</Link>
                </Button>
            )}
        </div>
    );
}
