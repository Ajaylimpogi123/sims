import { Button } from "@/components/ui/button";
import {
    Dialog,
    DialogContent,
    DialogClose,
    DialogFooter,
    DialogHeader,
    DialogTitle,
    DialogDescription,
} from "@/components/ui/dialog";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from "@/components/ui/select";
import InputError from "@/Components/InputError";
import useEditReport from "../Hooks/useEditReport";

export default function EditReportModal({ report, children }) {
    const {
        open,
        openModal,
        closeModal,
        data,
        setData,
        errors,
        processing,
        handleSubmit,
    } = useEditReport(report);

    return (
        <>
            <div onClick={openModal}>{children}</div>

            <Dialog open={open} onOpenChange={closeModal}>
                <DialogContent className="sm:max-w-[500px]">
                    <form onSubmit={handleSubmit}>
                        <DialogHeader>
                            <DialogTitle>Edit Report</DialogTitle>
                            <DialogDescription>
                                Only pending reports can be edited
                            </DialogDescription>
                        </DialogHeader>

                        <div className="grid gap-4 max-h-[65vh] overflow-y-auto pr-1 py-2">
                            <div className="grid gap-2">
                                <Label>Type</Label>
                                <Select
                                    value={data.type}
                                    onValueChange={(value) =>
                                        setData((prev) => ({
                                            ...prev,
                                            type: value,
                                            period_end:
                                                value === "daily"
                                                    ? prev.period_start
                                                    : prev.period_end,
                                        }))
                                    }
                                >
                                    <SelectTrigger>
                                        <SelectValue />
                                    </SelectTrigger>
                                    <SelectContent>
                                        <SelectItem value="daily">
                                            Daily
                                        </SelectItem>
                                        <SelectItem value="weekly">
                                            Weekly
                                        </SelectItem>
                                    </SelectContent>
                                </Select>
                                <InputError message={errors.type} />
                            </div>

                            <div className="grid gap-2">
                                <Label>
                                    {data.type === "daily"
                                        ? "Date"
                                        : "Period Start"}
                                </Label>
                                <Input
                                    type="date"
                                    value={data.period_start}
                                    onChange={(e) =>
                                        setData((prev) => ({
                                            ...prev,
                                            period_start: e.target.value,
                                            period_end:
                                                prev.type === "daily"
                                                    ? e.target.value
                                                    : prev.period_end,
                                        }))
                                    }
                                />
                                <InputError message={errors.period_start} />
                            </div>

                            {data.type === "weekly" && (
                                <div className="grid gap-2">
                                    <Label>Period End</Label>
                                    <Input
                                        type="date"
                                        value={data.period_end}
                                        onChange={(e) =>
                                            setData(
                                                "period_end",
                                                e.target.value,
                                            )
                                        }
                                    />
                                    <InputError
                                        message={errors.period_end}
                                    />
                                </div>
                            )}

                            <div className="grid gap-2">
                                <Label>Accomplishment Details</Label>
                                <textarea
                                    rows={4}
                                    value={data.content}
                                    onChange={(e) =>
                                        setData("content", e.target.value)
                                    }
                                    className="flex w-full rounded-md border border-input bg-transparent px-3 py-2 text-sm shadow-sm placeholder:text-muted-foreground focus-visible:outline-none focus-visible:ring-1 focus-visible:ring-ring disabled:cursor-not-allowed disabled:opacity-50"
                                />
                                <InputError message={errors.content} />
                            </div>

                            <div className="grid gap-2">
                                <Label>
                                    Replace Attachment (optional)
                                </Label>
                                <Input
                                    type="file"
                                    accept=".jpg,.jpeg,.png,.pdf"
                                    onChange={(e) =>
                                        setData(
                                            "attachment",
                                            e.target.files[0] || null,
                                        )
                                    }
                                />
                                <InputError message={errors.attachment} />
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
