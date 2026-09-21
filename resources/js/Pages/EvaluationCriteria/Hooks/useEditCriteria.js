import { useState, useEffect } from "react";
import { useForm } from "@inertiajs/react";

export default function useEditCriteria(criterion) {
    const [open, setOpen] = useState(false);

    const { data, setData, patch, errors, processing, reset } = useForm({
        label: "",
        description: "",
        category: "",
        sort_order: 0,
    });

    useEffect(() => {
        if (!criterion || !open) return;

        setData({
            label: criterion.label || "",
            description: criterion.description || "",
            category: criterion.category || "",
            sort_order: criterion.sort_order ?? 0,
        });
    }, [criterion, open]);

    const openModal = () => setOpen(true);
    const closeModal = () => {
        setOpen(false);
        reset();
    };

    const handleSubmit = (e) => {
        e.preventDefault();

        patch(route("evaluation-criteria.update", criterion.id), {
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
