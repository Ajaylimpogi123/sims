import RowActionsMenu from "@/Components/RowActionsMenu";
import { router } from "@inertiajs/react";
import Swal from "sweetalert2";
import EditModal from "./EditModal";
import StatusBadge from "@/Components/StatusBadge";

// supervisor_issue (server) -> StatusBadge status key. no_supervisor is
// shown whenever the company has no supervisor.
const SUPERVISOR_ISSUE_BADGES = {
    not_a_supervisor: "not_a_supervisor",
    inactive: "supervisor_inactive",
};

const handleDelete = (company) => {
    if (confirm(`Are you sure you want to delete "${company.company_name}"?`)) {
        router.delete(route("company-management.destroy", company.id), {
            preserveScroll: true,
            // A refusal (students assigned / evaluations recorded) comes
            // back as a redirect with flash.error, not a validation error.
            onSuccess: (page) => {
                const error = page.props.flash?.error;
                if (error) {
                    Swal.fire({
                        icon: "error",
                        title: "Company Not Deleted",
                        text: error,
                        confirmButtonColor: "#dc2626",
                    });
                }
            },
        });
    }
};

const handleToggleStatus = (company) => {
    router.patch(
        route("company-management.toggle-status", company.id),
        {},
        { preserveScroll: true },
    );
};

export function getColumns(supervisorOptions) {
    return [
    {
        accessorKey: "company_name",
        header: "Company Name",
    },
    {
        id: "supervisor",
        accessorFn: (company) => company.supervisor?.name ?? "",
        header: "Company Supervisor",
        cell: ({ row }) => {
            const company = row.original;
            const badge = SUPERVISOR_ISSUE_BADGES[company.supervisor_issue];

            if (!company.supervisor) {
                return <StatusBadge status="no_supervisor" />;
            }
            if (!badge) return company.supervisor.name;

            return (
                <div className="flex flex-col items-start gap-1">
                    <span>{company.supervisor.name}</span>
                    <StatusBadge status={badge} />
                </div>
            );
        },
    },
    {
        accessorKey: "contact_number",
        header: "Contact Number",
    },
    {
        accessorKey: "industry",
        header: "Industry",
        cell: ({ row }) => row.getValue("industry") || "-",
    },
    {
        accessorKey: "slots",
        header: "Slots",
        cell: ({ row }) => {
            const company = row.original;
            return `${company.students_count ?? 0} / ${company.slots}`;
        },
    },
    {
        accessorKey: "status",
        header: "Status",
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
            const company = row.original;

            return (
                <RowActionsMenu
                    actions={[
                        { label: "Edit", dialog: "edit" },
                        {
                            label:
                                company.status === "active"
                                    ? "Deactivate"
                                    : "Activate",
                            onSelect: () => handleToggleStatus(company),
                        },
                        {
                            label: "Delete",
                            onSelect: () => handleDelete(company),
                            className:
                                "pl-2 pr-4 py-2 text-sm text-red-600 hover:bg-red-50 rounded-md cursor-pointer",
                        },
                    ]}
                    renderDialogs={({ isOpen, onOpenChange }) => (
                        <EditModal
                            company={company}
                            supervisorOptions={supervisorOptions}
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
