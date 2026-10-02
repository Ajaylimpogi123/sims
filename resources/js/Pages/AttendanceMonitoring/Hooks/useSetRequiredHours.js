import { useState, useEffect } from "react";
import { useForm } from "@inertiajs/react";

export default function useSetRequiredHours(student) {
    const [open, setOpen] = useState(false);

    const { data, setData, patch, errors, processing, reset } = useForm({
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
    }, [student, open]);

    const openModal = () => setOpen(true);
    const closeModal = () => {
        setOpen(false);
        reset();
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
