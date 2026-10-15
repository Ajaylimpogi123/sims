import AuthenticatedLayout from "@/Layouts/AuthenticatedLayout";
import { Head, router } from "@inertiajs/react";
import { useMemo, useState } from "react";
import { AlertTriangle } from "lucide-react";
import { Button } from "@/Components/ui/button";
import { DataTable } from "./Partials/DataTable";
import { getColumns } from "./Partials/Columns";

export default function Index({ students, companies, filters }) {
    // `students` is always the full list; the "Needs supervisor" filter is
    // applied here, seeded from ?needs_supervisor=1 (the dashboard's link).
    const [needsSupervisorOnly, setNeedsSupervisorOnly] = useState(
        Boolean(filters?.needs_supervisor),
    );

    const columns = getColumns(companies, { needsSupervisorOnly });

    const studentData = useMemo(
        () =>
            (students || []).map((student) => ({
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
                supervisor_on_roster: student.supervisor_on_roster ?? null,
                needs_supervisor: Boolean(student.needs_supervisor),
                internship_status: student.internship_status,
                internship_schedule: student.internship_schedule,
            })),
        [students],
    );

    const needsSupervisorCount = useMemo(
        () => studentData.filter((s) => s.needs_supervisor).length,
        [studentData],
    );

    const visibleData = useMemo(
        () =>
            needsSupervisorOnly
                ? studentData.filter((s) => s.needs_supervisor)
                : studentData,
        [studentData, needsSupervisorOnly],
    );

    const toggleNeedsSupervisor = () => {
        const next = !needsSupervisorOnly;
        setNeedsSupervisorOnly(next);

        // Keep the URL shareable/refreshable; the list itself is filtered
        // client-side, so only the (cheap) `filters` prop is reloaded.
        router.get(
            route("internship-assignment.index"),
            next ? { needs_supervisor: 1 } : {},
            {
                preserveState: true,
                preserveScroll: true,
                replace: true,
                only: ["filters"],
            },
        );
    };

    const needsSupervisorToggle = (
        <Button
            type="button"
            variant="outline"
            size="sm"
            aria-pressed={needsSupervisorOnly}
            onClick={toggleNeedsSupervisor}
            disabled={!needsSupervisorOnly && needsSupervisorCount === 0}
            className={
                needsSupervisorOnly
                    ? "border-amber-300 bg-amber-50 text-amber-800 hover:bg-amber-100 hover:text-amber-900"
                    : ""
            }
        >
            <AlertTriangle
                className={`h-4 w-4 ${
                    needsSupervisorCount > 0 ? "text-amber-500" : ""
                }`}
            />
            Needs supervisor ({needsSupervisorCount})
        </Button>
    );

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
                            <DataTable
                                columns={columns}
                                data={visibleData}
                                toolbar={needsSupervisorToggle}
                                emptyMessage={
                                    needsSupervisorOnly
                                        ? "Every placed student has a supervisor on their company's roster."
                                        : "No results."
                                }
                            />
                        </div>
                    </div>
                </div>
            </div>
        </AuthenticatedLayout>
    );
}
