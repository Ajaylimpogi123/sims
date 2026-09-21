import { usePage } from "@inertiajs/react";
import FilterBar from "./FilterBar";
import InternshipCharts from "./InternshipCharts";
import AttendanceCharts from "./AttendanceCharts";
import EvaluationCharts from "./EvaluationCharts";
import ReportsCharts from "./ReportsCharts";

export default function AnalyticsTab({ roleId, filterOptions }) {
    const { analytics } = usePage().props;

    if (!analytics) {
        return null;
    }

    return (
        <div className="space-y-6">
            <FilterBar
                filters={analytics.filters}
                filterOptions={filterOptions}
                roleId={roleId}
            />

            <div>
                <h2 className="mb-3 text-lg font-semibold text-foreground">
                    Internship
                </h2>
                <InternshipCharts data={analytics.internship} />
            </div>

            <div>
                <h2 className="mb-3 text-lg font-semibold text-foreground">
                    Attendance
                </h2>
                <AttendanceCharts data={analytics.attendance} />
            </div>

            <div>
                <h2 className="mb-3 text-lg font-semibold text-foreground">
                    Evaluations
                </h2>
                <EvaluationCharts data={analytics.evaluation} />
            </div>

            <div>
                <h2 className="mb-3 text-lg font-semibold text-foreground">
                    Reports
                </h2>
                <ReportsCharts data={analytics.reports} />
            </div>
        </div>
    );
}
