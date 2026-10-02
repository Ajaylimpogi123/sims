import { useCallback, useEffect, useRef, useState } from "react";

const MAX_DIMENSION = 1280;
const JPEG_QUALITY = 0.8;

export const INSECURE_CONTEXT_MESSAGE =
    "Camera and location require a secure (HTTPS) connection. Open this site over HTTPS to time in or out.";

function describeCameraError(error) {
    switch (error?.name) {
        case "NotAllowedError":
        case "SecurityError":
        case "PermissionDeniedError":
            return "Camera access was blocked. Allow camera access for this site (click the camera or lock icon in the address bar, or open your browser's site settings), then press Try again.";
        case "NotFoundError":
        case "DevicesNotFoundError":
        case "OverconstrainedError":
            return "No camera was found on this device. Connect a camera, or use a device that has one.";
        case "NotReadableError":
        case "TrackStartError":
        case "AbortError":
            return "The camera is being used by another app or tab, or couldn't be started. Close other apps using the camera, then press Try again.";
        default:
            return "The camera couldn't be started. Press Try again.";
    }
}

/**
 * Live camera stream for taking a photo in-app (no file picker).
 *
 * Call start() to request the camera and stop() to release it; tracks are
 * also stopped automatically on unmount. Attach `videoRef` to a <video>
 * element — the stream is attached whenever the element mounts.
 */
export default function useCamera() {
    const [status, setStatus] = useState("idle"); // idle | starting | live | error
    const [error, setError] = useState(null);

    const streamRef = useRef(null);
    const videoElRef = useRef(null);
    // Incremented on every start/stop so a getUserMedia promise that
    // resolves after the dialog was closed (or restarted) is discarded and
    // its tracks stopped instead of leaking an open camera.
    const requestIdRef = useRef(0);

    const attachStream = (video, stream) => {
        if (!video) return;
        if (video.srcObject !== stream) {
            video.srcObject = stream;
        }
        if (stream) {
            video.play?.().catch(() => {});
        }
    };

    const videoRef = useCallback((node) => {
        videoElRef.current = node;
        if (node && streamRef.current) {
            attachStream(node, streamRef.current);
        }
    }, []);

    const releaseStream = () => {
        if (streamRef.current) {
            streamRef.current.getTracks().forEach((track) => track.stop());
            streamRef.current = null;
        }
        if (videoElRef.current) {
            videoElRef.current.srcObject = null;
        }
    };

    const stop = useCallback(() => {
        requestIdRef.current += 1;
        releaseStream();
        setStatus("idle");
        setError(null);
    }, []);

    const start = useCallback(async () => {
        const requestId = ++requestIdRef.current;
        releaseStream();
        setError(null);

        if (typeof window !== "undefined" && !window.isSecureContext) {
            setStatus("error");
            setError(INSECURE_CONTEXT_MESSAGE);
            return;
        }

        if (!navigator.mediaDevices?.getUserMedia) {
            setStatus("error");
            setError(
                "This browser doesn't support taking photos. Use an up-to-date Chrome, Edge, Firefox, or Safari.",
            );
            return;
        }

        setStatus("starting");

        try {
            const stream = await navigator.mediaDevices.getUserMedia({
                video: { facingMode: "user" },
                audio: false,
            });

            if (requestId !== requestIdRef.current) {
                stream.getTracks().forEach((track) => track.stop());
                return;
            }

            streamRef.current = stream;
            attachStream(videoElRef.current, stream);
            setStatus("live");
        } catch (err) {
            if (requestId !== requestIdRef.current) return;
            setStatus("error");
            setError(describeCameraError(err));
        }
    }, []);

    /**
     * Grab the current frame, downscale to MAX_DIMENSION on the long edge,
     * and encode as JPEG. Resolves to a File, or rejects if the stream
     * isn't producing frames yet.
     */
    const capture = useCallback(() => {
        return new Promise((resolve, reject) => {
            const video = videoElRef.current;
            const width = video?.videoWidth;
            const height = video?.videoHeight;

            if (!video || !width || !height) {
                reject(new Error("The camera isn't ready yet."));
                return;
            }

            const scale = Math.min(1, MAX_DIMENSION / Math.max(width, height));
            const canvas = document.createElement("canvas");
            canvas.width = Math.round(width * scale);
            canvas.height = Math.round(height * scale);

            const context = canvas.getContext("2d");
            context.drawImage(video, 0, 0, canvas.width, canvas.height);

            canvas.toBlob(
                (blob) => {
                    if (!blob) {
                        reject(new Error("The photo couldn't be captured."));
                        return;
                    }
                    resolve(
                        new File([blob], `attendance-${Date.now()}.jpg`, {
                            type: "image/jpeg",
                        }),
                    );
                },
                "image/jpeg",
                JPEG_QUALITY,
            );
        });
    }, []);

    useEffect(() => {
        return () => {
            requestIdRef.current += 1;
            releaseStream();
        };
    }, []);

    return { status, error, videoRef, start, stop, capture };
}
