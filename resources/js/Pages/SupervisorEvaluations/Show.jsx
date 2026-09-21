import AuthenticatedLayout from "@/Layouts/AuthenticatedLayout";
import { Head, Link, usePage } from "@inertiajs/react";
import { Button } from "@/components/ui/button";
import { formatLongDate } from "@/lib/dates";
import EvaluationFormFields from "./Partials/EvaluationFormFields";
import CriteriaRatingGroup from "./Partials/CriteriaRatingGroup";
import useEditEvaluation from "./Hooks/useEditEvaluation";
import { initResponses } from "./lib/responses";

const ADMIN_ROLE_ID = 4;

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

function ReadOnlyText({ label, value }) {
    return (
        <div className="grid gap-1">
            <span className="text-xs font-medium text-muted-foreground">
                {label}
            </span>
            <p className="rounded-md border bg-muted/20 p-3 text-sm whitespace-pre-wrap">
                {value || "—"}
            </p>
        </div>
    );
}

export default function Show({ evaluation, criteria, canEdit }) {
    const { auth } = usePage().props;
    const isAdmin = auth?.user?.role_id === ADMIN_ROLE_ID;

    const {
        data,
        setData,
        responses,
        setResponse,
        averageRating,
        errors,
        processing,
        saveDraft,
        submitEvaluation,
        lockEvaluation,
        reopenEvaluation,
    } = useEditEvaluation(evaluation, criteria);

    // Read-only view always reflects the evaluation's *own* responses
    // (including any tied to now-deactivated criteria), not just the
    // currently active criteria list used for editing.
    const readOnlyCriteria = (evaluation.responses || [])
        .map((response) => response.criteria)
        .filter(Boolean);
    const readOnlyResponses = initResponses(
        readOnlyCriteria,
        evaluation.responses || [],
    );

    return (
        <AuthenticatedLayout>
            <Head title="Evaluation Detail" />

            <div className="relative z-10 py-8">
                <div className="flex-1 space-y-6 p-4 md:p-6">
                    <div className="mb-4 flex flex-col gap-4 sm:flex-row sm:items-end sm:justify-between">
                        <div>
                            <Link
                                href={route("supervisor-evaluations.index")}
                                className="text-sm text-white underline"
                            >
                                &larr; Back to Supervisor Monitoring & Feedback
                            </Link>
                            <h1 className="mt-2 text-3xl font-bold tracking-tight text-white">
                                {evaluation.student?.user?.name}
                            </h1>
                            <p className="mt-1 text-sm text-white">
                                {formatLongDate(
                                    evaluation.evaluation_period_start,
                                )}{" "}
                                —{" "}
                                {formatLongDate(
                                    evaluation.evaluation_period_end,
                                )}{" "}
                                · {evaluation.company?.company_name || "—"} ·{" "}
                                Supervisor:{" "}
                                {evaluation.supervisor?.name || "—"}
                            </p>
                        </div>
                        <div className="flex items-center gap-3">
                            <StatusBadge status={evaluation.status} />
                        </div>
                    </div>

                    <div className="rounded-sm border bg-card p-6 text-card-foreground shadow">
                        {canEdit ? (
                            <form onSubmit={saveDraft}>
                                <EvaluationFormFields
                                    data={data}
                                    setData={setData}
                                    responses={responses}
                                    setResponse={setResponse}
                                    criteria={criteria}
                                    errors={errors}
                                    averageRating={averageRating}
                                />

                                <div className="mt-6 flex flex-wrap gap-2">
                                    <Button
                                        type="submit"
                                        disabled={processing}
                                    >
                                        Save Draft
                                    </Button>
                                    <Button
                                        type="button"
                                        variant="default"
                                        className="bg-green-600 hover:bg-green-700"
                                        disabled={processing}
                                        onClick={submitEvaluation}
                                    >
                                        Submit Evaluation
                                    </Button>
                                </div>
                            </form>
                        ) : (
                            <div className="grid gap-6">
                                <div className="rounded-md border bg-muted/20 p-3 text-sm">
                                    Overall Rating:{" "}
                                    <span className="font-semibold">
                                        {evaluation.overall_rating ?? "—"}
                                    </span>
                                    /5
                                </div>

                                <CriteriaRatingGroup
                                    criteria={readOnlyCriteria}
                                    responses={readOnlyResponses}
                                    readOnly
                                />

                                <div className="grid gap-4 sm:grid-cols-2">
                                    <ReadOnlyText
                                        label="Strengths"
                                        value={evaluation.strengths}
                                    />
                                    <ReadOnlyText
                                        label="Areas for Improvement"
                                        value={
                                            evaluation.areas_for_improvement
                                        }
                                    />
                                    <ReadOnlyText
                                        label="Recommendations"
                                        value={evaluation.recommendations}
                                    />
                                    <ReadOnlyText
                                        label="Supervisor Remarks"
                                        value={evaluation.supervisor_remarks}
                                    />
                                </div>

                                {evaluation.status === "locked" &&
                                    evaluation.locked_by && (
                                        <p className="text-xs text-muted-foreground">
                                            Locked by{" "}
                                            {evaluation.locked_by?.name} on{" "}
                                            {formatLongDate(
                                                evaluation.locked_at,
                                            )}
                                        </p>
                                    )}

                                {isAdmin && (
                                    <div className="flex flex-wrap gap-2 border-t pt-4">
                                        {evaluation.status ===
                                            "submitted" && (
                                            <Button
                                                variant="outline"
                                                onClick={lockEvaluation}
                                            >
                                                Lock Evaluation
                                            </Button>
                                        )}
                                        {["submitted", "locked"].includes(
                                            evaluation.status,
                                        ) && (
                                            <Button
                                                variant="outline"
                                                onClick={reopenEvaluation}
                                            >
                                                Reopen for Editing
                                            </Button>
                                        )}
                                    </div>
                                )}
                            </div>
                        )}
                    </div>
                </div>
            </div>
        </AuthenticatedLayout>
    );
}
