import { useEffect } from "react";
import { useForm } from "@inertiajs/react";
import useControllableOpen from "@/hooks/useControllableOpen";

export default function useManageStudent(
    student,
    { needsSupervisorOnly = false, open: controlledOpen, onOpenChange } = {},
) {
    const [open, setOpen] = useControllableOpen(controlledOpen, onOpenChange);

    const { data, setData, patch, transform, errors, processing, reset, clearErrors } =
        useForm({
            name: "",
            email: "",
            student_number: "",
            course: "",
            section: "",
            company_id: "",
            internship_status: "not_started",
            internship_schedule: "",
            required_hours: "",
            term_id: "",
        });

    useEffect(() => {
        if (!student || !open) return;

        setData({
            name: student.name || "",
            email: student.email || "",
            student_number: student.student_number || "",
            course: student.course || "",
            section: student.section || "",
            company_id: student.company_id ? String(student.company_id) : "",
            internship_status: student.internship_status || "not_started",
            internship_schedule: student.internship_schedule || "",
            // 0 means "not set" (as on the server), so it seeds as empty.
            required_hours:
                Number(student.required_hours) > 0
                    ? String(student.required_hours)
                    : "",
            // Always sent; an unchanged term is a no-op on the server.
            term_id: student.term_id != null ? String(student.term_id) : "",
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

        // Not a form field: tells the server to redirect back to the
        // "Needs supervisor" filter (?needs_supervisor=1) after saving.
        transform(({ term_id, ...formData }) => ({
            ...formData,
            // Never send an empty term (a student row always has one).
            ...(term_id ? { term_id } : {}),
            ...(needsSupervisorOnly ? { needs_supervisor: 1 } : {}),
        }));

        patch(route("internship-assignment.update", student.id), {
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
