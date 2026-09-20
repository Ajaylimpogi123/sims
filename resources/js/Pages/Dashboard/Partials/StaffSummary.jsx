import { GraduationCap, Building2, ClipboardCheck, FileText } from "lucide-react";
import StatCard from "./StatCard";

export default function StaffSummary({ counts }) {
    return (
        <div className="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4">
            <StatCard
                title="Students"
                value={counts.students}
                subtitle="Total registered students"
                icon={GraduationCap}
                iconColor="text-blue-600"
                iconBg="bg-blue-50"
            />
            <StatCard
                title="Active Companies"
                value={counts.companies}
                subtitle="Partner companies"
                icon={Building2}
                iconColor="text-purple-600"
                iconBg="bg-purple-50"
            />
            <StatCard
                title="Pending Approvals"
                value={counts.pendingApprovals}
                subtitle="Time-in / time-out requests"
                icon={ClipboardCheck}
                iconColor="text-amber-600"
                iconBg="bg-amber-50"
            />
            <StatCard
                title="Pending Report Reviews"
                value={counts.pendingReportReviews}
                subtitle="Awaiting your review"
                icon={FileText}
                iconColor="text-emerald-600"
                iconBg="bg-emerald-50"
            />
        </div>
    );
}
