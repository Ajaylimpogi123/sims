import { useMemo, useState } from "react";
import { router, useForm } from "@inertiajs/react";

export default function useSupervisorRoster(company, availableSupervisors) {
    const [open, setOpen] = useState(false);

    const { data, setData, post, processing, errors, reset } = useForm({
        user_id: "",
    });

    const openModal = () => setOpen(true);
    const closeModal = () => {
        setOpen(false);
        reset();
    };

    const rosterIds = useMemo(
        () => new Set((company?.supervisors || []).map((s) => s.id)),
        [company],
    );

    const selectableSupervisors = useMemo(
        () => availableSupervisors.filter((s) => !rosterIds.has(s.id)),
        [availableSupervisors, rosterIds],
    );

    const handleAttach = (e) => {
        e.preventDefault();

        post(route("company-management.supervisors.attach", company.id), {
            preserveScroll: true,
            onSuccess: () => setData("user_id", ""),
        });
    };

    const handleDetach = (supervisorId) => {
        router.delete(
            route("company-management.supervisors.detach", [
                company.id,
                supervisorId,
            ]),
            { preserveScroll: true },
        );
    };

    return {
        open,
        openModal,
        closeModal,
        data,
        setData,
        errors,
        processing,
        selectableSupervisors,
        handleAttach,
        handleDetach,
    };
}
