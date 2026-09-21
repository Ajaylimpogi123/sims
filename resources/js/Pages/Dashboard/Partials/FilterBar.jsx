import { format } from "date-fns";
import InputLabel from "@/Components/InputLabel";
import { Button } from "@/components/ui/button";
import { DateRangePicker } from "@/Components/date-range-picker";
import useAnalyticsFilters from "../Hooks/useAnalyticsFilters";

export default function FilterBar({ filters, filterOptions, roleId }) {
    const { data, setData, apply, clear } = useAnalyticsFilters(filters);

    const dateRange = {
        from: data.date_from ? new Date(`${data.date_from}T00:00:00`) : undefined,
        to: data.date_to ? new Date(`${data.date_to}T00:00:00`) : undefined,
    };

    const handleDateChange = (range) => {
        const date_from = range?.from ? format(range.from, "yyyy-MM-dd") : "";
        const date_to = range?.to ? format(range.to, "yyyy-MM-dd") : "";
        apply({ date_from, date_to });
    };

    const hasActiveFilters =
        data.date_from ||
        data.date_to ||
        data.company_id ||
        data.supervisor_id ||
        data.student_id;

    return (
        <div className="flex flex-wrap items-end gap-3 rounded-lg border bg-white p-4">
            <div>
                <InputLabel value="Date Range" />
                <DateRangePicker date={dateRange} setDate={handleDateChange} />
            </div>

            <div>
                <InputLabel htmlFor="filter_company" value="Company" />
                <select
                    id="filter_company"
                    value={data.company_id}
                    className="mt-1 block w-full min-w-[10rem] rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500"
                    onChange={(e) => {
                        setData((prev) => ({ ...prev, company_id: e.target.value }));
                        apply({ company_id: e.target.value });
                    }}
                >
                    <option value="">All Companies</option>
                    {filterOptions.companies.map((company) => (
                        <option key={company.id} value={company.id}>
                            {company.company_name}
                        </option>
                    ))}
                </select>
            </div>

            {roleId !== 3 && (
                <div>
                    <InputLabel htmlFor="filter_supervisor" value="Supervisor" />
                    <select
                        id="filter_supervisor"
                        value={data.supervisor_id}
                        className="mt-1 block w-full min-w-[10rem] rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500"
                        onChange={(e) => {
                            setData((prev) => ({
                                ...prev,
                                supervisor_id: e.target.value,
                            }));
                            apply({ supervisor_id: e.target.value });
                        }}
                    >
                        <option value="">All Supervisors</option>
                        {filterOptions.supervisors.map((supervisor) => (
                            <option key={supervisor.id} value={supervisor.id}>
                                {supervisor.name}
                            </option>
                        ))}
                    </select>
                </div>
            )}

            <div>
                <InputLabel htmlFor="filter_student" value="Student" />
                <select
                    id="filter_student"
                    value={data.student_id}
                    className="mt-1 block w-full min-w-[10rem] rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500"
                    onChange={(e) => {
                        setData((prev) => ({ ...prev, student_id: e.target.value }));
                        apply({ student_id: e.target.value });
                    }}
                >
                    <option value="">All Students</option>
                    {filterOptions.students.map((student) => (
                        <option key={student.id} value={student.id}>
                            {student.user?.name ?? student.student_number}
                        </option>
                    ))}
                </select>
            </div>

            {hasActiveFilters && (
                <Button type="button" variant="outline" onClick={clear}>
                    Clear Filters
                </Button>
            )}
        </div>
    );
}
