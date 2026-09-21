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
import CriteriaRatingGroup from "./CriteriaRatingGroup";

const TEXT_FIELDS = [
    { key: "strengths", label: "Strengths" },
    { key: "areas_for_improvement", label: "Areas for Improvement" },
    { key: "recommendations", label: "Recommendations" },
    { key: "supervisor_remarks", label: "Supervisor Remarks" },
];

export default function EvaluationFormFields({
    students,
    data,
    setData,
    responses,
    setResponse,
    criteria,
    errors,
    averageRating,
}) {
    return (
        <div className="grid gap-6">
            {students && (
                <div className="grid gap-2">
                    <Label>Student</Label>
                    <Select
                        value={data.student_id ? String(data.student_id) : ""}
                        onValueChange={(value) =>
                            setData("student_id", value)
                        }
                    >
                        <SelectTrigger>
                            <SelectValue placeholder="Select a student" />
                        </SelectTrigger>
                        <SelectContent>
                            {students.map((student) => (
                                <SelectItem
                                    key={student.id}
                                    value={String(student.id)}
                                >
                                    {student.user?.name} (
                                    {student.student_number})
                                </SelectItem>
                            ))}
                        </SelectContent>
                    </Select>
                    <InputError message={errors.student_id} />
                </div>
            )}

            <div className="grid gap-4 sm:grid-cols-2">
                <div className="grid gap-2">
                    <Label>Evaluation Period Start</Label>
                    <Input
                        type="date"
                        value={data.evaluation_period_start}
                        onChange={(e) =>
                            setData(
                                "evaluation_period_start",
                                e.target.value,
                            )
                        }
                    />
                    <InputError message={errors.evaluation_period_start} />
                </div>

                <div className="grid gap-2">
                    <Label>Evaluation Period End</Label>
                    <Input
                        type="date"
                        value={data.evaluation_period_end}
                        onChange={(e) =>
                            setData("evaluation_period_end", e.target.value)
                        }
                    />
                    <InputError message={errors.evaluation_period_end} />
                </div>
            </div>

            <div className="rounded-md border bg-muted/20 p-3 text-sm">
                Overall Rating (auto-computed from criteria ratings):{" "}
                <span className="font-semibold">
                    {averageRating ?? "—"}
                </span>
                /5
            </div>

            <CriteriaRatingGroup
                criteria={criteria}
                responses={responses}
                onChange={setResponse}
                errors={errors}
            />

            <div className="grid gap-4 sm:grid-cols-2">
                {TEXT_FIELDS.map((field) => (
                    <div key={field.key} className="grid gap-2">
                        <Label>{field.label}</Label>
                        <textarea
                            rows={3}
                            value={data[field.key]}
                            onChange={(e) =>
                                setData(field.key, e.target.value)
                            }
                            className="flex w-full rounded-md border border-input bg-transparent px-3 py-2 text-sm shadow-sm placeholder:text-muted-foreground focus-visible:outline-none focus-visible:ring-1 focus-visible:ring-ring disabled:cursor-not-allowed disabled:opacity-50"
                        />
                        <InputError message={errors[field.key]} />
                    </div>
                ))}
            </div>
        </div>
    );
}
