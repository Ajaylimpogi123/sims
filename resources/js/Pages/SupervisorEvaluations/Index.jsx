import { useState } from "react";
import AuthenticatedLayout from "@/Layouts/AuthenticatedLayout";
import { Head, Link, usePage } from "@inertiajs/react";
import { Button } from "@/Components/ui/button";
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from "@/Components/ui/table";
import { formatLongDate } from "@/lib/dates";
import EvaluationFormFields from "./Partials/EvaluationFormFields";
import useCreateEvaluation from "./Hooks/useCreateEvaluation";

const SUPERVISOR_ROLE_ID = 3;

const STATUS_STYLES = {
    draft: "bg-gray-100 text-gray-700",
    submitted: "bg-amber-100 text-amber-700",
    locked: "bg-green-100 text-green-700",
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

export default function Index({ evaluations, students, criteria }) {
    const { auth, flash } = usePage().props;
    const [showForm, setShowForm] = useState(false);
    const canCreate = auth?.user?.role_id === SUPERVISOR_ROLE_ID;

    const {
        data,
        setData,
        responses,
        setResponse,
        averageRating,
        errors,
        processing,
        submit,
    } = useCreateEvaluation(criteria);

    const handleSubmit = (e) => {
        submit(e, { onSuccess: () => setShowForm(false) });
    };

    return (
        <AuthenticatedLayout>
            <Head title="Supervisor Monitoring & Feedback" />

            <div className="relative z-10 py-8">
                <div className="flex-1 space-y-6 p-4 md:p-6">
                    <div className="mb-8 flex flex-col gap-4 sm:flex-row sm:items-end sm:justify-between">
                        <div>
                            <h1 className="text-3xl font-bold tracking-tight text-white">
                                Supervisor Monitoring & Feedback
                            </h1>
                            <p className="mt-2 text-sm text-white">
                                Evaluate assigned students against the
                                configured evaluation criteria
                            </p>
                        </div>

                        {canCreate && (
                            <Button onClick={() => setShowForm((p) => !p)}>
                                {showForm ? "Cancel" : "New Evaluation"}
                            </Button>
                        )}
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

                    {canCreate && showForm && (
                        <div className="rounded-sm border bg-card p-6 text-card-foreground shadow">
                            <form onSubmit={handleSubmit}>
                                <EvaluationFormFields
                                    students={students}
                                    data={data}
                                    setData={setData}
                                    responses={responses}
                                    setResponse={setResponse}
                                    criteria={criteria}
                                    errors={errors}
                                    averageRating={averageRating}
                                />

                                <div className="mt-6 flex gap-2">
                                    <Button
                                        type="submit"
                                        disabled={processing}
                                    >
                                        Save Draft
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
                                        <TableHead>Student</TableHead>
                                        <TableHead>Company</TableHead>
                                        <TableHead>Supervisor</TableHead>
                                        <TableHead>Period</TableHead>
                                        <TableHead>Overall Rating</TableHead>
                                        <TableHead>Status</TableHead>
                                        <TableHead>Actions</TableHead>
                                    </TableRow>
                                </TableHeader>
                                <TableBody>
                                    {evaluations.length ? (
                                        evaluations.map((evaluation) => (
                                            <TableRow key={evaluation.id}>
                                                <TableCell>
                                                    {evaluation.student?.user
                                                        ?.name || "-"}
                                                </TableCell>
                                                <TableCell>
                                                    {evaluation.company
                                                        ?.company_name || "-"}
                                                </TableCell>
                                                <TableCell>
                                                    {evaluation.supervisor
                                                        ?.name || "-"}
                                                </TableCell>
                                                <TableCell>
                                                    {formatLongDate(
                                                        evaluation.evaluation_period_start,
                                                    )}{" "}
                                                    —{" "}
                                                    {formatLongDate(
                                                        evaluation.evaluation_period_end,
                                                    )}
                                                </TableCell>
                                                <TableCell>
                                                    {evaluation.overall_rating ??
                                                        "-"}
                                                </TableCell>
                                                <TableCell>
                                                    <StatusBadge
                                                        status={
                                                            evaluation.status
                                                        }
                                                    />
                                                </TableCell>
                                                <TableCell>
                                                    <Link
                                                        href={route(
                                                            "supervisor-evaluations.show",
                                                            evaluation.id,
                                                        )}
                                                    >
                                                        <Button size="sm">
                                                            View
                                                        </Button>
                                                    </Link>
                                                </TableCell>
                                            </TableRow>
                                        ))
                                    ) : (
                                        <TableRow>
                                            <TableCell
                                                colSpan={7}
                                                className="h-24 text-center"
                                            >
                                                No evaluations yet.
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
