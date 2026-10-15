import { useState } from "react";
import { useForm } from "@inertiajs/react";

export default function useReviewReport(report) {
    const [open, setOpen] = useState(false);

    const { data, setData, patch, errors, processing, reset, clearErrors } = useForm({
        comment: "",
    });

    const openModal = () => setOpen(true);
    const closeModal = () => {
        setOpen(false);
        reset();
        clearErrors();
    };

    const handleSubmit = (e) => {
        e.preventDefault();

        patch(route("report-reviews.review", report.id), {
            preserveScroll: true,
            onSuccess: () => closeModal(),
        });
    };

    return {
        open,
        openModal,
        closeModal,
        data,
        setData,
        errors,
        processing,
        handleSubmit,
    };
}
