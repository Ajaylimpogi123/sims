import { Card, CardContent, CardHeader, CardTitle } from "@/Components/ui/card";
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from "@/Components/ui/table";
import { Clock, FileText, Building2, CalendarRange } from "lucide-react";
import { formatLongDate, formatTime } from "@/lib/dates";
import StatCard from "./StatCard";
import HoursProgressBar from "./HoursProgressBar";
import SubmissionClosedNotice from "@/Components/SubmissionClosedNotice";
import useTerm from "@/hooks/useTerm";

const STATUS_LABELS = {
    not_started: "Not Started",
    ongoing: "Ongoing",
    completed: "Completed",
};

const REPORT_STATUS_STYLES = {
    pending: "bg-amber-100 text-amber-700",
    reviewed: "bg-green-100 text-green-700",
};

export default function StudentSummary({
    student,
    hours,
    todayAttendance,
    pendingReportsCount,
    recentReports,
}) {
    // Students' selectedTerm is always their own student row's term.
    const { selectedTerm } = useTerm();

    return (
        <div className="space-y-6">
            {selectedTerm && (
                <p className="inline-flex max-w-full items-center gap-2 rounded-full bg-white/90 px-3 py-1 text-sm text-gray-700 shadow-sm">
                    <CalendarRange className="h-4 w-4 shrink-0 text-primary" />
                    <span className="truncate">
                        Term:{" "}
                        <span className="font-medium text-gray-900">
                            {selectedTerm.label}
                        </span>
                    </span>
                    {selectedTerm.is_current === false && (
                        <span className="shrink-0 rounded-full bg-amber-100 px-2 py-0.5 text-xs font-medium text-amber-800">
                            Ended
                        </span>
                    )}
                </p>
            )}

            {student.can_submit === false && (
                <SubmissionClosedNotice
                    internshipStatus={student.internship_status}
                />
            )}

            <div className="grid grid-cols-1 gap-4 sm:grid-cols-3">
                <StatCard
                    title="Hours Rendered"
                    value={hours.rendered}
                    subtitle={
                        hours.required != null
                            ? `of ${hours.required} required`
                            : "No target set yet"
                    }
                    icon={Clock}
                />
                <StatCard
                    title="Internship Status"
                    value={STATUS_LABELS[student.internship_status] ?? "Not Started"}
                    subtitle={
                        student.company_name
                            ? student.supervisor_name
                                ? `${student.company_name} · Supervisor: ${student.supervisor_name}`
                                : student.company_name
                            : "No company assigned yet"
                    }
                    icon={Building2}
                    iconColor="text-blue-600"
                    iconBg="bg-blue-50"
                />
                <StatCard
                    title="Pending Reports"
                    value={pendingReportsCount}
                    subtitle="Awaiting reviewer feedback"
                    icon={FileText}
                    iconColor="text-amber-600"
                    iconBg="bg-amber-50"
                />
            </div>

            <Card className="border-0 shadow-sm">
                <CardHeader>
                    <CardTitle>Hours Progress</CardTitle>
                </CardHeader>
                <CardContent>
                    <HoursProgressBar
                        rendered={hours.rendered}
                        required={hours.required}
                    />
                </CardContent>
            </Card>

            <Card className="border-0 shadow-sm">
                <CardHeader>
                    <CardTitle>Today's Attendance</CardTitle>
                </CardHeader>
                <CardContent>
                    {todayAttendance ? (
                        <p className="text-sm text-muted-foreground">
                            Time In: {formatTime(todayAttendance.time_in)} (
                            {todayAttendance.time_in_status ?? "not submitted"}) · Time
                            Out: {formatTime(todayAttendance.time_out)} (
                            {todayAttendance.time_out_status ?? "not submitted"})
                        </p>
                    ) : (
                        <p className="text-sm text-muted-foreground">
                            You haven't timed in today.
                        </p>
                    )}
                </CardContent>
            </Card>

            <Card className="border-0 shadow-sm">
                <CardHeader>
                    <CardTitle>Recent Reports</CardTitle>
                </CardHeader>
                <CardContent>
                    {recentReports.length === 0 ? (
                        <p className="text-sm text-muted-foreground">
                            No reports submitted yet.
                        </p>
                    ) : (
                        <Table>
                            <TableHeader>
                                <TableRow>
                                    <TableHead>Type</TableHead>
                                    <TableHead>Period</TableHead>
                                    <TableHead>Status</TableHead>
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                {recentReports.map((report) => (
                                    <TableRow key={report.id}>
                                        <TableCell className="capitalize">
                                            {report.type}
                                        </TableCell>
                                        <TableCell>
                                            {formatLongDate(report.period_start)} –{" "}
                                            {formatLongDate(report.period_end)}
                                        </TableCell>
                                        <TableCell>
                                            <span
                                                className={`inline-flex items-center rounded-full px-2 py-0.5 text-xs font-medium ${
                                                    REPORT_STATUS_STYLES[report.status] ??
                                                    REPORT_STATUS_STYLES.pending
                                                }`}
                                            >
                                                {report.status}
                                            </span>
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
