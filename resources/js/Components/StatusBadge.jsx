const STATUS_CONFIG = {
    active: { label: "Active", className: "bg-green-100 text-green-700" },
    inactive: { label: "Inactive", className: "bg-gray-100 text-gray-600" },
    ongoing: { label: "Ongoing", className: "bg-blue-100 text-blue-700" },
    completed: { label: "Completed", className: "bg-green-100 text-green-700" },
    not_started: { label: "Not Started", className: "bg-gray-100 text-gray-600" },
    pending: { label: "Pending", className: "bg-amber-100 text-amber-700" },
    reviewed: { label: "Reviewed", className: "bg-green-100 text-green-700" },
    draft: { label: "Draft", className: "bg-gray-100 text-gray-600" },
    submitted: { label: "Submitted", className: "bg-blue-100 text-blue-700" },
    locked: { label: "Locked", className: "bg-purple-100 text-purple-700" },
    approved: { label: "Approved", className: "bg-green-100 text-green-700" },
    rejected: { label: "Rejected", className: "bg-red-100 text-red-700" },
};

/**
 * Generalized to cover the wider status vocabulary the Dashboard &
 * Analytics module surfaces (internship/report/evaluation/attendance
 * statuses), while staying byte-for-byte backward compatible with the
 * original active/inactive-only callers in UserManagement/CompanyManagement:
 * any status not in STATUS_CONFIG falls back to the original binary
 * active-vs-everything-else rendering.
 */
export default function StatusBadge({ status }) {
    const config = STATUS_CONFIG[status];

    if (config) {
        return (
            <span
                className={`inline-flex items-center rounded-full px-2 py-0.5 text-xs font-medium ${config.className}`}
            >
                {config.label}
            </span>
        );
    }

    const isActive = status === "active";

    return (
        <span
            className={`inline-flex items-center rounded-full px-2 py-0.5 text-xs font-medium ${
                isActive
                    ? "bg-green-100 text-green-700"
                    : "bg-gray-100 text-gray-600"
            }`}
        >
            {isActive ? "Active" : "Inactive"}
        </span>
    );
}
