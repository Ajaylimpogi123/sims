import { useState } from "react";
import { useForm } from "@inertiajs/react";
import {
    initResponses,
    toResponsesArray,
    computeAverageRating,
} from "../lib/responses";

export default function useCreateEvaluation(criteria) {
    const [responses, setResponses] = useState(() => initResponses(criteria));

    const { data, setData, post, errors, processing, reset, transform } =
        useForm({
            student_id: "",
            evaluation_period_start: "",
            evaluation_period_end: "",
            strengths: "",
            areas_for_improvement: "",
            recommendations: "",
            supervisor_remarks: "",
        });

    const setResponse = (criteriaId, value) => {
        setResponses((prev) => ({ ...prev, [criteriaId]: value }));
    };

    const averageRating = computeAverageRating(responses);

    const submit = (e, { onSuccess } = {}) => {
        e.preventDefault();

        transform((formData) => ({
            ...formData,
            responses: toResponsesArray(responses),
        }));

        post(route("supervisor-evaluations.store"), {
            preserveScroll: true,
            onSuccess: () => {
                reset();
                setResponses(initResponses(criteria));
                onSuccess?.();
            },
        });
    };

    return {
        data,
        setData,
        responses,
        setResponse,
        averageRating,
        errors,
        processing,
        submit,
    };
}
