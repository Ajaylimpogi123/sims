import { useState } from "react";
import { useForm } from "@inertiajs/react";

export default function useAddCompany() {
    const [open, setOpen] = useState(false);

    const { data, setData, post, errors, processing, reset } = useForm({
        company_name: "",
        address: "",
        contact_person: "",
        contact_number: "",
        email: "",
        industry: "",
        slots: 0,
    });

    const openModal = () => setOpen(true);
    const closeModal = () => setOpen(false);

    const handleSubmit = (e) => {
        e.preventDefault();

        post(route("company-management.store"), {
            onSuccess: () => {
                closeModal();
                reset();
            },
            preserveScroll: true,
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
