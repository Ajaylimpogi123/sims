import { useForm } from "@inertiajs/react";

const ROUTES = {
    time_in: "attendance.time-in",
    time_out: "attendance.time-out",
    emergency: "attendance.emergency-time-out",
};

/**
 * Form state for a Time In / Time Out / Emergency Time-Out submission
 * carrying a live photo + GPS coordinates. Posted as multipart.
 */
export default function useAttendanceCapture(mode, onSuccess) {
    const {
        data,
        setData,
        post,
        transform,
        errors,
        processing,
        reset,
        clearErrors,
    } = useForm({
        photo: null,
        latitude: "",
        longitude: "",
        accuracy: "",
        note: "",
    });

    const setLocation = (position) => {
        setData((current) => ({
            ...current,
            latitude: position ? position.latitude.toFixed(7) : "",
            longitude: position ? position.longitude.toFixed(7) : "",
            accuracy:
                position && position.accuracy != null
                    ? position.accuracy.toFixed(2)
                    : "",
        }));
    };

    const setPhoto = (file) => setData("photo", file);

    const handleSubmit = (e) => {
        e?.preventDefault();

        // `note` is only part of the emergency contract.
        transform((formData) => {
            if (mode === "emergency") return formData;
            const { note, ...rest } = formData;
            return rest;
        });

        post(route(ROUTES[mode]), {
            forceFormData: true,
            preserveScroll: true,
            onSuccess: () => {
                reset();
                clearErrors();
                onSuccess?.();
            },
        });
    };

    return {
        data,
        setData,
        setPhoto,
        setLocation,
        errors,
        processing,
        handleSubmit,
    };
}
