import { useState, useEffect } from "react";
import { useForm } from "@inertiajs/react";

export default function useEditCompany(company) {
    const [open, setOpen] = useState(false);

    const { data, setData, patch, errors, processing, reset } = useForm({
        company_name: "",
        address: "",
        contact_person: "",
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
            contact_person: company.contact_person || "",
            contact_number: company.contact_number || "",
            email: company.email || "",
            industry: company.industry || "",
            slots: company.slots || 0,
        });
    }, [company, open]);

    const openModal = () => setOpen(true);
    const closeModal = () => {
        setOpen(false);
        reset();
    };

    const handleSubmit = (e) => {
        e.preventDefault();

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
