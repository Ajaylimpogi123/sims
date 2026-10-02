import { useCallback, useEffect, useState } from "react";
import { router, usePage } from "@inertiajs/react";
import { cn } from "@/lib/utils";

const HIGHLIGHT_CLASS =
    "bg-amber-100 outline outline-2 -outline-offset-2 outline-amber-400 hover:bg-amber-100";

function readHighlightParam(pageUrl) {
    try {
        const value = new URL(pageUrl, window.location.origin).searchParams.get(
            "highlight",
        );

        return value && /^\d+$/.test(value) ? value : null;
    } catch {
        return null;
    }
}

function urlWithoutHighlight(pageUrl) {
    const url = new URL(pageUrl, window.location.origin);
    url.searchParams.delete("highlight");

    return url.pathname + url.search + url.hash;
}

/**
 * Reads `?highlight={id}` (set by notification destination URLs), scrolls the
 * matching row into view and gives it a temporary highlight. Rows opt in by
 * spreading `getRowProps(id)`; several rows may share an id (e.g. an
 * attendance's time-in and time-out requests) and all are highlighted.
 *
 * Does nothing when the id isn't rendered on the page. The param is stripped
 * from the URL afterwards so a reload / redirect back doesn't re-flash it.
 */
export default function useHighlightRow({ duration = 3000 } = {}) {
    const { url } = usePage();
    const [highlightId] = useState(() => readHighlightParam(url));
    const [activeId, setActiveId] = useState(highlightId);

    useEffect(() => {
        if (!highlightId) return;

        // Deferred on purpose: (1) Inertia resets scroll after swapping the page
        // in, which would cancel an immediate scroll; (2) on a full page load
        // the adapter runs router.init in the root's effect, which fires after
        // this page's effects, so router.replace must wait until it has run.
        const scrollTimer = setTimeout(() => {
            router.replace({
                url: urlWithoutHighlight(url),
                preserveState: true,
                preserveScroll: true,
            });

            const row = document.querySelector(
                `[data-highlight-id="${highlightId}"]`,
            );

            if (!row) {
                setActiveId(null);
                return;
            }

            row.scrollIntoView({ behavior: "smooth", block: "center" });
        }, 150);

        const clearTimer = setTimeout(() => setActiveId(null), duration);

        return () => {
            clearTimeout(scrollTimer);
            clearTimeout(clearTimer);
        };
        // Runs once per mount: the id is captured on first render.
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [highlightId]);

    const isHighlighted = useCallback(
        (id) => activeId !== null && String(id) === activeId,
        [activeId],
    );

    const getRowProps = useCallback(
        (id, className) => ({
            "data-highlight-id": String(id),
            className: cn(className, isHighlighted(id) && HIGHLIGHT_CLASS),
        }),
        [isHighlighted],
    );

    return { isHighlighted, getRowProps };
}
