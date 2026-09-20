import AuthenticatedLayout from "@/Layouts/AuthenticatedLayout";
import { Head } from "@inertiajs/react";
import { DataTable } from "./Partials/DataTable";
import { getColumns } from "./Partials/Columns";
import ExportButtons from "@/Components/ExportButtons";

const EXPORT_COLUMNS = [
    { header: "Name", accessor: "name" },
    { header: "Company", accessor: "company_name" },
    { header: "Rendered Hours", accessor: "rendered_hours" },
    { header: "Required Hours", accessor: "required_hours" },
    { header: "Remaining Hours", accessor: "remaining_hours" },
];

export default function Index({ students }) {
    const columns = getColumns();

    const studentData =
        students.map((student) => {
            const renderedHours = Number(student.total_rendered_hours) || 0;
            const requiredHours = student.required_hours;

            return {
                id: student.id,
                user_id: student.user_id,
                name: student.user?.name,
                company_name: student.company?.company_name,
                rendered_hours: renderedHours,
                required_hours: requiredHours,
                remaining_hours:
                    requiredHours != null
                        ? Math.max(requiredHours - renderedHours, 0)
                        : null,
                attendances: student.attendances || [],
            };
        }) || [];

    const exportData = studentData.map((student) => ({
        name: student.name,
        company_name: student.company_name || "Unassigned",
        rendered_hours: student.rendered_hours,
        required_hours: student.required_hours ?? "Not set",
        remaining_hours: student.remaining_hours ?? "—",
    }));

    return (
        <AuthenticatedLayout>
            <Head title="Attendance Monitoring" />

            <div className="relative z-10 py-8">
                <div className="flex-1 space-y-6 p-4 md:p-6">
                    <div className="mb-8 flex flex-col gap-4 sm:flex-row sm:items-end sm:justify-between">
                        <div>
                            <h1 className="text-3xl font-bold tracking-tight text-white">
                                Attendance Monitoring
                            </h1>
                            <p className="mt-2 text-sm text-white">
                                Track rendered vs. required internship hours,
                                and manage daily attendance records
                            </p>
                        </div>
                        <ExportButtons
                            columns={EXPORT_COLUMNS}
                            rows={exportData}
                            filename="attendance-monitoring"
                            title="Attendance Monitoring"
                        />
                    </div>

                    <div className="rounded-sm border bg-card text-card-foreground shadow">
                        <div className="p-6">
                            <DataTable
                                columns={columns}
                                data={studentData}
                            />
                        </div>
                    </div>
                </div>
            </div>
        </AuthenticatedLayout>
    );
}
