import { useEffect } from "react";
import { useForm } from "@inertiajs/react";
import useControllableOpen from "@/hooks/useControllableOpen";

export default function useSetRequiredHours(
    student,
    { open: controlledOpen, onOpenChange } = {},
) {
    const [open, setOpen] = useControllableOpen(controlledOpen, onOpenChange);

    const { data, setData, patch, errors, processing, reset, clearErrors } = useForm({
        required_hours: "",
    });

    useEffect(() => {
        if (!student || !open) return;

        setData({
            required_hours:
                student.required_hours != null
                    ? String(student.required_hours)
                    : "",
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

        patch(route("attendance-monitoring.required-hours", student.id), {
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
