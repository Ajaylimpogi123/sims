import { useState } from "react";
import AuthenticatedLayout from "@/Layouts/AuthenticatedLayout";
import { Head, useForm, usePage } from "@inertiajs/react";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from "@/components/ui/select";
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from "@/components/ui/table";
import InputError from "@/Components/InputError";
import EditReportModal from "./Partials/EditReportModal";
import { router } from "@inertiajs/react";
import { formatLongDate } from "@/lib/dates";

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

export default function Index({ reports }) {
    const { flash } = usePage().props;
    const [showForm, setShowForm] = useState(false);

    const { data, setData, post, processing, errors, reset } = useForm({
        type: "daily",
        period_start: "",
        period_end: "",
        content: "",
        attachment: null,
    });

    const handleTypeChange = (value) => {
        setData((prev) => ({
            ...prev,
            type: value,
            period_end: value === "daily" ? prev.period_start : prev.period_end,
        }));
    };

    const handlePeriodStartChange = (value) => {
        setData((prev) => ({
            ...prev,
            period_start: value,
            period_end: prev.type === "daily" ? value : prev.period_end,
        }));
    };

    const handleSubmit = (e) => {
        e.preventDefault();

        post(route("reports.store"), {
            forceFormData: true,
            preserveScroll: true,
            onSuccess: () => {
                reset();
                setShowForm(false);
            },
        });
    };

    const handleDelete = (report) => {
        if (confirm("Delete this report?")) {
            router.delete(route("reports.destroy", report.id), {
                preserveScroll: true,
            });
        }
    };

    return (
        <AuthenticatedLayout>
            <Head title="My Reports" />

            <div className="relative z-10 py-8">
                <div className="flex-1 space-y-6 p-4 md:p-6">
                    <div className="mb-8 flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
                        <div>
                            <h1 className="text-3xl font-bold tracking-tight text-white">
                                My Reports
                            </h1>
                            <p className="mt-2 text-sm text-white">
                                Submit your daily/weekly accomplishment
                                reports for review
                            </p>
                        </div>
                        <Button onClick={() => setShowForm((prev) => !prev)}>
                            {showForm ? "Cancel" : "New Report"}
                        </Button>
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

                    {showForm && (
                        <div className="rounded-sm border bg-card p-6 text-card-foreground shadow">
                            <form
                                onSubmit={handleSubmit}
                                className="grid gap-4 sm:grid-cols-2"
                            >
                                <div className="grid gap-2">
                                    <Label>Type</Label>
                                    <Select
                                        value={data.type}
                                        onValueChange={handleTypeChange}
                                    >
                                        <SelectTrigger>
                                            <SelectValue />
                                        </SelectTrigger>
                                        <SelectContent>
                                            <SelectItem value="daily">
                                                Daily
                                            </SelectItem>
                                            <SelectItem value="weekly">
                                                Weekly
                                            </SelectItem>
                                        </SelectContent>
                                    </Select>
                                    <InputError message={errors.type} />
                                </div>

                                <div className="grid gap-2">
                                    <Label>
                                        {data.type === "daily"
                                            ? "Date"
                                            : "Period Start"}
                                    </Label>
                                    <Input
                                        type="date"
                                        value={data.period_start}
                                        onChange={(e) =>
                                            handlePeriodStartChange(
                                                e.target.value,
                                            )
                                        }
                                    />
                                    <InputError
                                        message={errors.period_start}
                                    />
                                </div>

                                {data.type === "weekly" && (
                                    <div className="grid gap-2">
                                        <Label>Period End</Label>
                                        <Input
                                            type="date"
                                            value={data.period_end}
                                            onChange={(e) =>
                                                setData(
                                                    "period_end",
                                                    e.target.value,
                                                )
                                            }
                                        />
                                        <InputError
                                            message={errors.period_end}
                                        />
                                    </div>
                                )}

                                <div className="grid gap-2 sm:col-span-2">
                                    <Label>Accomplishment Details</Label>
                                    <textarea
                                        rows={4}
                                        value={data.content}
                                        onChange={(e) =>
                                            setData(
                                                "content",
                                                e.target.value,
                                            )
                                        }
                                        className="flex w-full rounded-md border border-input bg-transparent px-3 py-2 text-sm shadow-sm placeholder:text-muted-foreground focus-visible:outline-none focus-visible:ring-1 focus-visible:ring-ring disabled:cursor-not-allowed disabled:opacity-50"
                                    />
                                    <InputError message={errors.content} />
                                </div>

                                <div className="grid gap-2 sm:col-span-2">
                                    <Label>Attachment (optional)</Label>
                                    <Input
                                        type="file"
                                        accept=".jpg,.jpeg,.png,.pdf"
                                        onChange={(e) =>
                                            setData(
                                                "attachment",
                                                e.target.files[0] || null,
                                            )
                                        }
                                    />
                                    <InputError message={errors.attachment} />
                                </div>

                                <div className="sm:col-span-2">
                                    <Button
                                        type="submit"
                                        disabled={processing}
                                    >
                                        Submit Report
                                    </Button>
                                </div>
                            </form>
                        </div>
                    )}

                    <div className="rounded-sm border bg-card text-card-foreground shadow">
                        <div className="p-6">
                            <Table>
                                <TableHeader>
                                    <TableRow>
                                        <TableHead>Type</TableHead>
                                        <TableHead>Period</TableHead>
                                        <TableHead>Status</TableHead>
                                        <TableHead>Reviewer Comment</TableHead>
                                        <TableHead>Attachment</TableHead>
                                        <TableHead>Actions</TableHead>
                                    </TableRow>
                                </TableHeader>
                                <TableBody>
                                    {reports.length ? (
                                        reports.map((report) => (
                                            <TableRow key={report.id}>
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
                                                    {report.reviewer_comment ||
                                                        "-"}
                                                </TableCell>
                                                <TableCell>
                                                    {report.attachment_path ? (
                                                        <a
                                                            href={route(
                                                                "reports.attachment",
                                                                report.id,
                                                            )}
                                                            target="_blank"
                                                            rel="noreferrer"
                                                            className="text-blue-600 underline"
                                                        >
                                                            {report.attachment_original_name ||
                                                                "Download"}
                                                        </a>
                                                    ) : (
                                                        "-"
                                                    )}
                                                </TableCell>
                                                <TableCell className="flex gap-2">
                                                    {report.status ===
                                                        "pending" && (
                                                        <>
                                                            <EditReportModal
                                                                report={
                                                                    report
                                                                }
                                                            >
                                                                Edit
                                                            </EditReportModal>
                                                            <Button
                                                                size="sm"
                                                                variant="destructive"
                                                                onClick={() =>
                                                                    handleDelete(
                                                                        report,
                                                                    )
                                                                }
                                                            >
                                                                Delete
                                                            </Button>
                                                        </>
                                                    )}
                                                </TableCell>
                                            </TableRow>
                                        ))
                                    ) : (
                                        <TableRow>
                                            <TableCell
                                                colSpan={6}
                                                className="h-24 text-center"
                                            >
                                                No reports submitted yet.
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
