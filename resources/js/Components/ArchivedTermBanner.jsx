import { usePage } from "@inertiajs/react";
import { Archive, AlertTriangle } from "lucide-react";
import { Button } from "@/Components/ui/button";
import useTerm from "@/hooks/useTerm";

/**
 * Under the header: an amber notice while an archived term is selected, and
 * the server's archived-term refusal (validation error on `term`) for writes
 * made outside a dialog (e.g. Pending Approvals' approve/reject).
 */
export function ArchivedTermBanner() {
    const { errors } = usePage().props;
    const { termArchived, selectedTerm, currentTerm, isStudent, selectTerm } =
        useTerm();
    const termError = errors?.term;

    if (!termArchived && !termError) return null;

    return (
        <div className="relative z-20 space-y-2 px-4 pt-3 lg:px-6">
            {termArchived && (
                <div
                    role="status"
                    className="flex flex-col gap-2 rounded-lg border border-amber-200 bg-amber-50 px-4 py-2.5 text-sm text-amber-900 sm:flex-row sm:items-center sm:justify-between"
                >
                    <p className="flex items-start gap-2">
                        <Archive className="mt-0.5 h-4 w-4 shrink-0 text-amber-600" />
                        {isStudent ? (
                            <span>
                                Your internship term
                                {selectedTerm ? ` (${selectedTerm.label})` : ""}{" "}
                                has ended. Your records are read-only.
                            </span>
                        ) : (
                            <span>
                                You're viewing an archived term
                                {selectedTerm ? ` (${selectedTerm.label})` : ""}
                                . It's read-only.
                            </span>
                        )}
                    </p>
                    {!isStudent && currentTerm && (
                        <Button
                            type="button"
                            size="sm"
                            variant="outline"
                            className="h-8 shrink-0 border-amber-300 bg-white text-amber-900 hover:bg-amber-100 hover:text-amber-950"
                            onClick={() => selectTerm(currentTerm.id)}
                        >
                            Back to current term
                        </Button>
                    )}
                </div>
            )}

            {termError && (
                <div
                    role="alert"
                    className="flex items-start gap-2 rounded-lg border border-red-200 bg-red-50 px-4 py-2.5 text-sm text-red-700"
                >
                    <AlertTriangle className="mt-0.5 h-4 w-4 shrink-0" />
                    <span>{termError}</span>
                </div>
            )}
        </div>
    );
}
