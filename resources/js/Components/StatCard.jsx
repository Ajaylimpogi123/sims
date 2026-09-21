import { Card, CardContent } from "@/Components/ui/card";
import { Link } from "@inertiajs/react";
import { ArrowDownRight, ArrowUpRight } from "lucide-react";

/**
 * Canonical KPI card, shared by the Dashboard's Overview and Analytics
 * tabs. `trend`/`trendLabel`/`href` are additive/optional — omitting them
 * reproduces the original card exactly.
 */
export default function StatCard({
    title,
    value,
    subtitle,
    icon: Icon,
    iconColor,
    iconBg,
    trend = null,
    trendLabel,
    href = null,
}) {
    const card = (
        <Card
            className={`border-0 shadow-sm ${href ? "transition-shadow hover:shadow-md" : ""}`}
        >
            <CardContent className="p-6">
                <div className="flex items-start justify-between gap-4">
                    <div>
                        <p className="text-sm text-muted-foreground">{title}</p>
                        <p className="mt-2 text-3xl font-bold tracking-tight">
                            {value}
                        </p>
                        {subtitle && (
                            <p className="mt-1 text-xs text-muted-foreground">
                                {subtitle}
                            </p>
                        )}
                        {trend !== null && trend !== undefined && (
                            <p
                                className={`mt-1 inline-flex items-center gap-1 text-xs font-medium ${
                                    trend >= 0 ? "text-emerald-600" : "text-red-600"
                                }`}
                            >
                                {trend >= 0 ? (
                                    <ArrowUpRight className="h-3 w-3" />
                                ) : (
                                    <ArrowDownRight className="h-3 w-3" />
                                )}
                                {Math.abs(trend)}%{trendLabel ? ` ${trendLabel}` : ""}
                            </p>
                        )}
                    </div>
                    {Icon && (
                        <div className={`rounded-lg p-3 ${iconBg ?? "bg-emerald-50"}`}>
                            <Icon className={`h-6 w-6 ${iconColor ?? "text-emerald-600"}`} />
                        </div>
                    )}
                </div>
            </CardContent>
        </Card>
    );

    if (href) {
        return (
            <Link href={href} className="block">
                {card}
            </Link>
        );
    }

    return card;
}
