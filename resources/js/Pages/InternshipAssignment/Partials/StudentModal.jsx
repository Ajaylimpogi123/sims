import { Button } from "@/Components/ui/button";
import {
    Dialog,
    DialogContent,
    DialogClose,
    DialogFooter,
    DialogHeader,
    DialogTitle,
    DialogDescription,
} from "@/Components/ui/dialog";
import { Input } from "@/Components/ui/input";
import { Label } from "@/Components/ui/label";
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from "@/Components/ui/select";
import InputError from "@/Components/InputError";
import useManageStudent from "../Hooks/useManageStudent";
import { useMemo } from "react";
import { AlertTriangle } from "lucide-react";
import useTerm from "@/hooks/useTerm";

const UNASSIGNED = "unassigned";

// Same wording as the server's 422s, which replace these hints when sent.
const REQUIRED_HOURS_FOR_ONGOING_HINT =
    "Set the required hours before marking the internship Ongoing.";
const REQUIRED_HOURS_LOCKED_HINT =
    "Required hours can't be cleared while the internship is Ongoing.";

// Read-only "Supervisor: <name>" line under Company.
const SUPERVISOR_SUFFIX = {
    inactive: " (inactive)",
    not_a_supervisor: " (not a supervisor)",
};
const SUPERVISOR_HINTS = {
    no_supervisor:
        "This company has no supervisor yet — set one in Company Management.",
    inactive:
        "This company's supervisor account is inactive. Set an active supervisor in Company Management.",
    not_a_supervisor:
        "This company's supervisor account is no longer a Supervisor. Set a new one in Company Management.",
};

const STATUS_OPTIONS = [
    { value: "not_started", label: "Not Started" },
    { value: "ongoing", label: "Ongoing" },
    { value: "completed", label: "Completed" },
];

export default function StudentModal({
    student,
    companies,
    needsSupervisorOnly = false,
    open: controlledOpen,
    onOpenChange,
    children,
}) {
    const {
        open,
        openModal,
        closeModal,
        data,
        setData,
        errors,
        processing,
        handleSubmit,
    } = useManageStudent(student, {
        needsSupervisorOnly,
        open: controlledOpen,
        onOpenChange,
    });

    // Mirrors the server, which checks the hours submitted in the same save
    // (empty / 0 = not set):
    // - moving INTO Ongoing without hours → 422 on internship_status;
    // - an Ongoing student whose saved hours are emptied → 422 on
    //   required_hours. A legacy Ongoing row with no hours that stays
    //   Ongoing and empty still saves, so no hint then.
    const hoursEntered = Number(data.required_hours) > 0;
    const wasOngoing = student?.internship_status === "ongoing";
    const needsRequiredHoursForOngoing =
        data.internship_status === "ongoing" && !wasOngoing && !hoursEntered;
    const clearingHoursWhileOngoing =
        wasOngoing &&
        data.internship_status === "ongoing" &&
        Number(student?.required_hours) > 0 &&
        !hoursEntered;

    const isCurrentCompany = (company) =>
        student?.company_id != null &&
        String(company.id) === String(student.company_id);

    // Only active companies can take a NEW assignment; the student's current
    // company stays listed (even if inactive) so the existing placement can
    // be kept. The server enforces the same rule.
    const selectableCompanies = useMemo(
        () =>
            companies.filter(
                (c) => c.status === "active" || isCurrentCompany(c),
            ),
        [companies, student?.company_id],
    );

    const selectedCompany = useMemo(
        () => companies.find((c) => String(c.id) === data.company_id),
        [companies, data.company_id],
    );

    // The student's supervisor is always the company's supervisor (set in
    // Company Management); the server assigns it on save. Shown read-only.
    const companySupervisor = selectedCompany?.supervisor ?? null;
    // companies[].supervisor_issue (server): no_supervisor | not_a_supervisor
    // | inactive | null. Older payloads without it fall back to what the
    // account status shows.
    const supervisorIssue =
        selectedCompany && "supervisor_issue" in selectedCompany
            ? selectedCompany.supervisor_issue ?? null
            : !companySupervisor
              ? "no_supervisor"
              : companySupervisor.status !== "active"
                ? "inactive"
                : null;

    // Only a Not Started student (as saved) can move to another term; the
    // server enforces the same rule (error on term_id).
    const { terms } = useTerm();
    const canChangeTerm = student?.internship_status === "not_started";
    const termOptions = useMemo(() => {
        if (
            student?.term_id == null ||
            terms.some((t) => String(t.id) === String(student.term_id))
        ) {
            return terms;
        }
        // Keep the student's own term selectable even if it isn't listed.
        return [
            {
                id: student.term_id,
                label: student.term_label ?? "Current term",
                is_current: false,
            },
            ...terms,
        ];
    }, [terms, student?.term_id, student?.term_label]);

    const handleCompanyChange = (value) =>
        setData("company_id", value === UNASSIGNED ? "" : value);

    return (
        <>
            {children && <div onClick={openModal}>{children}</div>}

            <Dialog open={open} onOpenChange={closeModal}>
                <DialogContent className="sm:max-w-[500px]">
                    <form onSubmit={handleSubmit}>
                        <DialogHeader>
                            <DialogTitle>{student?.name}</DialogTitle>
                            <DialogDescription>
                                Update the student's profile, company
                                assignment, required hours and
                                internship status
                            </DialogDescription>
                        </DialogHeader>

                        <div className="grid gap-4 max-h-[65vh] overflow-y-auto pr-1 py-2">
                            {errors.term && (
                                <p
                                    role="alert"
                                    className="flex items-start gap-1.5 rounded-md border border-red-200 bg-red-50 px-3 py-2 text-sm text-red-700"
                                >
                                    <AlertTriangle className="mt-0.5 h-4 w-4 shrink-0" />
                                    {errors.term}
                                </p>
                            )}
                            <div className="space-y-3">
                                <h4 className="text-sm font-semibold text-muted-foreground">
                                    Profile
                                </h4>

                                <div className="grid gap-3">
                                    <Label>Name</Label>
                                    <Input
                                        value={data.name}
                                        onChange={(e) =>
                                            setData("name", e.target.value)
                                        }
                                    />
                                    <InputError message={errors.name} />
                                </div>

                                <div className="grid gap-3">
                                    <Label>Email</Label>
                                    <Input
                                        type="email"
                                        value={data.email}
                                        onChange={(e) =>
                                            setData("email", e.target.value)
                                        }
                                    />
                                    <InputError message={errors.email} />
                                </div>

                                <div className="grid grid-cols-2 gap-3">
                                    <div className="grid gap-3">
                                        <Label>Student Number</Label>
                                        <Input
                                            value={data.student_number}
                                            onChange={(e) =>
                                                setData(
                                                    "student_number",
                                                    e.target.value,
                                                )
                                            }
                                        />
                                        <InputError
                                            message={errors.student_number}
                                        />
                                    </div>

                                    <div className="grid gap-3">
                                        <Label>Course</Label>
                                        <Input
                                            value={data.course}
                                            onChange={(e) =>
                                                setData(
                                                    "course",
                                                    e.target.value,
                                                )
                                            }
                                        />
                                        <InputError message={errors.course} />
                                    </div>
                                </div>

                                <div className="grid gap-3">
                                    <Label>Section</Label>
                                    <Input
                                        value={data.section}
                                        onChange={(e) =>
                                            setData("section", e.target.value)
                                        }
                                    />
                                    <InputError message={errors.section} />
                                </div>
                            </div>

                            <div className="space-y-3 border-t pt-4">
                                <h4 className="text-sm font-semibold text-muted-foreground">
                                    Internship Assignment
                                </h4>

                                <div className="grid gap-3">
                                    <Label>Term</Label>
                                    <Select
                                        value={data.term_id || undefined}
                                        onValueChange={(value) =>
                                            setData("term_id", value)
                                        }
                                        disabled={!canChangeTerm}
                                    >
                                        <SelectTrigger>
                                            <SelectValue placeholder="Select a term" />
                                        </SelectTrigger>
                                        <SelectContent>
                                            {termOptions.map((term) => (
                                                <SelectItem
                                                    key={term.id}
                                                    value={String(term.id)}
                                                >
                                                    {term.label}
                                                    {term.is_current &&
                                                        " (current)"}
                                                </SelectItem>
                                            ))}
                                        </SelectContent>
                                    </Select>
                                    {!canChangeTerm && (
                                        <p className="text-xs text-muted-foreground">
                                            Only students who haven't started
                                            can move to another term.
                                        </p>
                                    )}
                                    <InputError message={errors.term_id} />
                                </div>

                                <div className="grid gap-3">
                                    <Label>Company</Label>
                                    <Select
                                        value={data.company_id || UNASSIGNED}
                                        onValueChange={handleCompanyChange}
                                    >
                                        <SelectTrigger>
                                            <SelectValue placeholder="Select a company" />
                                        </SelectTrigger>
                                        <SelectContent>
                                            <SelectItem value={UNASSIGNED}>
                                                Unassigned
                                            </SelectItem>
                                            {selectableCompanies.map((company) => (
                                                <SelectItem
                                                    key={company.id}
                                                    value={String(company.id)}
                                                >
                                                    {company.company_name} (
                                                    {company.students_count ??
                                                        0}
                                                    /{company.slots})
                                                    {company.status !==
                                                        "active" &&
                                                        " (inactive)"}
                                                </SelectItem>
                                            ))}
                                        </SelectContent>
                                    </Select>
                                    <InputError message={errors.company_id} />
                                    {selectedCompany && companySupervisor && (
                                        <p className="text-sm text-muted-foreground">
                                            Supervisor:{" "}
                                            <span className="font-medium text-foreground">
                                                {companySupervisor.name}
                                            </span>
                                            {SUPERVISOR_SUFFIX[supervisorIssue] ??
                                                ""}
                                        </p>
                                    )}
                                    {selectedCompany && supervisorIssue && (
                                        <p className="flex items-start gap-1.5 text-xs text-amber-700">
                                            <AlertTriangle className="mt-px h-3.5 w-3.5 shrink-0" />
                                            {SUPERVISOR_HINTS[supervisorIssue]}
                                        </p>
                                    )}
                                </div>

                                <div className="grid gap-3">
                                    <Label htmlFor="assignment-required-hours">
                                        Required Hours
                                    </Label>
                                    <Input
                                        id="assignment-required-hours"
                                        type="number"
                                        min="0"
                                        max="2000"
                                        step="1"
                                        inputMode="numeric"
                                        placeholder="e.g. 486"
                                        value={data.required_hours}
                                        onChange={(e) =>
                                            setData(
                                                "required_hours",
                                                e.target.value,
                                            )
                                        }
                                    />
                                    {clearingHoursWhileOngoing &&
                                        !errors.required_hours && (
                                            <p className="flex items-start gap-1.5 text-xs text-amber-700">
                                                <AlertTriangle className="mt-px h-3.5 w-3.5 shrink-0" />
                                                {REQUIRED_HOURS_LOCKED_HINT}
                                            </p>
                                        )}
                                    <InputError
                                        message={errors.required_hours}
                                    />
                                </div>

                                <div className="grid gap-3">
                                    <Label>Internship Status</Label>
                                    <Select
                                        value={data.internship_status}
                                        onValueChange={(value) =>
                                            setData(
                                                "internship_status",
                                                value,
                                            )
                                        }
                                    >
                                        <SelectTrigger>
                                            <SelectValue placeholder="Select status" />
                                        </SelectTrigger>
                                        <SelectContent>
                                            {STATUS_OPTIONS.map((status) => (
                                                <SelectItem
                                                    key={status.value}
                                                    value={status.value}
                                                >
                                                    {status.label}
                                                </SelectItem>
                                            ))}
                                        </SelectContent>
                                    </Select>
                                    {needsRequiredHoursForOngoing &&
                                        !errors.internship_status && (
                                            <p className="flex items-start gap-1.5 text-xs text-amber-700">
                                                <AlertTriangle className="mt-px h-3.5 w-3.5 shrink-0" />
                                                {REQUIRED_HOURS_FOR_ONGOING_HINT}
                                            </p>
                                        )}
                                    <InputError
                                        message={errors.internship_status}
                                    />
                                </div>

                                <div className="grid gap-3">
                                    <Label>Internship Schedule</Label>
                                    <Input
                                        placeholder="e.g. Mon-Fri, 8:00 AM - 5:00 PM"
                                        value={data.internship_schedule}
                                        onChange={(e) =>
                                            setData(
                                                "internship_schedule",
                                                e.target.value,
                                            )
                                        }
                                    />
                                    <InputError
                                        message={errors.internship_schedule}
                                    />
                                </div>
                            </div>
                        </div>

                        <DialogFooter className="mt-4">
                            <DialogClose asChild>
                                <Button
                                    type="button"
                                    variant="outline"
                                    disabled={processing}
                                    onClick={closeModal}
                                >
                                    Cancel
                                </Button>
                            </DialogClose>

                            <Button type="submit" disabled={processing}>
                                Save
                            </Button>
                        </DialogFooter>
                    </form>
                </DialogContent>
            </Dialog>
        </>
    );
}
