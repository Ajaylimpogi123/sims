import RowActionsMenu from "@/Components/RowActionsMenu";
import { RequiredHoursNotSet } from "@/Components/RequiredHoursNotSet";
import { router } from "@inertiajs/react";
import StudentModal from "./StudentModal";
import StatusBadge from "@/Components/StatusBadge";

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

// supervisor_issue -> StatusBadge status key.
const ISSUE_BADGES = {
    no_supervisor: "no_supervisor",
    not_on_roster: "not_on_roster",
    not_a_supervisor: "not_a_supervisor",
    inactive: "supervisor_inactive",
};

const handleToggleStatus =(student, needsSupervisorOnly) => {
    router.patch(
        route("internship-assignment.toggle-status", student.id),
        needsSupervisorOnly ? { needs_supervisor: 1 } : {},
        { preserveScroll: true },
    );
};

// needsSupervisorOnly is sent with saves so the redirect keeps the
// "Needs supervisor" filter (?needs_supervisor=1) on.
export function getColumns(companies, { needsSupervisorOnly = false } = {}) {
    return [
        {
            accessorKey: "name",
            header: "Name",
        },
        {
            accessorKey: "student_number",
            header: "Student Number",
        },
        {
            accessorKey: "company_name",
            header: "Company",
            cell: ({ row }) => row.getValue("company_name") || "Unassigned",
        },
        {
            accessorKey: "supervisor_name",
            header: "Supervisor",
            cell: ({ row }) => {
                const student = row.original;
                const name = row.getValue("supervisor_name");

                // supervisor_issue (server) is non-null exactly when
                // needs_supervisor is true, picked by precedence: no
                // supervisor > not on roster > not a supervisor > inactive.
                const badge = ISSUE_BADGES[student.supervisor_issue];

                if (!badge) return name || "Unassigned";

                if (student.supervisor_issue === "no_supervisor") {
                    return <StatusBadge status={badge} />;
                }

                return (
                    <div className="flex flex-col items-start gap-1">
                        <span>{name}</span>
                        <StatusBadge status={badge} />
                    </div>
                );
            },
        },
        {
            accessorKey: "required_hours",
            header: "Required Hours",
            cell: ({ row }) => {
                const hours = Number(row.getValue("required_hours"));
                // 0 counts as not set, as on the server.
                return hours > 0 ? hours : <RequiredHoursNotSet />;
            },
        },
        {
            accessorKey: "internship_status",
            header: "Internship Status",
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
            accessorKey: "status",
            header: "Account Status",
            cell: ({ row }) => {
                const status = row.getValue("status");
                return (
                    <span
                        className={`inline-flex items-center rounded-full px-2 py-0.5 text-xs font-medium ${
                            status === "active"
                                ? "bg-green-100 text-green-700"
                                : "bg-gray-100 text-gray-600"
                        }`}
                    >
                        {status === "active" ? "Active" : "Inactive"}
                    </span>
                );
            },
        },
        {
            id: "actions",
            cell: ({ row }) => {
                const student = row.original;

                return (
                    <RowActionsMenu
                        actions={[
                            { label: "Edit", dialog: "edit" },
                            {
                                label:
                                    student.status === "active"
                                        ? "Deactivate"
                                        : "Activate",
                                onSelect: () =>
                                    handleToggleStatus(
                                        student,
                                        needsSupervisorOnly,
                                    ),
                            },
                        ]}
                        renderDialogs={({ isOpen, onOpenChange }) => (
                            <StudentModal
                                student={student}
                                companies={companies}
                                needsSupervisorOnly={needsSupervisorOnly}
                                open={isOpen("edit")}
                                onOpenChange={onOpenChange}
                            />
                        )}
                    />
                );
            },
        },
    ];
}
