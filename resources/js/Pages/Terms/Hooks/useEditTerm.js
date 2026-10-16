import { useEffect } from "react";
import { useForm } from "@inertiajs/react";
import useControllableOpen from "@/hooks/useControllableOpen";

export default function useEditTerm(
    term,
    { open: controlledOpen, onOpenChange } = {},
) {
    const [open, setOpen] = useControllableOpen(controlledOpen, onOpenChange);

    const { data, setData, patch, errors, processing, reset, clearErrors } =
        useForm({
            school_year_start: "",
            semester: "first",
            starts_on: "",
            ends_on: "",
        });

    useEffect(() => {
        if (!term || !open) return;

        setData({
            school_year_start: String(term.school_year_start ?? ""),
            semester: term.semester || "first",
            starts_on: term.starts_on || "",
            ends_on: term.ends_on || "",
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

        patch(route("terms.update", term.id), {
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
