import AuthenticatedLayout from "@/Layouts/AuthenticatedLayout";
import { Head } from "@inertiajs/react";
import { formatLongDate } from "@/lib/dates";
import CriteriaRatingGroup from "./Partials/CriteriaRatingGroup";
import { initResponses } from "./lib/responses";

function ReadOnlyText({ label, value }) {
    if (!value) return null;

    return (
        <div className="grid gap-1">
            <span className="text-xs font-medium text-muted-foreground">
                {label}
            </span>
            <p className="rounded-md border bg-muted/20 p-3 text-sm whitespace-pre-wrap">
                {value}
            </p>
        </div>
    );
}

function EvaluationCard({ evaluation }) {
    const criteria = (evaluation.responses || [])
        .map((response) => response.criteria)
        .filter(Boolean);
    const responses = initResponses(criteria, evaluation.responses || []);

    return (
        <div className="rounded-sm border bg-card p-6 text-card-foreground shadow">
            <div className="mb-4 flex flex-col gap-1 sm:flex-row sm:items-center sm:justify-between">
                <div>
                    <h2 className="text-lg font-semibold">
                        {formatLongDate(evaluation.evaluation_period_start)}{" "}
                        — {formatLongDate(evaluation.evaluation_period_end)}
                    </h2>
                    <p className="text-sm text-muted-foreground">
                        {evaluation.company?.company_name || "—"} ·
                        Supervisor: {evaluation.supervisor?.name || "—"}
                    </p>
                </div>
                <div className="rounded-md border bg-muted/20 px-3 py-1 text-sm">
                    Overall Rating:{" "}
                    <span className="font-semibold">
                        {evaluation.overall_rating ?? "—"}
                    </span>
                    /5
                </div>
            </div>

            <div className="grid gap-6">
                <CriteriaRatingGroup
                    criteria={criteria}
                    responses={responses}
                    readOnly
                />

                <div className="grid gap-4 sm:grid-cols-2">
                    <ReadOnlyText
                        label="Strengths"
                        value={evaluation.strengths}
                    />
                    <ReadOnlyText
                        label="Areas for Improvement"
                        value={evaluation.areas_for_improvement}
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
            </div>
        </div>
    );
}

export default function MyFeedback({ evaluations }) {
    return (
        <AuthenticatedLayout>
            <Head title="My Feedback" />

            <div className="relative z-10 py-8">
                <div className="flex-1 space-y-6 p-4 md:p-6">
                    <div className="mb-8">
                        <h1 className="text-3xl font-bold tracking-tight text-white">
                            My Feedback
                        </h1>
                        <p className="mt-2 text-sm text-white">
                            Evaluations your supervisor has submitted about
                            your internship performance
                        </p>
                    </div>

                    {evaluations.length ? (
                        <div className="space-y-6">
                            {evaluations.map((evaluation) => (
                                <EvaluationCard
                                    key={evaluation.id}
                                    evaluation={evaluation}
                                />
                            ))}
                        </div>
                    ) : (
                        <div className="rounded-sm border bg-card p-6 text-center text-card-foreground shadow">
                            No feedback has been submitted yet.
                        </div>
                    )}
                </div>
            </div>
        </AuthenticatedLayout>
    );
}
