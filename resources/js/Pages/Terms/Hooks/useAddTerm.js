import { useState } from "react";
import { useForm } from "@inertiajs/react";

// A school year starts mid-year: Jan–May still belongs to last year's SY.
export function defaultSchoolYearStart(today = new Date()) {
    const year = today.getFullYear();
    return today.getMonth() < 5 ? year - 1 : year;
}

export default function useAddTerm() {
    const [open, setOpen] = useState(false);

    const { data, setData, post, errors, processing, reset, clearErrors } =
        useForm({
            school_year_start: String(defaultSchoolYearStart()),
            semester: "first",
            starts_on: "",
            ends_on: "",
        });

    const openModal = () => setOpen(true);
    const closeModal = () => {
        setOpen(false);
        reset();
        clearErrors();
    };

    const handleSubmit = (e) => {
        e.preventDefault();

        post(route("terms.store"), {
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
