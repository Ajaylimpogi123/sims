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

const UNASSIGNED = "unassigned";

const STATUS_OPTIONS = [
    { value: "not_started", label: "Not Started" },
    { value: "ongoing", label: "Ongoing" },
    { value: "completed", label: "Completed" },
];

export default function StudentModal({ student, companies, children }) {
    const {
        open,
        openModal,
        closeModal,
        data,
        setData,
        errors,
        processing,
        handleSubmit,
    } = useManageStudent(student);

    const isCurrentCompany = (company) =>
        student?.company_id != null &&
        String(company.id) === String(student.company_id);
    const isCurrentSupervisor = (supervisor) =>
        student?.supervisor_id != null &&
        String(supervisor.id) === String(student.supervisor_id);

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

    // The supervisor dropdown is dependent on the selected company — only
    // active supervisors on that company's roster (Company Management) are
    // selectable, plus the student's current supervisor if they're still on
    // that roster (even if inactive).
    const rosterFor = (companyId) => {
        const company = companies.find((c) => String(c.id) === companyId);
        return (company?.supervisors || []).filter(
            (s) => s.status === "active" || isCurrentSupervisor(s),
        );
    };

    const selectedCompany = useMemo(
        () => companies.find((c) => String(c.id) === data.company_id),
        [companies, data.company_id],
    );
    const rosterSupervisors = useMemo(
        () => rosterFor(data.company_id),
        [companies, data.company_id, student?.supervisor_id],
    );

    const handleCompanyChange = (value) => {
        const newCompanyId = value === UNASSIGNED ? "" : value;
        const supervisorStillValid = rosterFor(newCompanyId).some(
            (supervisor) => String(supervisor.id) === data.supervisor_id,
        );

        setData({
            ...data,
            company_id: newCompanyId,
            // Changing the company can invalidate the previously-selected
            // supervisor (they may not be on the new company's roster) —
            // only auto-clear when the user actually changes the company
            // themselves, not on the modal's initial data load.
            supervisor_id: supervisorStillValid ? data.supervisor_id : "",
        });
    };

    return (
        <>
            <div onClick={openModal}>{children}</div>

            <Dialog open={open} onOpenChange={closeModal}>
                <DialogContent className="sm:max-w-[500px]">
                    <form onSubmit={handleSubmit}>
                        <DialogHeader>
                            <DialogTitle>{student?.name}</DialogTitle>
                            <DialogDescription>
                                Update the student's profile, company/
                                supervisor assignment, and internship status
                            </DialogDescription>
                        </DialogHeader>

                        <div className="grid gap-4 max-h-[65vh] overflow-y-auto pr-1 py-2">
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
                                </div>

                                <div className="grid gap-3">
                                    <Label>Supervisor</Label>
                                    <Select
                                        value={
                                            data.supervisor_id || UNASSIGNED
                                        }
                                        onValueChange={(value) =>
                                            setData(
                                                "supervisor_id",
                                                value === UNASSIGNED
                                                    ? ""
                                                    : value,
                                            )
                                        }
                                        disabled={!selectedCompany}
                                    >
                                        <SelectTrigger>
                                            <SelectValue
                                                placeholder={
                                                    selectedCompany
                                                        ? "Select a supervisor"
                                                        : "Assign a company first"
                                                }
                                            />
                                        </SelectTrigger>
                                        <SelectContent>
                                            <SelectItem value={UNASSIGNED}>
                                                Unassigned
                                            </SelectItem>
                                            {rosterSupervisors.length ===
                                                0 && selectedCompany && (
                                                <div className="px-2 py-1.5 text-sm text-muted-foreground">
                                                    No active supervisors on
                                                    this company's roster
                                                </div>
                                            )}
                                            {rosterSupervisors.map(
                                                (supervisor) => (
                                                    <SelectItem
                                                        key={supervisor.id}
                                                        value={String(
                                                            supervisor.id,
                                                        )}
                                                    >
                                                        {supervisor.name}
                                                        {supervisor.status !==
                                                            "active" &&
                                                            " (inactive)"}
                                                    </SelectItem>
                                                ),
                                            )}
                                        </SelectContent>
                                    </Select>
                                    <InputError
                                        message={errors.supervisor_id}
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
