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

// Fallback when the page doesn't send `semesters`.
export const DEFAULT_SEMESTERS = [
    { value: "first", label: "1st Semester" },
    { value: "second", label: "2nd Semester" },
    { value: "summer", label: "Summer" },
];

export function schoolYearLabel(start) {
    const year = Number(start);
    if (!Number.isInteger(year) || year < 1000 || year > 9999) return null;
    return `SY ${year}-${year + 1}`;
}

export default function TermFormFields({
    idPrefix,
    data,
    setData,
    errors,
    semesters,
}) {
    const options = semesters?.length ? semesters : DEFAULT_SEMESTERS;
    const syLabel = schoolYearLabel(data.school_year_start);

    return (
        <div className="grid gap-4 max-h-[65vh] overflow-y-auto pr-1 py-2">
            <div className="grid gap-3">
                <Label htmlFor={`${idPrefix}-school-year`}>
                    School Year (starting year)
                </Label>
                <Input
                    id={`${idPrefix}-school-year`}
                    type="number"
                    min="2000"
                    max="2100"
                    step="1"
                    inputMode="numeric"
                    value={data.school_year_start}
                    onChange={(e) =>
                        setData("school_year_start", e.target.value)
                    }
                />
                <p className="text-xs text-muted-foreground">
                    {syLabel ?? "Enter the year the school year starts"}
                </p>
                <InputError message={errors.school_year_start} />
            </div>

            <div className="grid gap-3">
                <Label>Semester</Label>
                <Select
                    value={data.semester}
                    onValueChange={(value) => setData("semester", value)}
                >
                    <SelectTrigger>
                        <SelectValue placeholder="Select semester" />
                    </SelectTrigger>
                    <SelectContent>
                        {options.map((option) => (
                            <SelectItem key={option.value} value={option.value}>
                                {option.label}
                            </SelectItem>
                        ))}
                    </SelectContent>
                </Select>
                <InputError message={errors.semester} />
            </div>

            <div className="grid grid-cols-1 gap-3 sm:grid-cols-2">
                <div className="grid gap-3">
                    <Label htmlFor={`${idPrefix}-starts-on`}>
                        Starts on (optional)
                    </Label>
                    <Input
                        id={`${idPrefix}-starts-on`}
                        type="date"
                        value={data.starts_on}
                        onChange={(e) => setData("starts_on", e.target.value)}
                    />
                    <InputError message={errors.starts_on} />
                </div>
                <div className="grid gap-3">
                    <Label htmlFor={`${idPrefix}-ends-on`}>
                        Ends on (optional)
                    </Label>
                    <Input
                        id={`${idPrefix}-ends-on`}
                        type="date"
                        min={data.starts_on || undefined}
                        value={data.ends_on}
                        onChange={(e) => setData("ends_on", e.target.value)}
                    />
                    <InputError message={errors.ends_on} />
                </div>
            </div>
        </div>
    );
}
