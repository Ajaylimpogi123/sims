import { useState } from "react";
import AuthenticatedLayout from "@/Layouts/AuthenticatedLayout";
import { Head, usePage } from "@inertiajs/react";
import { Button } from "@/components/ui/button";
import { AttendanceEvidenceInOut } from "@/Components/AttendanceEvidence";
import CaptureDialog from "./Partials/CaptureDialog";
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from "@/components/ui/table";
import {
    formatLongDate,
    formatLongDateWithWeekday,
    formatTime,
} from "@/lib/dates";

const STATUS_STYLES = {
    pending: "bg-amber-100 text-amber-700",
    approved: "bg-green-100 text-green-700",
    rejected: "bg-red-100 text-red-700",
};

function StatusBadge({ status }) {
    if (!status) return <span className="text-muted-foreground">-</span>;

    return (
        <span
            className={`inline-flex items-center rounded-full px-2 py-0.5 text-xs font-medium capitalize ${
                STATUS_STYLES[status] || "bg-gray-100 text-gray-600"
            }`}
        >
            {status}
        </span>
    );
}

export default function Index({
    student,
    attendances,
    totalRenderedHours,
    todayRecord,
}) {
    const { flash } = usePage().props;
    // "time_in" | "time_out" | "emergency" while the capture dialog is open.
    // The dialog is only mounted while open so the camera is released on close.
    const [captureMode, setCaptureMode] = useState(null);

    const timeInStatus = todayRecord?.time_in_status;
    const timeOutStatus = todayRecord?.time_out_status;

    const canTimeIn = !timeInStatus || timeInStatus === "rejected";
    const canTimeOut =
        timeInStatus === "approved" &&
        (!timeOutStatus || timeOutStatus === "rejected");
    // Mirrors AttendanceController::emergencyTimeOut — a rejected time-in
    // can't be followed by an emergency time-out.
    const canReportEmergency =
        !!todayRecord?.time_in &&
        timeInStatus !== "rejected" &&
        (!timeOutStatus || timeOutStatus === "rejected");

    const requiredHours = student.required_hours;
    const remainingHours =
        requiredHours != null
            ? Math.max(requiredHours - totalRenderedHours, 0)
            : null;

    return (
        <AuthenticatedLayout>
            <Head title="My Attendance" />

            <div className="relative z-10 py-8">
                <div className="flex-1 space-y-6 p-4 md:p-6">
                    <div className="mb-8">
                        <h1 className="text-3xl font-bold tracking-tight text-white">
                            My Attendance
                        </h1>
                        <p className="mt-2 text-sm text-white">
                            Time in and out each day, and track your
                            internship hours
                        </p>
                    </div>

                    {flash?.success && (
                        <div className="rounded-md border border-green-200 bg-green-50 px-4 py-3 text-sm text-green-700">
                            {flash.success}
                        </div>
                    )}
                    {flash?.error && (
                        <div className="rounded-md border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700">
                            {flash.error}
                        </div>
                    )}

                    <div className="grid gap-4 sm:grid-cols-3">
                        <div className="rounded-sm border bg-card p-4 text-card-foreground shadow">
                            <p className="text-sm text-muted-foreground">
                                Hours Rendered
                            </p>
                            <p className="text-2xl font-bold">
                                {totalRenderedHours}
                            </p>
                        </div>
                        <div className="rounded-sm border bg-card p-4 text-card-foreground shadow">
                            <p className="text-sm text-muted-foreground">
                                Required Hours
                            </p>
                            <p className="text-2xl font-bold">
                                {requiredHours ?? "Not set"}
                            </p>
                        </div>
                        <div className="rounded-sm border bg-card p-4 text-card-foreground shadow">
                            <p className="text-sm text-muted-foreground">
                                Remaining Hours
                            </p>
                            <p className="text-2xl font-bold">
                                {remainingHours ?? "—"}
                            </p>
                        </div>
                    </div>

                    <div className="rounded-sm border bg-card p-6 text-card-foreground shadow space-y-4">
                        <div className="flex flex-col items-start gap-4 sm:flex-row sm:items-center sm:justify-between">
                            <div className="space-y-1">
                                <p className="font-medium">
                                    Today —{" "}
                                    {formatLongDateWithWeekday(new Date())}
                                </p>
                                <p className="text-sm text-muted-foreground flex items-center gap-2">
                                    Time In:{" "}
                                    {todayRecord?.time_in
                                        ? formatTime(todayRecord.time_in)
                                        : "Not recorded"}{" "}
                                    <StatusBadge status={timeInStatus} />
                                </p>
                                <p className="text-sm text-muted-foreground flex items-center gap-2">
                                    Time Out:{" "}
                                    {todayRecord?.time_out
                                        ? formatTime(todayRecord.time_out)
                                        : "Not recorded"}{" "}
                                    <StatusBadge status={timeOutStatus} />
                                </p>
                                {timeInStatus === "rejected" &&
                                    todayRecord?.time_in_rejection_reason && (
                                        <p className="text-sm text-red-600">
                                            Time-in rejected:{" "}
                                            {
                                                todayRecord.time_in_rejection_reason
                                            }
                                        </p>
                                    )}
                                {timeOutStatus === "rejected" &&
                                    todayRecord?.time_out_rejection_reason && (
                                        <p className="text-sm text-red-600">
                                            Time-out rejected:{" "}
                                            {
                                                todayRecord.time_out_rejection_reason
                                            }
                                        </p>
                                    )}
                            </div>

                            <div className="flex gap-2">
                                <Button
                                    onClick={() => setCaptureMode("time_in")}
                                    disabled={!canTimeIn}
                                >
                                    Time In
                                </Button>
                                <Button
                                    onClick={() => setCaptureMode("time_out")}
                                    disabled={!canTimeOut}
                                    variant="outline"
                                >
                                    Time Out
                                </Button>
                            </div>
                        </div>

                        <p className="text-xs text-muted-foreground">
                            Time in and time out require a live photo from
                            your camera and your current location.
                        </p>

                        {canReportEmergency && (
                            <div className="border-t pt-4">
                                <Button
                                    variant="link"
                                    className="h-auto p-0 text-sm text-red-600"
                                    onClick={() => setCaptureMode("emergency")}
                                >
                                    Report Emergency (time out without an
                                    approved time-in)
                                </Button>
                            </div>
                        )}
                    </div>

                    {captureMode && (
                        <CaptureDialog
                            key={captureMode}
                            mode={captureMode}
                            onClose={() => setCaptureMode(null)}
                        />
                    )}

                    <div className="rounded-sm border bg-card text-card-foreground shadow">
                        <div className="p-6">
                            <Table>
                                <TableHeader>
                                    <TableRow>
                                        <TableHead>Date</TableHead>
                                        <TableHead>Time In</TableHead>
                                        <TableHead>In Status</TableHead>
                                        <TableHead>Time Out</TableHead>
                                        <TableHead>Out Status</TableHead>
                                        <TableHead>Hours Rendered</TableHead>
                                        <TableHead>Evidence</TableHead>
                                    </TableRow>
                                </TableHeader>
                                <TableBody>
                                    {attendances.length ? (
                                        attendances.map((record) => (
                                            <TableRow key={record.id}>
                                                <TableCell>
                                                    {formatLongDate(
                                                        record.date,
                                                    )}
                                                </TableCell>
                                                <TableCell>
                                                    {formatTime(
                                                        record.time_in,
                                                        "-",
                                                    )}
                                                </TableCell>
                                                <TableCell>
                                                    <StatusBadge
                                                        status={
                                                            record.time_in_status
                                                        }
                                                    />
                                                </TableCell>
                                                <TableCell>
                                                    {formatTime(
                                                        record.time_out,
                                                        "-",
                                                    )}
                                                </TableCell>
                                                <TableCell>
                                                    <StatusBadge
                                                        status={
                                                            record.time_out_status
                                                        }
                                                    />
                                                </TableCell>
                                                <TableCell>
                                                    {record.rendered_hours ??
                                                        "-"}
                                                </TableCell>
                                                <TableCell>
                                                    <AttendanceEvidenceInOut
                                                        attendance={record}
                                                    />
                                                </TableCell>
                                            </TableRow>
                                        ))
                                    ) : (
                                        <TableRow>
                                            <TableCell
                                                colSpan={7}
                                                className="h-24 text-center"
                                            >
                                                No attendance records yet.
                                            </TableCell>
                                        </TableRow>
                                    )}
                                </TableBody>
                            </Table>
                        </div>
                    </div>
                </div>
            </div>
        </AuthenticatedLayout>
    );
}
