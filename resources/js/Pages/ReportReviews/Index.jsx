import AuthenticatedLayout from "@/Layouts/AuthenticatedLayout";
import { Head } from "@inertiajs/react";
import { Button } from "@/Components/ui/button";
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from "@/Components/ui/table";
import ReviewModal from "./Partials/ReviewModal";
import { formatLongDate } from "@/lib/dates";
import ExportButtons from "@/Components/ExportButtons";
import { usePage } from "@inertiajs/react";
import useHighlightRow from "@/hooks/useHighlightRow";

const SUPERVISOR_ROLE_ID = 3;

const STATUS_STYLES = {
    pending: "bg-amber-100 text-amber-700",
    reviewed: "bg-green-100 text-green-700",
};

function StatusBadge({ status }) {
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

const EXPORT_COLUMNS = [
    { header: "Student", accessor: "student_name" },
    { header: "Type", accessor: "type" },
    { header: "Period", accessor: "period" },
    { header: "Status", accessor: "status" },
];

function reportPeriod(report) {
    if (report.type === "daily") {
        return formatLongDate(report.period_start);
    }

    return `${formatLongDate(report.period_start)} — ${formatLongDate(report.period_end)}`;
}

function capitalize(value) {
    if (!value) return value;
    return value.charAt(0).toUpperCase() + value.slice(1);
}

export default function Index({ reports }) {
    const { auth } = usePage().props;
    const canReview = auth?.user?.role_id !== SUPERVISOR_ROLE_ID;
    const { getRowProps } = useHighlightRow();

    const exportData = reports.map((report) => ({
        student_name: report.student?.user?.name || "-",
        type: capitalize(report.type),
        period: reportPeriod(report),
        status: capitalize(report.status),
    }));

    return (
        <AuthenticatedLayout>
            <Head title="Report Reviews" />

            <div className="relative z-10 py-8">
                <div className="flex-1 space-y-6 p-4 md:p-6">
                    <div className="mb-8 flex flex-col gap-4 sm:flex-row sm:items-end sm:justify-between">
                        <div>
                            <h1 className="text-3xl font-bold tracking-tight text-white">
                                Report Reviews
                            </h1>
                            <p className="mt-2 text-sm text-white">
                                Review student accomplishment reports and
                                leave feedback
                            </p>
                        </div>
                        <ExportButtons
                            columns={EXPORT_COLUMNS}
                            rows={exportData}
                            filename="report-reviews"
                            title="Report Reviews"
                        />
                    </div>

                    <div className="rounded-sm border bg-card text-card-foreground shadow">
                        <div className="p-6">
                            <Table>
                                <TableHeader>
                                    <TableRow>
                                        <TableHead>Student</TableHead>
                                        <TableHead>Type</TableHead>
                                        <TableHead>Period</TableHead>
                                        <TableHead>Status</TableHead>
                                        <TableHead>Attachment</TableHead>
                                        <TableHead>Actions</TableHead>
                                    </TableRow>
                                </TableHeader>
                                <TableBody>
                                    {reports.length ? (
                                        reports.map((report) => (
                                            <TableRow
                                                key={report.id}
                                                {...getRowProps(report.id)}
                                            >
                                                <TableCell>
                                                    {report.student?.user?.name}
                                                </TableCell>
                                                <TableCell className="capitalize">
                                                    {report.type}
                                                </TableCell>
                                                <TableCell>
                                                    {report.type === "daily"
                                                        ? formatLongDate(
                                                              report.period_start,
                                                          )
                                                        : `${formatLongDate(
                                                              report.period_start,
                                                          )} — ${formatLongDate(
                                                              report.period_end,
                                                          )}`}
                                                </TableCell>
                                                <TableCell>
                                                    <StatusBadge
                                                        status={report.status}
                                                    />
                                                </TableCell>
                                                <TableCell>
                                                    {report.attachment_path ? (
                                                        <a
                                                            href={route(
                                                                "report-reviews.attachment",
                                                                report.id,
                                                            )}
                                                            target="_blank"
                                                            rel="noreferrer"
                                                            className="text-blue-600 underline"
                                                        >
                                                            View
                                                        </a>
                                                    ) : (
                                                        "-"
                                                    )}
                                                </TableCell>
                                                <TableCell>
                                                    <ReviewModal
                                                        report={report}
                                                    >
                                                        <Button size="sm">
                                                            {canReview &&
                                                            report.status ===
                                                                "pending"
                                                                ? "Review"
                                                                : "View"}
                                                        </Button>
                                                    </ReviewModal>
                                                </TableCell>
                                            </TableRow>
                                        ))
                                    ) : (
                                        <TableRow>
                                            <TableCell
                                                colSpan={6}
                                                className="h-24 text-center"
                                            >
                                                No reports to review.
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
