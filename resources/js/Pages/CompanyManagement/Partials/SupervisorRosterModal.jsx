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
import { Label } from "@/Components/ui/label";
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from "@/Components/ui/select";
import InputError from "@/Components/InputError";
import { X } from "lucide-react";
import useSupervisorRoster from "../Hooks/useSupervisorRoster";

export default function SupervisorRosterModal({
    company,
    availableSupervisors,
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
        selectableSupervisors,
        handleAttach,
        handleDetach,
    } = useSupervisorRoster(company, availableSupervisors);

    const roster = company.supervisors || [];

    return (
        <>
            <div onClick={openModal}>{children}</div>

            <Dialog open={open} onOpenChange={closeModal}>
                <DialogContent className="sm:max-w-[450px]">
                    <DialogHeader>
                        <DialogTitle>
                            Supervisor Roster — {company.company_name}
                        </DialogTitle>
                        <DialogDescription>
                            Supervisors eligible to be assigned to students at
                            this company
                        </DialogDescription>
                    </DialogHeader>

                    <div className="max-h-[40vh] space-y-2 overflow-y-auto pr-1">
                        {roster.length ? (
                            roster.map((supervisor) => (
                                <div
                                    key={supervisor.id}
                                    className="flex items-center justify-between rounded-md border px-3 py-2 text-sm"
                                >
                                    <div>
                                        <p className="font-medium">
                                            {supervisor.name}
                                        </p>
                                        <p className="text-xs text-muted-foreground">
                                            {supervisor.email}
                                        </p>
                                    </div>
                                    <Button
                                        type="button"
                                        variant="ghost"
                                        size="sm"
                                        className="text-red-600 hover:text-red-700"
                                        onClick={() =>
                                            handleDetach(supervisor.id)
                                        }
                                    >
                                        <X className="h-3.5 w-3.5" />
                                    </Button>
                                </div>
                            ))
                        ) : (
                            <p className="text-sm text-muted-foreground">
                                No supervisors on this company's roster yet.
                            </p>
                        )}
                    </div>

                    <form
                        onSubmit={handleAttach}
                        className="flex items-end gap-2 border-t pt-4"
                    >
                        <div className="grid flex-1 gap-2">
                            <Label>Add Supervisor</Label>
                            <Select
                                value={data.user_id}
                                onValueChange={(value) =>
                                    setData("user_id", value)
                                }
                            >
                                <SelectTrigger>
                                    <SelectValue placeholder="Select a supervisor" />
                                </SelectTrigger>
                                <SelectContent>
                                    {selectableSupervisors.length === 0 ? (
                                        <div className="px-2 py-1.5 text-sm text-muted-foreground">
                                            No more supervisors available
                                        </div>
                                    ) : (
                                        selectableSupervisors.map(
                                            (supervisor) => (
                                                <SelectItem
                                                    key={supervisor.id}
                                                    value={String(
                                                        supervisor.id,
                                                    )}
                                                >
                                                    {supervisor.name}
                                                </SelectItem>
                                            ),
                                        )
                                    )}
                                </SelectContent>
                            </Select>
                            <InputError message={errors.user_id} />
                        </div>
                        <Button
                            type="submit"
                            disabled={processing || !data.user_id}
                        >
                            Add
                        </Button>
                    </form>

                    <DialogFooter>
                        <DialogClose asChild>
                            <Button type="button" variant="outline">
                                Close
                            </Button>
                        </DialogClose>
                    </DialogFooter>
                </DialogContent>
            </Dialog>
        </>
    );
}
