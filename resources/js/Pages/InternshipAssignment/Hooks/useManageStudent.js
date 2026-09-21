import { useState, useEffect } from "react";
import { useForm } from "@inertiajs/react";

export default function useManageStudent(student) {
    const [open, setOpen] = useState(false);

    const { data, setData, patch, errors, processing, reset } = useForm({
        name: "",
        email: "",
        student_number: "",
        course: "",
        section: "",
        company_id: "",
        supervisor_id: "",
        internship_status: "not_started",
        internship_schedule: "",
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
            supervisor_id: student.supervisor_id
                ? String(student.supervisor_id)
                : "",
            internship_status: student.internship_status || "not_started",
            internship_schedule: student.internship_schedule || "",
        });
    }, [student, open]);

    const openModal = () => setOpen(true);
    const closeModal = () => {
        setOpen(false);
        reset();
    };

    const handleSubmit = (e) => {
        e.preventDefault();

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
