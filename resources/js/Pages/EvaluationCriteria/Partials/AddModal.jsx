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
import InputError from "@/Components/InputError";
import useAddCriteria from "../Hooks/useAddCriteria";

export default function AddModal({ children }) {
    const {
        open,
        openModal,
        closeModal,
        data,
        setData,
        errors,
        processing,
        handleSubmit,
    } = useAddCriteria();

    return (
        <>
            <div onClick={openModal}>{children}</div>

            <Dialog open={open} onOpenChange={closeModal}>
                <DialogContent className="sm:max-w-[425px]">
                    <form onSubmit={handleSubmit}>
                        <DialogHeader>
                            <DialogTitle>Add Evaluation Criterion</DialogTitle>
                            <DialogDescription>
                                New criteria appear immediately in the
                                evaluation form — no code changes needed.
                            </DialogDescription>
                        </DialogHeader>

                        <div className="grid gap-4">
                            <div className="grid gap-3">
                                <Label>Category</Label>
                                <Input
                                    value={data.category}
                                    onChange={(e) =>
                                        setData("category", e.target.value)
                                    }
                                    placeholder="e.g. Work Quality"
                                />
                                <InputError message={errors.category} />
                            </div>

                            <div className="grid gap-3">
                                <Label>Label</Label>
                                <Input
                                    value={data.label}
                                    onChange={(e) =>
                                        setData("label", e.target.value)
                                    }
                                    placeholder="e.g. Produces accurate, complete work"
                                />
                                <InputError message={errors.label} />
                            </div>

                            <div className="grid gap-3">
                                <Label>Description (optional)</Label>
                                <textarea
                                    rows={2}
                                    value={data.description}
                                    onChange={(e) =>
                                        setData(
                                            "description",
                                            e.target.value,
                                        )
                                    }
                                    className="flex w-full rounded-md border border-input bg-transparent px-3 py-2 text-sm shadow-sm placeholder:text-muted-foreground focus-visible:outline-none focus-visible:ring-1 focus-visible:ring-ring disabled:cursor-not-allowed disabled:opacity-50"
                                />
                                <InputError message={errors.description} />
                            </div>

                            <div className="grid gap-3">
                                <Label>Sort Order</Label>
                                <Input
                                    type="number"
                                    min="0"
                                    value={data.sort_order}
                                    onChange={(e) =>
                                        setData("sort_order", e.target.value)
                                    }
                                />
                                <InputError message={errors.sort_order} />
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
                                Add Criterion
                            </Button>
                        </DialogFooter>
                    </form>
                </DialogContent>
            </Dialog>
        </>
    );
}
