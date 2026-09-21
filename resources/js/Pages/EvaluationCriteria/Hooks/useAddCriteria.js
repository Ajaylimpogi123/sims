import { useState } from "react";
import { useForm } from "@inertiajs/react";

export default function useAddCriteria() {
    const [open, setOpen] = useState(false);

    const { data, setData, post, errors, processing, reset } = useForm({
        label: "",
        description: "",
        category: "",
        sort_order: 0,
    });

    const openModal = () => setOpen(true);
    const closeModal = () => setOpen(false);

    const handleSubmit = (e) => {
        e.preventDefault();

        post(route("evaluation-criteria.store"), {
            preserveScroll: true,
            onSuccess: () => {
                closeModal();
                reset();
            },
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
