import {
    Tooltip,
    TooltipContent,
    TooltipTrigger,
} from "@/Components/ui/tooltip";
import { cn } from "@/lib/utils";

export const REQUIRED_HOURS_NOT_SET = "Required hours not set";

// Shown in place of a progress bar / required-hours value when a student's
// required_hours is null. Optional `action` renders next to the label (e.g.
// a "Set" trigger for roles allowed to edit required hours).
export function RequiredHoursNotSet({ action, className }) {
    return (
        <span
            className={cn(
                "inline-flex items-center gap-2 whitespace-nowrap text-sm text-muted-foreground",
                className,
            )}
        >
            {REQUIRED_HOURS_NOT_SET}
            {action}
        </span>
    );
}

// Remaining hours can't be computed without a target: keep the "—" but
// explain it on hover/focus.
export function RemainingHoursUnknown() {
    return (
        <Tooltip>
            <TooltipTrigger asChild>
                <span
                    tabIndex={0}
                    className="cursor-help text-muted-foreground"
                    aria-label={`Remaining hours unavailable: ${REQUIRED_HOURS_NOT_SET.toLowerCase()}`}
                >
                    —
                </span>
            </TooltipTrigger>
            <TooltipContent>{REQUIRED_HOURS_NOT_SET}</TooltipContent>
        </Tooltip>
    );
}
