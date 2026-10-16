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

function CurrentBadge({ className = "" }) {
    return (
        <span
            className={cn(
                "inline-flex shrink-0 items-center rounded-full bg-primary/10 px-1.5 py-px text-[10px] font-semibold uppercase tracking-wide text-primary-strong",
                className,
            )}
        >
            Current
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
                            {term.is_current && <CurrentBadge />}
                        </span>
                    </SelectItem>
                ))}
            </SelectContent>
        </Select>
    );
}
