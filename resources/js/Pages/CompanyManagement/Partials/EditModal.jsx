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
import InputError from "@/Components/InputError";
import useEditCompany from "../Hooks/useEditCompany";
import CompanySupervisorSelect, {
    NO_SUPERVISOR_STUDENTS_MESSAGE,
    reassignmentMessage,
} from "./CompanySupervisorSelect";

export default function EditModal({
    company,
    supervisorOptions = [],
    children,
    open: controlledOpen,
    onOpenChange,
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
    } = useEditCompany(company, { open: controlledOpen, onOpenChange });

    // Saving a different supervisor moves every student at the company.
    const currentSupervisorId =
        company?.supervisor_id != null ? String(company.supervisor_id) : "";
    const studentsCount = Number(company?.students_count) || 0;
    const pickedName = (
        supervisorOptions.find((s) => String(s.id) === data.supervisor_id) ??
        (company?.supervisor &&
        String(company.supervisor.id) === data.supervisor_id
            ? company.supervisor
            : null)
    )?.name;
    const confirmMessage =
        data.supervisor_id !== currentSupervisorId && studentsCount > 0
            ? pickedName
                ? reassignmentMessage(studentsCount, pickedName)
                : NO_SUPERVISOR_STUDENTS_MESSAGE(studentsCount)
            : null;

    return (
        <>
            {children && <div onClick={openModal}>{children}</div>}

            <Dialog open={open} onOpenChange={closeModal}>
                <DialogContent className="sm:max-w-[425px]">
                    <form
                        onSubmit={(e) =>
                            handleSubmit(e, {
                                confirmMessage: confirmMessage
                                    ? `${confirmMessage} Continue?`
                                    : null,
                            })
                        }
                    >
                        <DialogHeader>
                            <DialogTitle>Edit Company</DialogTitle>
                            <DialogDescription>
                                Update company details
                            </DialogDescription>
                        </DialogHeader>

                        <div className="grid gap-4 max-h-[65vh] overflow-y-auto pr-1 py-2">
                            <div className="grid gap-3">
                                <Label>Company Name</Label>
                                <Input
                                    value={data.company_name}
                                    onChange={(e) =>
                                        setData("company_name", e.target.value)
                                    }
                                />
                                <InputError message={errors.company_name} />
                            </div>

                            <div className="grid gap-3">
                                <Label>Address</Label>
                                <Input
                                    value={data.address}
                                    onChange={(e) =>
                                        setData("address", e.target.value)
                                    }
                                />
                                <InputError message={errors.address} />
                            </div>

                            <CompanySupervisorSelect
                                value={data.supervisor_id}
                                onChange={(value) =>
                                    setData("supervisor_id", value)
                                }
                                error={errors.supervisor_id}
                                supervisorOptions={supervisorOptions}
                                company={company}
                            />

                            <div className="grid gap-3">
                                <Label>Contact Number</Label>
                                <Input
                                    value={data.contact_number}
                                    onChange={(e) =>
                                        setData(
                                            "contact_number",
                                            e.target.value,
                                        )
                                    }
                                />
                                <InputError message={errors.contact_number} />
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

                            <div className="grid gap-3">
                                <Label>Industry</Label>
                                <Input
                                    value={data.industry}
                                    onChange={(e) =>
                                        setData("industry", e.target.value)
                                    }
                                />
                                <InputError message={errors.industry} />
                            </div>

                            <div className="grid gap-3">
                                <Label>Internship Slots</Label>
                                <Input
                                    type="number"
                                    min="0"
                                    value={data.slots}
                                    onChange={(e) =>
                                        setData("slots", e.target.value)
                                    }
                                />
                                <InputError message={errors.slots} />
                            </div>
                        </div>

                        <DialogFooter>
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
