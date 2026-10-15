import { useState, useEffect } from "react";
import { useForm } from "@inertiajs/react";

export default function useEditReport(report) {
    const [open, setOpen] = useState(false);

    const { data, setData, post, errors, processing, reset, clearErrors } = useForm({
        _method: "patch",
        type: "daily",
        period_start: "",
        period_end: "",
        content: "",
        attachment: null,
    });

    useEffect(() => {
        if (!report || !open) return;

        setData({
            _method: "patch",
            type: report.type || "daily",
            period_start: report.period_start || "",
            period_end: report.period_end || "",
            content: report.content || "",
            attachment: null,
        });
        // Seed only when the dialog opens: a validation (422) response re-sends
        // the props as new objects and must not overwrite what was typed.
    }, [open]);

    const openModal = () => setOpen(true);
    const closeModal = () => {
        setOpen(false);
        reset();
        clearErrors();
    };

    const handleSubmit = (e) => {
        e.preventDefault();

        post(route("reports.update", report.id), {
            forceFormData: true,
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
