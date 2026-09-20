export default function HoursProgressBar({ rendered, required }) {
    if (required == null) {
        return <span className="text-sm text-muted-foreground">—</span>;
    }

    const percent =
        required > 0 ? Math.min(Math.round((rendered / required) * 100), 100) : 0;

    return (
        <div className="flex items-center gap-3">
            <div className="h-2 w-full max-w-xs overflow-hidden rounded-full bg-gray-100">
                <div
                    className="h-full rounded-full bg-emerald-500"
                    style={{ width: `${percent}%` }}
                />
            </div>
            <span className="whitespace-nowrap text-xs text-muted-foreground">
                {rendered}/{required} hrs ({percent}%)
            </span>
        </div>
    );
}
