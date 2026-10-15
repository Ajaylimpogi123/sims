import { Button } from "@/Components/ui/button";
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuLabel,
    DropdownMenuTrigger,
} from "@/Components/ui/dropdown-menu";
import { MoreHorizontal } from "lucide-react";
import RequiredHoursModal from "./RequiredHoursModal";
import LogModal from "./LogModal";
import {
    RequiredHoursNotSet,
    RemainingHoursUnknown,
} from "@/Components/RequiredHoursNotSet";

export function getColumns(canWrite) {
    return [
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
            accessorKey: "rendered_hours",
            header: "Rendered Hours",
        },
        {
            accessorKey: "required_hours",
            header: "Required Hours",
            cell: ({ row }) => {
                const requiredHours = row.getValue("required_hours");
                if (requiredHours != null) return requiredHours;

                return (
                    <RequiredHoursNotSet
                        action={
                            canWrite && (
                                <RequiredHoursModal student={row.original}>
                                    <Button
                                        type="button"
                                        variant="link"
                                        size="sm"
                                        className="h-auto p-0 text-xs"
                                    >
                                        Set
                                    </Button>
                                </RequiredHoursModal>
                            )
                        }
                    />
                );
            },
        },
        {
            accessorKey: "remaining_hours",
            header: "Remaining Hours",
            cell: ({ row }) =>
                row.getValue("remaining_hours") ?? <RemainingHoursUnknown />,
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
                            {canWrite && (
                                <div className="pl-2 pr-4 py-2 text-sm text-gray-800 hover:bg-gray-100 rounded-md cursor-pointer">
                                    <RequiredHoursModal student={student}>
                                        Set Required Hours
                                    </RequiredHoursModal>
                                </div>
                            )}
                            <div className="pl-2 pr-4 py-2 text-sm text-gray-800 hover:bg-gray-100 rounded-md cursor-pointer">
                                <LogModal student={student} canWrite={canWrite}>
                                    {canWrite ? "View Log" : "View Attendance"}
                                </LogModal>
                            </div>
                        </DropdownMenuContent>
                    </DropdownMenu>
                );
            },
        },
    ];
}
