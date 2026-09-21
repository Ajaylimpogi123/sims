import {
    GraduationCap,
    Briefcase,
    Building2,
    UserCog,
    UserX,
    ClipboardCheck,
    TrendingUp,
    Percent,
    FileText,
    AlertTriangle,
} from "lucide-react";
import StatCard from "@/Components/StatCard";

export default function CoordinatorSummary({ kpis }) {
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
                title="Active Internships"
                value={kpis.activeInternshipAssignments.value}
                subtitle="Currently ongoing"
                icon={Briefcase}
                iconColor="text-emerald-600"
                iconBg="bg-emerald-50"
                href={route("internship-assignment.index")}
            />
            <StatCard
                title="Active Companies"
                value={kpis.companies.value}
                subtitle="Partner companies"
                icon={Building2}
                iconColor="text-purple-600"
                iconBg="bg-purple-50"
                href={route("company-management.index")}
            />
            <StatCard
                title="Active Supervisors"
                value={kpis.activeSupervisors.value}
                subtitle="Assigned/active supervisors"
                icon={UserCog}
                iconColor="text-indigo-600"
                iconBg="bg-indigo-50"
            />
            <StatCard
                title="Without Company"
                value={kpis.studentsWithoutCompany.value}
                subtitle="Students needing assignment"
                icon={UserX}
                iconColor="text-orange-600"
                iconBg="bg-orange-50"
                href={route("internship-assignment.index")}
            />
            <StatCard
                title="Pending Approvals"
                value={kpis.pendingApprovals.value}
                subtitle="Time-in / time-out requests"
                icon={ClipboardCheck}
                iconColor="text-amber-600"
                iconBg="bg-amber-50"
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
                title="Completion Progress"
                value={`${kpis.internshipCompletionProgress.value}%`}
                subtitle="Average across active students"
                icon={Percent}
                iconColor="text-teal-600"
                iconBg="bg-teal-50"
                href={route("progress-monitoring.index")}
            />
            <StatCard
                title="Reports Awaiting Review"
                value={kpis.reportsAwaitingReview.value}
                subtitle="Pending internship reports"
                icon={FileText}
                iconColor="text-cyan-600"
                iconBg="bg-cyan-50"
                href={route("report-reviews.index")}
            />
            <StatCard
                title="Requiring Attention"
                value={kpis.studentsRequiringAttention.value}
                subtitle="Repeated attendance rejections"
                icon={AlertTriangle}
                iconColor="text-red-600"
                iconBg="bg-red-50"
                href={route("attendance-monitoring.index")}
            />
        </div>
    );
}
