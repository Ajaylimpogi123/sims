import { Card, CardContent } from "@/Components/ui/card";

export default function StatCard({ title, value, subtitle, icon: Icon, iconColor, iconBg }) {
    return (
        <Card className="border-0 shadow-sm">
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
}
