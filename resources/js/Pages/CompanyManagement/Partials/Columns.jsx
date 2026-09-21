import { Button } from "@/components/ui/button";
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuLabel,
    DropdownMenuTrigger,
} from "@/components/ui/dropdown-menu";
import { MoreHorizontal } from "lucide-react";
import { router } from "@inertiajs/react";
import EditModal from "./EditModal";
import SupervisorRosterModal from "./SupervisorRosterModal";

const handleDelete = (company) => {
    if (confirm(`Are you sure you want to delete "${company.company_name}"?`)) {
        router.delete(route("company-management.destroy", company.id), {
            preserveScroll: true,
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
                <DropdownMenu>
                    <DropdownMenuTrigger asChild>
                        <Button variant="ghost" className="h-8 w-8 p-0">
                            <MoreHorizontal className="h-4 w-4" />
                        </Button>
                    </DropdownMenuTrigger>
                    <DropdownMenuContent align="end">
                        <DropdownMenuLabel>Actions</DropdownMenuLabel>
                        <div className="pl-2 pr-4 py-2 text-sm text-gray-800 hover:bg-gray-100 rounded-md cursor-pointer">
                            <EditModal company={company}>Edit</EditModal>
                        </div>
                        <div className="pl-2 pr-4 py-2 text-sm text-gray-800 hover:bg-gray-100 rounded-md cursor-pointer">
                            <SupervisorRosterModal
                                company={company}
                                availableSupervisors={availableSupervisors}
                            >
                                Manage Supervisors
                            </SupervisorRosterModal>
                        </div>
                        <DropdownMenuItem
                            onClick={() => handleToggleStatus(company)}
                            className="pl-2 pr-4 py-2 text-sm text-gray-800 hover:bg-gray-100 rounded-md cursor-pointer"
                        >
                            {company.status === "active"
                                ? "Deactivate"
                                : "Activate"}
                        </DropdownMenuItem>
                        <DropdownMenuItem
                            onClick={() => handleDelete(company)}
                            className="pl-2 pr-4 py-2 text-sm text-red-600 hover:bg-red-50 rounded-md cursor-pointer"
                        >
                            Delete
                        </DropdownMenuItem>
                    </DropdownMenuContent>
                </DropdownMenu>
            );
        },
    },
    ];
}
