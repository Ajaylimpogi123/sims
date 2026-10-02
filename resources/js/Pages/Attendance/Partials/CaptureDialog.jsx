import { useEffect, useState } from "react";
import {
    AlertTriangle,
    Camera,
    Loader2,
    MapPin,
    RefreshCw,
} from "lucide-react";
import { Button } from "@/components/ui/button";
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from "@/components/ui/dialog";
import { Label } from "@/components/ui/label";
import InputError from "@/Components/InputError";
import useCamera, { INSECURE_CONTEXT_MESSAGE } from "../Hooks/useCamera";
import useGeolocation from "../Hooks/useGeolocation";
import useAttendanceCapture from "../Hooks/useAttendanceCapture";

const MODES = {
    time_in: {
        title: "Time In",
        description:
            "Take a photo of yourself and share your current location to record your time in.",
        submitLabel: "Submit Time In",
    },
    time_out: {
        title: "Time Out",
        description:
            "Take a photo of yourself and share your current location to record your time out.",
        submitLabel: "Submit Time Out",
    },
    emergency: {
        title: "Emergency Time Out",
        description:
            "Explain the emergency, take a photo of yourself, and share your current location. Your supervisor will review it.",
        submitLabel: "Submit Emergency Time Out",
    },
};

function Notice({ tone = "error", children }) {
    const styles =
        tone === "error"
            ? "border-red-200 bg-red-50 text-red-700"
            : "border-amber-200 bg-amber-50 text-amber-800";

    return (
        <div
            className={`flex items-start gap-2 rounded-md border px-3 py-2 text-sm ${styles}`}
        >
            <AlertTriangle className="mt-0.5 h-4 w-4 shrink-0" />
            <span>{children}</span>
        </div>
    );
}

/**
 * Live photo + GPS capture for Time In / Time Out / Emergency Time Out.
 * Mount it only while it should be open — the camera starts on mount and
 * every track is stopped on unmount.
 */
export default function CaptureDialog({ mode, onClose }) {
    const config = MODES[mode];
    const insecure =
        typeof window !== "undefined" && window.isSecureContext === false;

    const camera = useCamera();
    const geo = useGeolocation();
    const {
        data,
        setData,
        setPhoto,
        setLocation,
        errors,
        processing,
        handleSubmit,
    } = useAttendanceCapture(mode, onClose);

    const [captureError, setCaptureError] = useState(null);
    const [previewUrl, setPreviewUrl] = useState(null);

    // Start the camera and location request when the dialog mounts; the
    // hooks stop the stream / ignore late results on unmount.
    useEffect(() => {
        camera.start();
        geo.request();
        return () => camera.stop();
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, []);

    useEffect(() => {
        setLocation(geo.status === "success" ? geo.position : null);
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [geo.status, geo.position]);

    useEffect(() => {
        if (!data.photo) {
            setPreviewUrl(null);
            return;
        }
        const url = URL.createObjectURL(data.photo);
        setPreviewUrl(url);
        return () => URL.revokeObjectURL(url);
    }, [data.photo]);

    const handleTakePhoto = async () => {
        setCaptureError(null);
        try {
            setPhoto(await camera.capture());
            // Release the webcam while the preview is shown; Retake restarts it.
            camera.stop();
        } catch (err) {
            setCaptureError(err.message);
        }
    };

    const handleRetake = () => {
        setCaptureError(null);
        setPhoto(null);
        if (camera.status !== "live") camera.start();
    };

    const handleOpenChange = (open) => {
        if (!open && !processing) onClose();
    };

    const hasLocation = data.latitude !== "" && data.longitude !== "";
    const canSubmit =
        !!data.photo &&
        hasLocation &&
        (mode !== "emergency" || data.note.trim() !== "") &&
        !processing;

    const lat = geo.position?.latitude;
    const lng = geo.position?.longitude;
    const accuracy = geo.position?.accuracy;

    return (
        <Dialog open onOpenChange={handleOpenChange}>
            <DialogContent className="sm:max-w-lg">
                <form onSubmit={handleSubmit} className="grid gap-4">
                    <DialogHeader>
                        <DialogTitle>{config.title}</DialogTitle>
                        <DialogDescription>{config.description}</DialogDescription>
                    </DialogHeader>

                    <div className="grid max-h-[65vh] gap-5 overflow-y-auto py-2 pr-1">
                        {insecure && <Notice>{INSECURE_CONTEXT_MESSAGE}</Notice>}

                        {mode === "emergency" && (
                            <div className="grid gap-2">
                                <Label htmlFor="emergency-note">
                                    What happened?
                                </Label>
                                <textarea
                                    id="emergency-note"
                                    placeholder="Explain the emergency..."
                                    value={data.note}
                                    onChange={(e) =>
                                        setData("note", e.target.value)
                                    }
                                    maxLength={1000}
                                    rows={3}
                                    className="flex w-full rounded-md border border-input bg-transparent px-3 py-2 text-sm shadow-sm placeholder:text-muted-foreground focus-visible:outline-none focus-visible:ring-1 focus-visible:ring-ring disabled:cursor-not-allowed disabled:opacity-50"
                                />
                                <InputError message={errors.note} />
                            </div>
                        )}

                        {/* Photo */}
                        <div className="grid gap-2">
                            <Label>Photo</Label>
                            <div className="relative flex aspect-video w-full items-center justify-center overflow-hidden rounded-md border bg-black">
                                <video
                                    ref={camera.videoRef}
                                    autoPlay
                                    playsInline
                                    muted
                                    className={
                                        previewUrl ||
                                        camera.status === "error"
                                            ? "hidden"
                                            : "h-full w-full object-contain"
                                    }
                                />
                                {previewUrl && (
                                    <img
                                        src={previewUrl}
                                        alt="Captured photo"
                                        className="h-full w-full object-contain"
                                    />
                                )}
                                {!previewUrl &&
                                    (camera.status === "starting" ||
                                        camera.status === "idle") && (
                                        <span className="absolute inset-0 flex items-center justify-center gap-2 text-sm text-white/80">
                                            <Loader2 className="h-4 w-4 animate-spin" />
                                            Starting camera… (allow camera
                                            access if asked)
                                        </span>
                                    )}
                                {!previewUrl && camera.status === "error" && (
                                    <span className="px-4 text-center text-sm text-white/80">
                                        Camera unavailable
                                    </span>
                                )}
                            </div>

                            {camera.status === "error" && !insecure && (
                                <Notice>{camera.error}</Notice>
                            )}
                            {captureError && <Notice>{captureError}</Notice>}

                            <div className="flex flex-wrap gap-2">
                                {previewUrl ? (
                                    <Button
                                        type="button"
                                        variant="outline"
                                        size="sm"
                                        onClick={handleRetake}
                                        disabled={processing}
                                    >
                                        <RefreshCw className="h-4 w-4" />
                                        Retake
                                    </Button>
                                ) : camera.status === "error" ? (
                                    !insecure && (
                                        <Button
                                            type="button"
                                            variant="outline"
                                            size="sm"
                                            onClick={camera.start}
                                        >
                                            <RefreshCw className="h-4 w-4" />
                                            Try again
                                        </Button>
                                    )
                                ) : (
                                    <Button
                                        type="button"
                                        size="sm"
                                        onClick={handleTakePhoto}
                                        disabled={camera.status !== "live"}
                                    >
                                        <Camera className="h-4 w-4" />
                                        Take photo
                                    </Button>
                                )}
                            </div>
                            <InputError message={errors.photo} />
                        </div>

                        {/* Location */}
                        <div className="grid gap-2">
                            <Label>Location</Label>
                            <div className="flex items-start gap-2 rounded-md border px-3 py-2 text-sm">
                                <MapPin className="mt-0.5 h-4 w-4 shrink-0 text-muted-foreground" />
                                <div className="min-w-0 flex-1">
                                    {geo.status === "success" && (
                                        <>
                                            <p className="font-medium">
                                                {lat.toFixed(6)},{" "}
                                                {lng.toFixed(6)}
                                            </p>
                                            <p className="text-muted-foreground">
                                                {accuracy != null
                                                    ? `Accurate to ±${Math.round(accuracy)} m`
                                                    : "Accuracy unknown"}
                                                {" · "}
                                                <a
                                                    href={`https://www.google.com/maps?q=${lat},${lng}`}
                                                    target="_blank"
                                                    rel="noopener noreferrer"
                                                    className="underline underline-offset-2"
                                                >
                                                    View map
                                                </a>
                                            </p>
                                        </>
                                    )}
                                    {(geo.status === "locating" ||
                                        geo.status === "idle") && (
                                        <p className="flex items-center gap-2 text-muted-foreground">
                                            <Loader2 className="h-4 w-4 animate-spin" />
                                            Getting your location… (allow
                                            location access if asked)
                                        </p>
                                    )}
                                    {geo.status === "error" && (
                                        <p className="text-muted-foreground">
                                            Location unavailable
                                        </p>
                                    )}
                                </div>
                                {(geo.status === "success" ||
                                    (geo.status === "error" && !insecure)) && (
                                    <Button
                                        type="button"
                                        variant="outline"
                                        size="sm"
                                        onClick={geo.request}
                                        disabled={processing}
                                    >
                                        <RefreshCw className="h-4 w-4" />
                                        {geo.status === "error"
                                            ? "Try again"
                                            : "Refresh"}
                                    </Button>
                                )}
                            </div>
                            {geo.status === "error" && !insecure && (
                                <Notice>{geo.error}</Notice>
                            )}
                            <InputError message={errors.latitude} />
                            <InputError message={errors.longitude} />
                            <InputError message={errors.accuracy} />
                        </div>
                    </div>

                    <DialogFooter className="gap-2 sm:gap-0">
                        <Button
                            type="button"
                            variant="outline"
                            onClick={onClose}
                            disabled={processing}
                        >
                            Cancel
                        </Button>
                        <Button
                            type="submit"
                            disabled={!canSubmit}
                            variant={
                                mode === "emergency" ? "destructive" : "default"
                            }
                        >
                            {processing && (
                                <Loader2 className="h-4 w-4 animate-spin" />
                            )}
                            {config.submitLabel}
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}
