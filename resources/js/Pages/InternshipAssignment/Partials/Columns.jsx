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
import StudentModal from "./StudentModal";

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

const handleToggleStatus = (student) => {
    router.patch(
        route("internship-assignment.toggle-status", student.id),
        {},
        { preserveScroll: true },
    );
};

export function getColumns(companies) {
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
            cell: ({ row }) =>
                row.getValue("supervisor_name") || "Unassigned",
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
                    <DropdownMenu>
                        <DropdownMenuTrigger asChild>
                            <Button variant="ghost" className="h-8 w-8 p-0">
                                <MoreHorizontal className="h-4 w-4" />
                            </Button>
                        </DropdownMenuTrigger>
                        <DropdownMenuContent align="end">
                            <DropdownMenuLabel>Actions</DropdownMenuLabel>
                            <div className="pl-2 pr-4 py-2 text-sm text-gray-800 hover:bg-gray-100 rounded-md cursor-pointer">
                                <StudentModal
                                    student={student}
                                    companies={companies}
                                >
                                    Edit
                                </StudentModal>
                            </div>
                            <DropdownMenuItem
                                onClick={() => handleToggleStatus(student)}
                                className="pl-2 pr-4 py-2 text-sm text-gray-800 hover:bg-gray-100 rounded-md cursor-pointer"
                            >
                                {student.status === "active"
                                    ? "Deactivate"
                                    : "Activate"}
                            </DropdownMenuItem>
                        </DropdownMenuContent>
                    </DropdownMenu>
                );
            },
        },
    ];
}
