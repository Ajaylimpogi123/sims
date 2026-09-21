import { Card, CardContent, CardHeader, CardTitle } from "@/Components/ui/card";
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from "@/components/ui/table";
import {
    Users,
    Briefcase,
    CalendarCheck,
    ClipboardCheck,
    FileText,
    ClipboardList,
    CheckCircle2,
    AlertTriangle,
} from "lucide-react";
import StatCard from "@/Components/StatCard";
import HoursProgressBar from "./HoursProgressBar";

const STATUS_LABELS = {
    not_started: "Not Started",
    ongoing: "Ongoing",
    completed: "Completed",
};

export default function SupervisorSummary({ kpis, supervisedStudents }) {
    return (
        <div className="space-y-6">
            <div className="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4">
                <StatCard
                    title="Assigned Students"
                    value={kpis.assignedStudents.value}
                    subtitle="Assigned to you"
                    icon={Users}
                    iconColor="text-blue-600"
                    iconBg="bg-blue-50"
                />
                <StatCard
                    title="Active Internships"
                    value={kpis.activeInternships.value}
                    subtitle="Currently ongoing"
                    icon={Briefcase}
                    iconColor="text-emerald-600"
                    iconBg="bg-emerald-50"
                    href={route("progress-monitoring.index")}
                />
                <StatCard
                    title="Today's Attendance"
                    value={kpis.todaysAttendance.value}
                    subtitle="Present today"
                    icon={CalendarCheck}
                    iconColor="text-sky-600"
                    iconBg="bg-sky-50"
                />
                <StatCard
                    title="Pending Approvals"
                    value={kpis.pendingAttendanceApprovals.value}
                    subtitle="Awaiting your action"
                    icon={ClipboardCheck}
                    iconColor="text-amber-600"
                    iconBg="bg-amber-50"
                    href={route("attendance-approvals.index")}
                />
                <StatCard
                    title="Reports Awaiting Review"
                    value={kpis.reportsAwaitingReview.value}
                    subtitle="Awaiting your review"
                    icon={FileText}
                    iconColor="text-cyan-600"
                    iconBg="bg-cyan-50"
                    href={route("report-reviews.index")}
                />
                <StatCard
                    title="Pending Evaluations"
                    value={kpis.pendingEvaluations.value}
                    subtitle="Still in draft"
                    icon={ClipboardList}
                    iconColor="text-fuchsia-600"
                    iconBg="bg-fuchsia-50"
                    href={route("supervisor-evaluations.index")}
                />
                <StatCard
                    title="Completed Evaluations"
                    value={kpis.completedEvaluations.value}
                    subtitle="Submitted or locked"
                    icon={CheckCircle2}
                    iconColor="text-green-600"
                    iconBg="bg-green-50"
                    href={route("supervisor-evaluations.index")}
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

            <Card className="border-0 shadow-sm">
                <CardHeader>
                    <CardTitle>Your Students</CardTitle>
                </CardHeader>
                <CardContent>
                    {supervisedStudents.length === 0 ? (
                        <p className="text-sm text-muted-foreground">
                            No students are assigned to you yet.
                        </p>
                    ) : (
                        <Table>
                            <TableHeader>
                                <TableRow>
                                    <TableHead>Name</TableHead>
                                    <TableHead>Status</TableHead>
                                    <TableHead>Progress</TableHead>
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                {supervisedStudents.map((student) => (
                                    <TableRow key={student.id}>
                                        <TableCell>{student.name}</TableCell>
                                        <TableCell>
                                            {STATUS_LABELS[student.internship_status] ??
                                                "Not Started"}
                                        </TableCell>
                                        <TableCell>
                                            <HoursProgressBar
                                                rendered={student.rendered_hours}
                                                required={student.required_hours}
                                            />
                                        </TableCell>
                                    </TableRow>
                                ))}
                            </TableBody>
                        </Table>
                    )}
                </CardContent>
            </Card>
        </div>
    );
}
