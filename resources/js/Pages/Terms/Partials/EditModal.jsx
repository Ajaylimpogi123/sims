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
import useEditTerm from "../Hooks/useEditTerm";
import TermFormFields from "./TermFormFields";

export default function EditModal({
    term,
    semesters,
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
    } = useEditTerm(term, { open: controlledOpen, onOpenChange });

    return (
        <>
            {children && <div onClick={openModal}>{children}</div>}

            <Dialog open={open} onOpenChange={closeModal}>
                <DialogContent className="sm:max-w-[460px]">
                    <form onSubmit={handleSubmit}>
                        <DialogHeader>
                            <DialogTitle>Edit Term</DialogTitle>
                            <DialogDescription>{term?.label}</DialogDescription>
                        </DialogHeader>

                        <TermFormFields
                            idPrefix={`edit-term-${term?.id}`}
                            data={data}
                            setData={setData}
                            errors={errors}
                            semesters={semesters}
                        />

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
