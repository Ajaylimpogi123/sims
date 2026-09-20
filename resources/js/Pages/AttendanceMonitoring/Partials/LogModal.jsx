import { useState } from "react";
import { router } from "@inertiajs/react";
import { Button } from "@/components/ui/button";
import {
    Dialog,
    DialogContent,
    DialogHeader,
    DialogTitle,
    DialogDescription,
} from "@/components/ui/dialog";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from "@/components/ui/table";
import InputError from "@/Components/InputError";
import useAddAttendanceEntry from "../Hooks/useAddAttendanceEntry";
import useEditAttendanceEntry from "../Hooks/useEditAttendanceEntry";
import { formatLongDate, formatTime } from "@/lib/dates";

function AttendanceRow({ attendance, studentUserId, onDone }) {
    const [editing, setEditing] = useState(false);

    const { data, setData, errors, processing, handleSubmit } =
        useEditAttendanceEntry(attendance, () => {
            setEditing(false);
            onDone?.();
        });

    const handleDelete = () => {
        if (
            confirm(
                `Delete the attendance entry for ${formatLongDate(attendance.date)}?`,
            )
        ) {
            router.delete(
                route(
                    "attendance-monitoring.attendances.destroy",
                    attendance.id,
                ),
                { preserveScroll: true },
            );
        }
    };

    if (editing) {
        return (
            <TableRow>
                <TableCell>
                    <Input
                        type="date"
                        value={data.date}
                        onChange={(e) => setData("date", e.target.value)}
                    />
                    <InputError message={errors.date} />
                </TableCell>
                <TableCell>
                    <Input
                        type="time"
                        value={data.time_in}
                        onChange={(e) => setData("time_in", e.target.value)}
                    />
                    <InputError message={errors.time_in} />
                </TableCell>
                <TableCell>
                    <Input
                        type="time"
                        value={data.time_out}
                        onChange={(e) => setData("time_out", e.target.value)}
                    />
                    <InputError message={errors.time_out} />
                </TableCell>
                <TableCell>{attendance.rendered_hours ?? "-"}</TableCell>
                <TableCell>-</TableCell>
                <TableCell className="flex gap-2">
                    <Button
                        size="sm"
                        disabled={processing}
                        onClick={handleSubmit}
                    >
                        Save
                    </Button>
                    <Button
                        size="sm"
                        variant="outline"
                        disabled={processing}
                        onClick={() => setEditing(false)}
                    >
                        Cancel
                    </Button>
                </TableCell>
            </TableRow>
        );
    }

    return (
        <TableRow>
            <TableCell>{formatLongDate(attendance.date)}</TableCell>
            <TableCell>{formatTime(attendance.time_in, "-")}</TableCell>
            <TableCell>{formatTime(attendance.time_out, "-")}</TableCell>
            <TableCell>{attendance.rendered_hours ?? "-"}</TableCell>
            <TableCell>
                {attendance.recorded_by === studentUserId ? "Self" : "Staff"}
            </TableCell>
            <TableCell className="flex gap-2">
                <Button
                    size="sm"
                    variant="outline"
                    onClick={() => setEditing(true)}
                >
                    Edit
                </Button>
                <Button size="sm" variant="destructive" onClick={handleDelete}>
                    Delete
                </Button>
            </TableCell>
        </TableRow>
    );
}

export default function LogModal({ student, children }) {
    const [open, setOpen] = useState(false);

    const { data, setData, errors, processing, handleSubmit } =
        useAddAttendanceEntry(student.id);

    return (
        <>
            <div onClick={() => setOpen(true)}>{children}</div>

            <Dialog open={open} onOpenChange={setOpen}>
                <DialogContent className="sm:max-w-2xl">
                    <DialogHeader>
                        <DialogTitle>
                            Attendance Log — {student.name}
                        </DialogTitle>
                        <DialogDescription>
                            Add a missed entry or correct an existing one
                        </DialogDescription>
                    </DialogHeader>

                    <form
                        onSubmit={handleSubmit}
                        className="grid grid-cols-1 gap-3 sm:grid-cols-4 sm:items-end"
                    >
                        <div className="grid gap-2">
                            <Label>Date</Label>
                            <Input
                                type="date"
                                value={data.date}
                                onChange={(e) =>
                                    setData("date", e.target.value)
                                }
                            />
                            <InputError message={errors.date} />
                        </div>
                        <div className="grid gap-2">
                            <Label>Time In</Label>
                            <Input
                                type="time"
                                value={data.time_in}
                                onChange={(e) =>
                                    setData("time_in", e.target.value)
                                }
                            />
                            <InputError message={errors.time_in} />
                        </div>
                        <div className="grid gap-2">
                            <Label>Time Out</Label>
                            <Input
                                type="time"
                                value={data.time_out}
                                onChange={(e) =>
                                    setData("time_out", e.target.value)
                                }
                            />
                            <InputError message={errors.time_out} />
                        </div>
                        <Button type="submit" disabled={processing}>
                            Add Entry
                        </Button>
                    </form>

                    <div className="max-h-[50vh] overflow-y-auto rounded-md border">
                        <Table>
                            <TableHeader>
                                <TableRow>
                                    <TableHead>Date</TableHead>
                                    <TableHead>Time In</TableHead>
                                    <TableHead>Time Out</TableHead>
                                    <TableHead>Hours</TableHead>
                                    <TableHead>Recorded By</TableHead>
                                    <TableHead>Actions</TableHead>
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                {student.attendances.length ? (
                                    student.attendances.map((attendance) => (
                                        <AttendanceRow
                                            key={attendance.id}
                                            attendance={attendance}
                                            studentUserId={student.user_id}
                                        />
                                    ))
                                ) : (
                                    <TableRow>
                                        <TableCell
                                            colSpan={6}
                                            className="h-24 text-center"
                                        >
                                            No attendance records yet.
                                        </TableCell>
                                    </TableRow>
                                )}
                            </TableBody>
                        </Table>
                    </div>
                </DialogContent>
            </Dialog>
        </>
    );
}
