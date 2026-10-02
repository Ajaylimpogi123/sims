import { useCallback, useEffect, useRef, useState } from "react";
import { INSECURE_CONTEXT_MESSAGE } from "./useCamera";

const TIMEOUT_MS = 15000;

function describeGeolocationError(error) {
    switch (error?.code) {
        case 1: // PERMISSION_DENIED
            return "Location access was blocked. Allow location for this site (click the lock icon in the address bar, or open your browser's site settings), then press Try again. On a phone, also make sure Location is turned on.";
        case 2: // POSITION_UNAVAILABLE
            return "Your location couldn't be determined. Make sure Location/GPS is turned on, then press Try again.";
        case 3: // TIMEOUT
            return "Getting your location took too long. Move near a window or outdoors, then press Try again.";
        default:
            return "Your location couldn't be determined. Press Try again.";
    }
}

/**
 * One-shot high-accuracy GPS reading. Call request() to (re)acquire.
 * Results arriving after reset() or unmount are ignored.
 */
export default function useGeolocation() {
    const [status, setStatus] = useState("idle"); // idle | locating | success | error
    const [position, setPosition] = useState(null); // { latitude, longitude, accuracy }
    const [error, setError] = useState(null);

    const requestIdRef = useRef(0);

    const reset = useCallback(() => {
        requestIdRef.current += 1;
        setStatus("idle");
        setPosition(null);
        setError(null);
    }, []);

    const request = useCallback(() => {
        const requestId = ++requestIdRef.current;
        setError(null);
        setPosition(null);

        if (typeof window !== "undefined" && !window.isSecureContext) {
            setStatus("error");
            setError(INSECURE_CONTEXT_MESSAGE);
            return;
        }

        if (!navigator.geolocation) {
            setStatus("error");
            setError(
                "This browser doesn't support location. Use an up-to-date Chrome, Edge, Firefox, or Safari.",
            );
            return;
        }

        setStatus("locating");

        navigator.geolocation.getCurrentPosition(
            (pos) => {
                if (requestId !== requestIdRef.current) return;
                setPosition({
                    latitude: pos.coords.latitude,
                    longitude: pos.coords.longitude,
                    accuracy: Number.isFinite(pos.coords.accuracy)
                        ? pos.coords.accuracy
                        : null,
                });
                setStatus("success");
            },
            (err) => {
                if (requestId !== requestIdRef.current) return;
                setStatus("error");
                setError(describeGeolocationError(err));
            },
            {
                enableHighAccuracy: true,
                timeout: TIMEOUT_MS,
                maximumAge: 0,
            },
        );
    }, []);

    useEffect(() => {
        return () => {
            requestIdRef.current += 1;
        };
    }, []);

    return { status, position, error, request, reset };
}
