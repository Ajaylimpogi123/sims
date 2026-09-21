import { useState } from "react";
import { useForm, router } from "@inertiajs/react";
import {
    initResponses,
    toResponsesArray,
    computeAverageRating,
} from "../lib/responses";

export default function useEditEvaluation(evaluation, criteria) {
    const [responses, setResponses] = useState(() =>
        initResponses(criteria, evaluation.responses || []),
    );

    const { data, setData, patch, errors, processing, transform } = useForm({
        evaluation_period_start: evaluation.evaluation_period_start || "",
        evaluation_period_end: evaluation.evaluation_period_end || "",
        strengths: evaluation.strengths || "",
        areas_for_improvement: evaluation.areas_for_improvement || "",
        recommendations: evaluation.recommendations || "",
        supervisor_remarks: evaluation.supervisor_remarks || "",
    });

    const setResponse = (criteriaId, value) => {
        setResponses((prev) => ({ ...prev, [criteriaId]: value }));
    };

    const averageRating = computeAverageRating(responses);

    const saveDraft = (e) => {
        e.preventDefault();

        transform((formData) => ({
            ...formData,
            responses: toResponsesArray(responses),
        }));

        patch(route("supervisor-evaluations.update", evaluation.id), {
            preserveScroll: true,
        });
    };

    const submitEvaluation = () => {
        if (
            !confirm(
                "Submit this evaluation? Once submitted it can no longer be edited unless an administrator reopens it.",
            )
        ) {
            return;
        }

        router.patch(
            route("supervisor-evaluations.submit", evaluation.id),
            {},
            { preserveScroll: true },
        );
    };

    const lockEvaluation = () => {
        router.patch(
            route("supervisor-evaluations.lock", evaluation.id),
            {},
            { preserveScroll: true },
        );
    };

    const reopenEvaluation = () => {
        router.patch(
            route("supervisor-evaluations.reopen", evaluation.id),
            {},
            { preserveScroll: true },
        );
    };

    return {
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
    };
}
