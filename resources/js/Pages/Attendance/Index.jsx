import { useState } from "react";
import AuthenticatedLayout from "@/Layouts/AuthenticatedLayout";
import { Head, router, usePage } from "@inertiajs/react";
import { Button } from "@/components/ui/button";
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
    const [showEmergencyForm, setShowEmergencyForm] = useState(false);
    const [note, setNote] = useState("");

    const timeInStatus = todayRecord?.time_in_status;
    const timeOutStatus = todayRecord?.time_out_status;

    const canTimeIn = !timeInStatus || timeInStatus === "rejected";
    const canTimeOut =
        timeInStatus === "approved" &&
        (!timeOutStatus || timeOutStatus === "rejected");
    const canReportEmergency =
        !!todayRecord?.time_in &&
        (!timeOutStatus || timeOutStatus === "rejected");

    const handleTimeIn = () => {
        router.post(route("attendance.time-in"), {}, { preserveScroll: true });
    };

    const handleTimeOut = () => {
        router.post(
            route("attendance.time-out"),
            {},
            { preserveScroll: true },
        );
    };

    const handleEmergencySubmit = (e) => {
        e.preventDefault();
        router.post(
            route("attendance.emergency-time-out"),
            { note },
            {
                preserveScroll: true,
                onSuccess: () => {
                    setNote("");
                    setShowEmergencyForm(false);
                },
            },
        );
    };

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
                                    onClick={handleTimeIn}
                                    disabled={!canTimeIn}
                                >
                                    Time In
                                </Button>
                                <Button
                                    onClick={handleTimeOut}
                                    disabled={!canTimeOut}
                                    variant="outline"
                                >
                                    Time Out
                                </Button>
                            </div>
                        </div>

                        {canReportEmergency && (
                            <div className="border-t pt-4">
                                {!showEmergencyForm ? (
                                    <Button
                                        variant="link"
                                        className="h-auto p-0 text-sm text-red-600"
                                        onClick={() =>
                                            setShowEmergencyForm(true)
                                        }
                                    >
                                        Report Emergency (time out without an
                                        approved time-in)
                                    </Button>
                                ) : (
                                    <form
                                        onSubmit={handleEmergencySubmit}
                                        className="space-y-2"
                                    >
                                        <textarea
                                            placeholder="Explain the emergency..."
                                            value={note}
                                            onChange={(e) =>
                                                setNote(e.target.value)
                                            }
                                            required
                                            rows={3}
                                            className="flex w-full rounded-md border border-input bg-transparent px-3 py-2 text-sm shadow-sm placeholder:text-muted-foreground focus-visible:outline-none focus-visible:ring-1 focus-visible:ring-ring disabled:cursor-not-allowed disabled:opacity-50"
                                        />
                                        <div className="flex gap-2">
                                            <Button type="submit" size="sm">
                                                Submit Emergency Time Out
                                            </Button>
                                            <Button
                                                type="button"
                                                size="sm"
                                                variant="outline"
                                                onClick={() => {
                                                    setShowEmergencyForm(
                                                        false,
                                                    );
                                                    setNote("");
                                                }}
                                            >
                                                Cancel
                                            </Button>
                                        </div>
                                    </form>
                                )}
                            </div>
                        )}
                    </div>

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
                                            </TableRow>
                                        ))
                                    ) : (
                                        <TableRow>
                                            <TableCell
                                                colSpan={6}
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
