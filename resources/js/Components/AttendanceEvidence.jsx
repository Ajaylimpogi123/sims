import { useState } from "react";
import { ImageOff, MapPin, TriangleAlert } from "lucide-react";

function toNumber(value) {
    if (value === null || value === undefined || value === "") return null;
    const number = parseFloat(value);
    return Number.isFinite(number) ? number : null;
}

/**
 * Photo thumbnail + map link for one leg ("time_in" | "time_out") of an
 * attendance record. Renders "—" when that leg has no captured evidence
 * (staff-entered rows and records from before capture was required).
 */
export default function AttendanceEvidence({
    attendance,
    leg,
    showMockedWarning = true,
}) {
    const [failedUrl, setFailedUrl] = useState(null);

    if (!attendance) {
        return <span className="text-muted-foreground">—</span>;
    }

    const hasPhoto = !!attendance[`${leg}_photo_path`];
    const latitude = toNumber(attendance[`${leg}_latitude`]);
    const longitude = toNumber(attendance[`${leg}_longitude`]);
    const accuracy = toNumber(attendance[`${leg}_accuracy`]);
    const hasLocation = latitude !== null && longitude !== null;
    // Only an explicit `true` from the phone counts; false/null (including
    // every web submission) shows nothing. Reviewer-facing only; the
    // student's own page opts out via showMockedWarning={false}.
    const isMocked =
        showMockedWarning && attendance[`${leg}_mocked`] === true;

    if (!hasPhoto && !hasLocation && !isMocked) {
        return <span className="text-muted-foreground">—</span>;
    }

    // The photo URL is stable per record + leg, but re-submitting a leg after
    // a rejection replaces the file — bust the browser cache with the
    // record's updated_at so the new photo shows.
    const photoUrl = hasPhoto
        ? route("attendance.photo", {
              attendance: attendance.id,
              leg,
              ...(attendance.updated_at ? { v: attendance.updated_at } : {}),
          })
        : null;
    const photoFailed = photoUrl !== null && failedUrl === photoUrl;
    const legLabel = leg === "time_in" ? "Time-in" : "Time-out";

    return (
        <div className="flex items-center gap-2">
            {photoUrl &&
                (photoFailed ? (
                    <span
                        className="flex h-10 w-10 shrink-0 items-center justify-center rounded border bg-muted text-muted-foreground"
                        title="Photo unavailable"
                    >
                        <ImageOff className="h-4 w-4" />
                    </span>
                ) : (
                    <a
                        href={photoUrl}
                        target="_blank"
                        rel="noopener noreferrer"
                        title="Open full-size photo"
                        className="shrink-0"
                    >
                        <img
                            src={photoUrl}
                            alt={`${legLabel} photo`}
                            loading="lazy"
                            onError={() => setFailedUrl(photoUrl)}
                            className="h-10 w-10 rounded border object-cover"
                        />
                    </a>
                ))}

            {hasLocation ? (
                <div className="flex flex-col text-xs leading-tight">
                    <a
                        href={`https://www.google.com/maps?q=${latitude},${longitude}`}
                        target="_blank"
                        rel="noopener noreferrer"
                        className="inline-flex items-center gap-1 text-primary underline underline-offset-2"
                    >
                        <MapPin className="h-3 w-3" />
                        View map
                    </a>
                    {accuracy !== null && (
                        <span className="text-muted-foreground">
                            ±{Math.round(accuracy)} m
                        </span>
                    )}
                </div>
            ) : (
                <span className="text-xs text-muted-foreground">
                    No location
                </span>
            )}

            {isMocked && <MockedLocationBadge />}
        </div>
    );
}

function MockedLocationBadge() {
    return (
        <span
            className="inline-flex shrink-0 items-center gap-1 rounded-full bg-amber-100 px-2 py-0.5 text-xs font-medium text-amber-700"
            title="The phone reported a mocked location for this entry."
        >
            <TriangleAlert className="h-3 w-3" />
            Possible fake GPS
        </span>
    );
}

/**
 * Stacked In / Out evidence for a whole attendance record, for tables that
 * show one row per day.
 */
export function AttendanceEvidenceInOut({
    attendance,
    showMockedWarning = true,
}) {
    return (
        <div className="space-y-1.5">
            <div className="flex items-center gap-2">
                <span className="w-7 shrink-0 text-xs text-muted-foreground">
                    In
                </span>
                <AttendanceEvidence
                    attendance={attendance}
                    leg="time_in"
                    showMockedWarning={showMockedWarning}
                />
            </div>
            <div className="flex items-center gap-2">
                <span className="w-7 shrink-0 text-xs text-muted-foreground">
                    Out
                </span>
                <AttendanceEvidence
                    attendance={attendance}
                    leg="time_out"
                    showMockedWarning={showMockedWarning}
                />
            </div>
        </div>
    );
}
