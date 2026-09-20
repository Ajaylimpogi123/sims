import AuthenticatedLayout from "@/Layouts/AuthenticatedLayout";
import { Head, router } from "@inertiajs/react";
import { Button } from "@/components/ui/button";
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from "@/components/ui/table";
import { formatLongDate, formatTime } from "@/lib/dates";

function buildRequests(attendances) {
    const requests = [];

    attendances.forEach((attendance) => {
        if (attendance.time_in_status === "pending") {
            requests.push({
                key: `${attendance.id}-in`,
                attendance,
                type: "time_in",
                label: "Time In",
                requestedAt: attendance.time_in,
                isEmergency: false,
                note: null,
            });
        }

        if (attendance.time_out_status === "pending") {
            requests.push({
                key: `${attendance.id}-out`,
                attendance,
                type: "time_out",
                label: attendance.is_emergency
                    ? "Time Out (Emergency)"
                    : "Time Out",
                requestedAt: attendance.time_out,
                isEmergency: attendance.is_emergency,
                note: attendance.note,
            });
        }
    });

    return requests;
}

export default function Index({ attendances }) {
    const requests = buildRequests(attendances);

    const handleApprove = (request) => {
        const routeName =
            request.type === "time_in"
                ? "attendance-approvals.approve-time-in"
                : "attendance-approvals.approve-time-out";

        router.patch(
            route(routeName, request.attendance.id),
            {},
            { preserveScroll: true },
        );
    };

    const handleReject = (request) => {
        const reason = prompt("Reason for rejection (optional):");
        if (reason === null) return;

        const routeName =
            request.type === "time_in"
                ? "attendance-approvals.reject-time-in"
                : "attendance-approvals.reject-time-out";

        router.patch(
            route(routeName, request.attendance.id),
            { reason },
            { preserveScroll: true },
        );
    };

    return (
        <AuthenticatedLayout>
            <Head title="Pending Approvals" />

            <div className="relative z-10 py-8">
                <div className="flex-1 space-y-6 p-4 md:p-6">
                    <div className="mb-8">
                        <h1 className="text-3xl font-bold tracking-tight text-white">
                            Pending Approvals
                        </h1>
                        <p className="mt-2 text-sm text-white">
                            Review and approve or reject student time-in and
                            time-out requests
                        </p>
                    </div>

                    <div className="rounded-sm border bg-card text-card-foreground shadow">
                        <div className="p-6">
                            <Table>
                                <TableHeader>
                                    <TableRow>
                                        <TableHead>Student</TableHead>
                                        <TableHead>Date</TableHead>
                                        <TableHead>Type</TableHead>
                                        <TableHead>Requested Time</TableHead>
                                        <TableHead>Note</TableHead>
                                        <TableHead>Actions</TableHead>
                                    </TableRow>
                                </TableHeader>
                                <TableBody>
                                    {requests.length ? (
                                        requests.map((request) => (
                                            <TableRow key={request.key}>
                                                <TableCell>
                                                    {
                                                        request.attendance
                                                            .student?.user
                                                            ?.name
                                                    }
                                                </TableCell>
                                                <TableCell>
                                                    {formatLongDate(
                                                        request.attendance
                                                            .date,
                                                    )}
                                                </TableCell>
                                                <TableCell>
                                                    {request.isEmergency ? (
                                                        <span className="inline-flex items-center rounded-full bg-red-100 px-2 py-0.5 text-xs font-medium text-red-700">
                                                            {request.label}
                                                        </span>
                                                    ) : (
                                                        request.label
                                                    )}
                                                </TableCell>
                                                <TableCell>
                                                    {formatTime(
                                                        request.requestedAt,
                                                    )}
                                                </TableCell>
                                                <TableCell>
                                                    {request.note || "-"}
                                                </TableCell>
                                                <TableCell className="flex gap-2">
                                                    <Button
                                                        size="sm"
                                                        onClick={() =>
                                                            handleApprove(
                                                                request,
                                                            )
                                                        }
                                                    >
                                                        Approve
                                                    </Button>
                                                    <Button
                                                        size="sm"
                                                        variant="destructive"
                                                        onClick={() =>
                                                            handleReject(
                                                                request,
                                                            )
                                                        }
                                                    >
                                                        Reject
                                                    </Button>
                                                </TableCell>
                                            </TableRow>
                                        ))
                                    ) : (
                                        <TableRow>
                                            <TableCell
                                                colSpan={6}
                                                className="h-24 text-center"
                                            >
                                                No pending requests.
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
