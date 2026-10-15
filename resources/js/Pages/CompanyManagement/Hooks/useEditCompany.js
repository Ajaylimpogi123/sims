import { useEffect } from "react";
import { useForm } from "@inertiajs/react";
import useControllableOpen from "@/hooks/useControllableOpen";

export default function useEditCompany(
    company,
    { open: controlledOpen, onOpenChange } = {},
) {
    const [open, setOpen] = useControllableOpen(controlledOpen, onOpenChange);

    const { data, setData, patch, errors, processing, reset, clearErrors } = useForm({
        company_name: "",
        address: "",
        supervisor_id: "",
        contact_number: "",
        email: "",
        industry: "",
        slots: 0,
    });

    useEffect(() => {
        if (!company || !open) return;

        setData({
            company_name: company.company_name || "",
            address: company.address || "",
            supervisor_id:
                company.supervisor_id != null
                    ? String(company.supervisor_id)
                    : "",
            contact_number: company.contact_number || "",
            email: company.email || "",
            industry: company.industry || "",
            slots: company.slots || 0,
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

    const handleSubmit = (e, { confirmMessage } = {}) => {
        e.preventDefault();

        // Changing the company's supervisor moves all its students too.
        if (confirmMessage && !window.confirm(confirmMessage)) return;

        patch(route("company-management.update", company.id), {
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
