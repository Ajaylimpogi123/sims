import RowActionsMenu from "@/Components/RowActionsMenu";
import { router } from "@inertiajs/react";
import Swal from "sweetalert2";
import EditModal from "./EditModal";
import SupervisorRosterModal from "./SupervisorRosterModal";

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

export function getColumns(availableSupervisors) {
    return [
    {
        accessorKey: "company_name",
        header: "Company Name",
    },
    {
        accessorKey: "contact_person",
        header: "Contact Person",
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
                        { label: "Manage Supervisors", dialog: "roster" },
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
                        <>
                            <EditModal
                                company={company}
                                open={isOpen("edit")}
                                onOpenChange={onOpenChange}
                            />
                            <SupervisorRosterModal
                                company={company}
                                availableSupervisors={availableSupervisors}
                                open={isOpen("roster")}
                                onOpenChange={onOpenChange}
                            />
                        </>
                    )}
                />
            );
        },
    },
    ];
}
