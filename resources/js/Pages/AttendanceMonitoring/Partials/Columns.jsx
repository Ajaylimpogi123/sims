import { Button } from "@/Components/ui/button";
import RowActionsMenu from "@/Components/RowActionsMenu";
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
                    <RowActionsMenu
                        actions={[
                            canWrite && {
                                label: "Set Required Hours",
                                dialog: "required-hours",
                            },
                            {
                                label: canWrite
                                    ? "View Log"
                                    : "View Attendance",
                                dialog: "log",
                            },
                        ]}
                        renderDialogs={({ isOpen, onOpenChange }) => (
                            <>
                                {canWrite && (
                                    <RequiredHoursModal
                                        student={student}
                                        open={isOpen("required-hours")}
                                        onOpenChange={onOpenChange}
                                    />
                                )}
                                <LogModal
                                    student={student}
                                    canWrite={canWrite}
                                    open={isOpen("log")}
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
