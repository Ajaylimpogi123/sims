import { useState, useEffect } from "react";
import { useForm } from "@inertiajs/react";

export default function useEditUser(user) {
    const [open, setOpen] = useState(false);

    const { data, setData, patch, errors, processing, reset, clearErrors } = useForm({
        name: "",
        email: "",
        role_id: "",
        password: "",
        password_confirmation: "",
    });

    useEffect(() => {
        if (!user || !open) return;

        setData({
            name: user.name || "",
            email: user.email || "",
            role_id: user.role_id ? String(user.role_id) : "",
            password: "",
            password_confirmation: "",
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

        patch(route("user-management.update", user.id), {
            onSuccess: () => {
                closeModal();
            },
            preserveScroll: true,
            only: ["users", "flash"],
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
