import AuthenticatedLayout from "@/Layouts/AuthenticatedLayout";
import { Deferred, Head, usePage } from "@inertiajs/react";
import { Tabs, TabsContent, TabsList, TabsTrigger } from "@/components/ui/tabs";
import StudentSummary from "./Partials/StudentSummary";
import AdminSummary from "./Partials/AdminSummary";
import CoordinatorSummary from "./Partials/CoordinatorSummary";
import SupervisorSummary from "./Partials/SupervisorSummary";
import ActionItems from "./Partials/ActionItems";
import RecentActivity from "./Partials/RecentActivity";
import AnalyticsTab from "./Partials/AnalyticsTab";
import AnalyticsSkeleton from "./Partials/AnalyticsSkeleton";

const ROLE_TITLES = {
    1: "My Dashboard",
    2: "Coordinator Dashboard",
    3: "Supervisor Dashboard",
    4: "Administrator Dashboard",
};

// Analytics tab is Admin/Coordinator/Supervisor only — the Student
// dashboard branch is untouched by this module.
const HAS_ANALYTICS = [2, 3, 4];

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

                    {HAS_ANALYTICS.includes(roleId) && (
                        <Tabs defaultValue="overview" className="w-full">
                            <TabsList>
                                <TabsTrigger value="overview">Overview</TabsTrigger>
                                <TabsTrigger value="analytics">Analytics</TabsTrigger>
                            </TabsList>

                            <TabsContent value="overview" className="space-y-6">
                                {roleId === 4 && (
                                    <AdminSummary kpis={props.kpis} />
                                )}
                                {roleId === 2 && (
                                    <CoordinatorSummary kpis={props.kpis} />
                                )}
                                {roleId === 3 && (
                                    <SupervisorSummary
                                        kpis={props.kpis}
                                        supervisedStudents={props.supervisedStudents}
                                    />
                                )}

                                <div className="grid grid-cols-1 gap-4 lg:grid-cols-2">
                                    <ActionItems actionItems={props.actionItems ?? []} />
                                    <RecentActivity
                                        recentActivity={props.recentActivity ?? []}
                                    />
                                </div>
                            </TabsContent>

                            <TabsContent value="analytics">
                                <Deferred data="analytics" fallback={<AnalyticsSkeleton />}>
                                    <AnalyticsTab
                                        roleId={roleId}
                                        filterOptions={props.filterOptions}
                                    />
                                </Deferred>
                            </TabsContent>
                        </Tabs>
                    )}
                </div>
            </div>
        </AuthenticatedLayout>
    );
}
