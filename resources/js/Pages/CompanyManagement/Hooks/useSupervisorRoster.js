import { useMemo } from "react";
import { router, useForm } from "@inertiajs/react";
import useControllableOpen from "@/hooks/useControllableOpen";

export default function useSupervisorRoster(
    company,
    availableSupervisors,
    { open: controlledOpen, onOpenChange } = {},
) {
    const [open, setOpen] = useControllableOpen(controlledOpen, onOpenChange);

    const { data, setData, post, processing, errors, reset, clearErrors } = useForm({
        user_id: "",
    });

    const openModal = () => setOpen(true);
    const closeModal = () => {
        setOpen(false);
        reset();
        clearErrors();
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
