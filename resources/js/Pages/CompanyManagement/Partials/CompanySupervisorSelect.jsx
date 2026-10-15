import { useMemo } from "react";
import { AlertTriangle } from "lucide-react";
import { Label } from "@/Components/ui/label";
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from "@/Components/ui/select";
import InputError from "@/Components/InputError";

const NONE = "none";

export const ISSUE_SUFFIX = {
    inactive: "inactive",
    not_a_supervisor: "not a supervisor",
};

export const ISSUE_HINTS = {
    inactive:
        "This supervisor's account is inactive. Choose an active supervisor.",
    not_a_supervisor:
        "This account is no longer a Supervisor. Choose an active supervisor.",
};

const studentsAtCompany = (count) =>
    count === 1
        ? "The 1 student at this company"
        : `All ${count} students at this company`;

// Changing a company's supervisor moves every student placed there.
export function reassignmentMessage(count, name) {
    return `${studentsAtCompany(count)} will be moved to ${name}.`;
}

export function NO_SUPERVISOR_STUDENTS_MESSAGE(count) {
    return `${studentsAtCompany(count)} will be left without a supervisor.`;
}

/**
 * "Company Supervisor" picker for the Add/Edit company forms. New picks are
 * the active Supervisor accounts (`supervisorOptions`); the company's current
 * supervisor stays listed (flagged) when they're no longer a valid pick, so
 * an unchanged save keeps them. The server enforces the same rule.
 */
export default function CompanySupervisorSelect({
    value,
    onChange,
    error,
    supervisorOptions = [],
    company = null,
}) {
    const current = company?.supervisor ?? null;
    const currentIssue =
        company?.supervisor_issue === "inactive" ||
        company?.supervisor_issue === "not_a_supervisor"
            ? company.supervisor_issue
            : null;

    const options = useMemo(() => {
        const list = supervisorOptions.map((s) => ({ ...s, flag: null }));
        if (current && !list.some((s) => String(s.id) === String(current.id))) {
            list.push({
                ...current,
                flag: ISSUE_SUFFIX[currentIssue] ?? "inactive",
            });
        }
        return list;
    }, [supervisorOptions, current?.id, currentIssue]);

    const keepsFlaggedCurrent =
        current != null &&
        currentIssue != null &&
        value === String(current.id);

    const studentsCount = Number(company?.students_count) || 0;
    const changed =
        company != null && value !== (current ? String(current.id) : "");
    const picked = options.find((s) => String(s.id) === value);

    return (
        <div className="grid gap-3">
            <Label>Company Supervisor</Label>
            <Select
                value={value || NONE}
                onValueChange={(v) => onChange(v === NONE ? "" : v)}
            >
                <SelectTrigger>
                    <SelectValue placeholder="Select a supervisor" />
                </SelectTrigger>
                <SelectContent>
                    <SelectItem value={NONE}>No supervisor</SelectItem>
                    {options.map((s) => (
                        <SelectItem key={s.id} value={String(s.id)}>
                            {s.name}
                            {s.flag ? ` (${s.flag})` : ""}
                        </SelectItem>
                    ))}
                </SelectContent>
            </Select>

            {supervisorOptions.length === 0 && (
                <p className="text-xs text-muted-foreground">
                    No active Supervisor accounts yet. Create one in User
                    Management.
                </p>
            )}
            {keepsFlaggedCurrent && !error && (
                <p className="flex items-start gap-1.5 text-xs text-amber-700">
                    <AlertTriangle className="mt-px h-3.5 w-3.5 shrink-0" />
                    {ISSUE_HINTS[currentIssue]}
                </p>
            )}
            {changed && studentsCount > 0 && !error && (
                <p className="flex items-start gap-1.5 text-xs text-amber-700">
                    <AlertTriangle className="mt-px h-3.5 w-3.5 shrink-0" />
                    {picked
                        ? reassignmentMessage(studentsCount, picked.name)
                        : NO_SUPERVISOR_STUDENTS_MESSAGE(studentsCount)}
                </p>
            )}
            <InputError message={error} />
        </div>
    );
}
