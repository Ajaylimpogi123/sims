import { useForm } from "@inertiajs/react";

export default function useAddAttendanceEntry(studentId) {
    const { data, setData, post, errors, processing, reset } = useForm({
        date: "",
        time_in: "",
        time_out: "",
    });

    const handleSubmit = (e) => {
        e.preventDefault();

        post(route("attendance-monitoring.attendances.store", studentId), {
            preserveScroll: true,
            onSuccess: () => reset(),
        });
    };

    return { data, setData, errors, processing, handleSubmit };
}
