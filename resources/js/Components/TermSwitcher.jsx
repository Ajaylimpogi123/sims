import { CalendarRange } from "lucide-react";
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from "@/Components/ui/select";
import useTerm from "@/hooks/useTerm";
import { cn } from "@/lib/utils";

const BADGE_STYLES = {
    current: "bg-primary/10 text-primary-strong",
    upcoming: "bg-sky-100 text-sky-700",
    archived: "bg-gray-100 text-gray-600",
};
const BADGE_LABELS = {
    current: "Current",
    upcoming: "Upcoming",
    archived: "Archived",
};

// Past = archived (read-only for non-Admins); neither current nor past =
// upcoming (fully open).
function termKind(term) {
    if (term.is_current) return "current";
    if (term.is_past) return "archived";
    if (term.is_past === false) return "upcoming";
    return null;
}

function TermBadge({ term }) {
    const kind = termKind(term);
    if (!kind) return null;

    return (
        <span
            className={cn(
                "inline-flex shrink-0 items-center rounded-full px-1.5 py-px text-[10px] font-semibold uppercase tracking-wide",
                BADGE_STYLES[kind],
            )}
        >
            {BADGE_LABELS[kind]}
        </span>
    );
}

/**
 * Header term picker. Staff (Coordinator/Supervisor/Admin) switch the term
 * they're viewing (stored in the session server-side); students only see
 * their own term, read-only.
 */
export function TermSwitcher() {
    const { terms, selectedTerm, isStudent, selectTerm } = useTerm();

    if (isStudent) {
        if (!selectedTerm) return null;

        return (
            <span
                className="inline-flex max-w-[55vw] items-center gap-1.5 truncate rounded-full border bg-background px-2.5 py-1 text-xs font-medium text-muted-foreground sm:max-w-none"
                title={selectedTerm.label}
            >
                <CalendarRange className="h-3.5 w-3.5 shrink-0 text-primary" />
                <span className="truncate">{selectedTerm.label}</span>
            </span>
        );
    }

    if (!terms.length) return null;

    return (
        <Select
            value={selectedTerm ? String(selectedTerm.id) : undefined}
            onValueChange={(value) => {
                if (String(selectedTerm?.id) !== value) selectTerm(value);
            }}
        >
            <SelectTrigger
                aria-label="School term"
                className="h-8 w-auto max-w-[55vw] gap-2 text-xs sm:max-w-[320px]"
            >
                <CalendarRange className="h-3.5 w-3.5 shrink-0 text-primary" />
                <SelectValue placeholder="Select term" />
            </SelectTrigger>
            <SelectContent align="end">
                {terms.map((term) => (
                    <SelectItem key={term.id} value={String(term.id)}>
                        <span className="flex items-center gap-2">
                            <span className="truncate">{term.label}</span>
                            <TermBadge term={term} />
                        </span>
                    </SelectItem>
                ))}
            </SelectContent>
        </Select>
    );
}
