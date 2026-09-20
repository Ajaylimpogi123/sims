import { format } from "date-fns";

/**
 * Parse API / form values into a local Date without timezone drift on YYYY-MM-DD.
 */
export function toDate(value) {
    if (value == null || value === "") {
        return null;
    }

    if (value instanceof Date) {
        return Number.isNaN(value.getTime()) ? null : value;
    }

    const str = String(value).trim();
    const dateOnlyMatch = str.match(/^(\d{4})-(\d{2})-(\d{2})$/);

    if (dateOnlyMatch) {
        const [, year, month, day] = dateOnlyMatch;
        return new Date(Number(year), Number(month) - 1, Number(day));
    }

    const parsed = new Date(str);
    return Number.isNaN(parsed.getTime()) ? null : parsed;
}

/**
 * Parse a bare time-only value (e.g. "13:20:07" or "13:20", as returned by
 * time_in/time_out columns) into a Date anchored on an arbitrary day, purely
 * so it can be formatted. Returns null for anything that isn't a valid
 * HH:mm[:ss] string — callers fall back to their own `empty` placeholder.
 */
function toTime(value) {
    if (value == null || value === "") {
        return null;
    }

    const match = String(value)
        .trim()
        .match(/^(\d{1,2}):(\d{2})(?::(\d{2}))?$/);

    if (!match) {
        return null;
    }

    const [, hours, minutes, seconds] = match;
    const date = new Date();
    date.setHours(Number(hours), Number(minutes), Number(seconds || 0), 0);

    return date;
}

/** Display format: 1:20 PM (converts a stored 24-hour HH:mm[:ss] value) */
export function formatTime(value, empty = "—") {
    const date = toTime(value);
    if (!date) {
        return empty;
    }

    return format(date, "h:mm a");
}

/** Display format: dd/MM/yyyy */
export function formatDate(value, empty = "—") {
    const date = toDate(value);
    if (!date) {
        return empty;
    }

    return format(date, "dd/MM/yyyy");
}

/** Display format: dd/MM/yyyy, h:mm a */
export function formatDateTime(value, empty = "—") {
    const date = toDate(value);
    if (!date) {
        return empty;
    }

    return format(date, "dd/MM/yyyy, h:mm a");
}

/** Display format: September 19, 2026 */
export function formatLongDate(value, empty = "—") {
    const date = toDate(value);
    if (!date) {
        return empty;
    }

    return format(date, "MMMM d, yyyy");
}

/** Display format: Saturday, September 19, 2026 */
export function formatLongDateWithWeekday(value, empty = "—") {
    const date = toDate(value);
    if (!date) {
        return empty;
    }

    return format(date, "EEEE, MMMM d, yyyy");
}
