const STATUS_LABELS = {
    not_started: "Not Started",
    ongoing: "Ongoing",
    completed: "Completed",
};

const STATUS_STYLES = {
    not_started: "bg-gray-100 text-gray-600",
    ongoing: "bg-blue-100 text-blue-700",
    completed: "bg-green-100 text-green-700",
};

function ProgressBar({ rendered, required }) {
    if (required == null) {
        return <span className="text-muted-foreground text-sm">—</span>;
    }

    const percent =
        required > 0 ? Math.min(Math.round((rendered / required) * 100), 100) : 0;

    return (
        <div className="flex items-center gap-2">
            <div className="h-2 w-32 overflow-hidden rounded-full bg-gray-100">
                <div
                    className="h-full rounded-full bg-emerald-500"
                    style={{ width: `${percent}%` }}
                />
            </div>
            <span className="text-xs text-muted-foreground whitespace-nowrap">
                {rendered}/{required} hrs ({percent}%)
            </span>
        </div>
    );
}

export const columns = [
    {
        accessorKey: "name",
        header: "Name",
    },
    {
        accessorKey: "company_name",
        header: "Company",
        cell: ({ row }) => row.getValue("company_name") || "Unassigned",
    },
    {
        accessorKey: "internship_status",
        header: "Status",
        cell: ({ row }) => {
            const status = row.getValue("internship_status");
            return (
                <span
                    className={`inline-flex items-center rounded-full px-2 py-0.5 text-xs font-medium ${
                        STATUS_STYLES[status] || STATUS_STYLES.not_started
                    }`}
                >
                    {STATUS_LABELS[status] || STATUS_LABELS.not_started}
                </span>
            );
        },
    },
    {
        id: "progress",
        header: "Progress",
        cell: ({ row }) => {
            const student = row.original;
            return (
                <ProgressBar
                    rendered={student.rendered_hours}
                    required={student.required_hours}
                />
            );
        },
    },
    {
        accessorKey: "remaining_hours",
        header: "Remaining Hours",
        cell: ({ row }) => row.getValue("remaining_hours") ?? "—",
    },
];
