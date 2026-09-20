import AuthenticatedLayout from "@/Layouts/AuthenticatedLayout";
import { Head, usePage } from "@inertiajs/react";
import StudentSummary from "./Partials/StudentSummary";
import StaffSummary from "./Partials/StaffSummary";
import SupervisorSummary from "./Partials/SupervisorSummary";

const ROLE_TITLES = {
    1: "My Dashboard",
    2: "Coordinator Dashboard",
    3: "Supervisor Dashboard",
    4: "Administrator Dashboard",
};

export default function Index(props) {
    const { auth } = usePage().props;
    const { roleId, noProfile } = props;

    const title = ROLE_TITLES[roleId] ?? "Dashboard";

    return (
        <AuthenticatedLayout>
            <Head title="Dashboard" />

            <div className="relative z-10 py-8">
                <div className="mx-auto w-full min-w-0 max-w-full space-y-6 px-4 sm:px-6 lg:px-8">
                    <div>
                        <h1 className="text-3xl font-bold tracking-tight text-white">
                            {title}
                        </h1>
                        <p className="mt-2 text-sm text-white/80">
                            Welcome back, {auth?.user?.name}.
                        </p>
                    </div>

                    {roleId === 1 && noProfile && (
                        <p className="rounded-md bg-white/90 p-4 text-sm text-gray-700">
                            No student profile is linked to your account yet.
                            Please contact your coordinator.
                        </p>
                    )}

                    {roleId === 1 && !noProfile && (
                        <StudentSummary
                            student={props.student}
                            hours={props.hours}
                            todayAttendance={props.todayAttendance}
                            pendingReportsCount={props.pendingReportsCount}
                            recentReports={props.recentReports}
                        />
                    )}

                    {(roleId === 2 || roleId === 4) && (
                        <StaffSummary counts={props.counts} />
                    )}

                    {roleId === 3 && (
                        <SupervisorSummary
                            counts={props.counts}
                            supervisedStudents={props.supervisedStudents}
                        />
                    )}
                </div>
            </div>
        </AuthenticatedLayout>
    );
}
