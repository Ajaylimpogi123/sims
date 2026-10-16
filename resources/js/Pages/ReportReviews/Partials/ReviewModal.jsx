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
import InputError from "@/Components/InputError";
import AttachmentLink from "@/Components/AttachmentLink";
import useReviewReport from "../Hooks/useReviewReport";
import { formatLongDate } from "@/lib/dates";
import { usePage } from "@inertiajs/react";
import useTerm from "@/hooks/useTerm";

const SUPERVISOR_ROLE_ID = 3;

export default function ReviewModal({ report, children }) {
    const { auth } = usePage().props;
    // Supervisor can view every report's details but can no longer submit a
    // review (comment + mark reviewed) — that's Coordinator/Admin only now.
    // Archived terms are read-only too (except for Admins); see useTerm.
    const { canWrite } = useTerm();
    const canReview = auth?.user?.role_id !== SUPERVISOR_ROLE_ID && canWrite;

    const {
        open,
        openModal,
        closeModal,
        data,
        setData,
        errors,
        processing,
        handleSubmit,
    } = useReviewReport(report);

    return (
        <>
            <div onClick={openModal}>{children}</div>

            <Dialog open={open} onOpenChange={closeModal}>
                <DialogContent className="sm:max-w-[500px]">
                    <form onSubmit={canReview ? handleSubmit : undefined}>
                        <DialogHeader>
                            <DialogTitle>
                                {canReview ? "Review Report" : "Report"} —{" "}
                                {report.student?.user?.name}
                            </DialogTitle>
                            <DialogDescription>
                                {report.type === "daily"
                                    ? formatLongDate(report.period_start)
                                    : `${formatLongDate(report.period_start)} — ${formatLongDate(report.period_end)}`}
                            </DialogDescription>
                        </DialogHeader>

                        <div className="grid gap-4 max-h-[65vh] overflow-y-auto pr-1 py-2">
                            {errors.term && (
                                <p
                                    role="alert"
                                    className="rounded-md border border-red-200 bg-red-50 px-3 py-2 text-sm text-red-700"
                                >
                                    {errors.term}
                                </p>
                            )}
                            <div className="rounded-md border bg-muted/30 p-3 text-sm whitespace-pre-wrap">
                                {report.content}
                            </div>

                            {report.attachment_path && (
                                <AttachmentLink
                                    href={route(
                                        "report-reviews.attachment",
                                        report.id,
                                    )}
                                    name={report.attachment_original_name}
                                    className="max-w-full self-start"
                                />
                            )}

                            {canReview ? (
                                <div className="grid gap-2">
                                    <Label>Comment</Label>
                                    <textarea
                                        rows={3}
                                        value={data.comment}
                                        onChange={(e) =>
                                            setData(
                                                "comment",
                                                e.target.value,
                                            )
                                        }
                                        className="flex w-full rounded-lg border border-input bg-background px-3 py-2 text-sm shadow-sm transition-[border-color,box-shadow] duration-150 placeholder:text-muted-foreground hover:border-foreground/25 focus-visible:outline-none focus-visible:border-primary focus-visible:ring-[3px] focus-visible:ring-primary/20 disabled:cursor-not-allowed disabled:bg-muted disabled:opacity-60"
                                    />
                                    <InputError message={errors.comment} />
                                </div>
                            ) : (
                                report.reviewer_comment && (
                                    <div className="grid gap-2">
                                        <Label>Reviewer Comment</Label>
                                        <div className="rounded-md border bg-muted/30 p-3 text-sm whitespace-pre-wrap">
                                            {report.reviewer_comment}
                                        </div>
                                    </div>
                                )
                            )}
                        </div>

                        <DialogFooter className="mt-4">
                            <DialogClose asChild>
                                <Button
                                    type="button"
                                    variant="outline"
                                    disabled={processing}
                                    onClick={closeModal}
                                >
                                    {canReview ? "Cancel" : "Close"}
                                </Button>
                            </DialogClose>

                            {canReview && (
                                <Button type="submit" disabled={processing}>
                                    Mark Reviewed
                                </Button>
                            )}
                        </DialogFooter>
                    </form>
                </DialogContent>
            </Dialog>
        </>
    );
}
