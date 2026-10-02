import { useForm } from "@inertiajs/react";

export default function useEditAttendanceEntry(attendance, onSuccess) {
    const { data, setData, patch, errors, processing } = useForm({
        date: attendance.date || "",
        time_in: attendance.time_in || "",
        time_out: attendance.time_out || "",
    });

    const handleSubmit = (e) => {
        e.preventDefault();

        patch(
            route("attendance-monitoring.attendances.update", attendance.id),
            {
                preserveScroll: true,
                onSuccess: () => onSuccess?.(),
            },
        );
    };

    return { data, setData, errors, processing, handleSubmit };
}
