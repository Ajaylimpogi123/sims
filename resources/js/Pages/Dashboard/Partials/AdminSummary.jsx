import {
    GraduationCap,
    Building2,
    UserCog,
    Briefcase,
    CheckCircle2,
    ClipboardCheck,
    TrendingUp,
    AlertTriangle,
    ClipboardList,
} from "lucide-react";
import StatCard from "@/Components/StatCard";

export default function AdminSummary({ kpis }) {
    return (
        <div className="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4">
            <StatCard
                title="Total Students"
                value={kpis.totalStudents.value}
                trend={kpis.totalStudents.trend}
                trendLabel="vs last month"
                subtitle="All registered students"
                icon={GraduationCap}
                iconColor="text-blue-600"
                iconBg="bg-blue-50"
            />
            <StatCard
                title="Active Companies"
                value={kpis.totalCompanies.value}
                subtitle="Partner companies"
                icon={Building2}
                iconColor="text-purple-600"
                iconBg="bg-purple-50"
            />
            <StatCard
                title="Total Supervisors"
                value={kpis.totalSupervisors.value}
                subtitle="Active supervisor accounts"
                icon={UserCog}
                iconColor="text-indigo-600"
                iconBg="bg-indigo-50"
            />
            <StatCard
                title="Active Internships"
                value={kpis.activeInternshipAssignments.value}
                subtitle="Currently ongoing"
                icon={Briefcase}
                iconColor="text-emerald-600"
                iconBg="bg-emerald-50"
                href={route("internship-assignment.index")}
            />
            <StatCard
                title="Completed Internships"
                value={kpis.completedInternships.value}
                subtitle="Finished this program"
                icon={CheckCircle2}
                iconColor="text-green-600"
                iconBg="bg-green-50"
            />
            <StatCard
                title="Pending Approvals"
                value={kpis.pendingApprovals.value}
                subtitle="Time-in / time-out requests"
                icon={ClipboardCheck}
                iconColor="text-amber-600"
                iconBg="bg-amber-50"
                href={route("attendance-approvals.index")}
            />
            <StatCard
                title="Attendance Summary"
                value={`${kpis.attendanceSummary.present} present`}
                subtitle={`${kpis.attendanceSummary.rejected} rejected entries`}
                icon={TrendingUp}
                iconColor="text-sky-600"
                iconBg="bg-sky-50"
            />
            <StatCard
                title="Nearing Completion"
                value={kpis.studentsNearingCompletion.value}
                subtitle="≥90% of required hours"
                icon={ClipboardList}
                iconColor="text-teal-600"
                iconBg="bg-teal-50"
                href={route("progress-monitoring.index")}
            />
            <StatCard
                title="Attendance Issues"
                value={kpis.studentsWithAttendanceIssues.value}
                subtitle="Students with repeated rejections"
                icon={AlertTriangle}
                iconColor="text-red-600"
                iconBg="bg-red-50"
                href={route("attendance-monitoring.index")}
            />
            <StatCard
                title="Pending Evaluations"
                value={kpis.pendingSupervisorEvaluations.value}
                subtitle="Draft supervisor evaluations"
                icon={ClipboardList}
                iconColor="text-fuchsia-600"
                iconBg="bg-fuchsia-50"
                href={route("supervisor-evaluations.index")}
            />
        </div>
    );
}
