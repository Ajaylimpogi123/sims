import AuthenticatedLayout from "@/Layouts/AuthenticatedLayout";
import { Head } from "@inertiajs/react";
import { DataTable } from "./Partials/DataTable";
import { getColumns } from "./Partials/Columns";

export default function Index({ students, companies, supervisors }) {
    const columns = getColumns(companies, supervisors);

    const studentData =
        students.map((student) => ({
            id: student.id,
            name: student.user?.name,
            email: student.user?.email,
            status: student.user?.status,
            student_number: student.student_number,
            course: student.course,
            section: student.section,
            company_id: student.company_id,
            company_name: student.company?.company_name,
            supervisor_id: student.supervisor_id,
            supervisor_name: student.supervisor?.name,
            internship_status: student.internship_status,
            internship_schedule: student.internship_schedule,
        })) || [];

    return (
        <AuthenticatedLayout>
            <Head title="Internship Assignment" />

            <div className="relative z-10 py-8">
                <div className="flex-1 space-y-6 p-4 md:p-6">
                    <div className="mb-8">
                        <h1 className="text-3xl font-bold tracking-tight text-white">
                            Internship Assignment
                        </h1>
                        <p className="mt-2 text-sm text-white">
                            View and manage every student's profile,
                            company/supervisor assignment, and internship
                            status in one place
                        </p>
                    </div>

                    <div className="rounded-sm border bg-card text-card-foreground shadow">
                        <div className="p-6">
                            <DataTable columns={columns} data={studentData} />
                        </div>
                    </div>
                </div>
            </div>
        </AuthenticatedLayout>
    );
}
