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
import { Label } from "@/components/ui/label";
import InputError from "@/Components/InputError";
import useReviewReport from "../Hooks/useReviewReport";
import { formatLongDate } from "@/lib/dates";
import { usePage } from "@inertiajs/react";

const SUPERVISOR_ROLE_ID = 3;

export default function ReviewModal({ report, children }) {
    const { auth } = usePage().props;
    // Supervisor can view every report's details but can no longer submit a
    // review (comment + mark reviewed) — that's Coordinator/Admin only now.
    const canReview = auth?.user?.role_id !== SUPERVISOR_ROLE_ID;

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
                            <div className="rounded-md border bg-muted/30 p-3 text-sm whitespace-pre-wrap">
                                {report.content}
                            </div>

                            {report.attachment_path && (
                                <a
                                    href={route(
                                        "report-reviews.attachment",
                                        report.id,
                                    )}
                                    target="_blank"
                                    rel="noreferrer"
                                    className="text-sm text-blue-600 underline"
                                >
                                    {report.attachment_original_name ||
                                        "View attachment"}
                                </a>
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
                                        className="flex w-full rounded-md border border-input bg-transparent px-3 py-2 text-sm shadow-sm placeholder:text-muted-foreground focus-visible:outline-none focus-visible:ring-1 focus-visible:ring-ring disabled:cursor-not-allowed disabled:opacity-50"
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
