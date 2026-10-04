<?php

namespace App\Http\Controllers\Concerns;

use App\Models\Student;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Attendance Monitoring input rules, shared by the website's
 * AttendanceMonitoringController and the mobile API's MonitoringController
 * so both accept exactly the same entries and required hours.
 */
trait ValidatesAttendanceMonitoring
{
    /** students.required_hours is an unsigned INT. */
    public const MAX_REQUIRED_HOURS = 4294967295;

    /**
     * Earliest entry date. Together with "not after today" (app timezone)
     * this keeps typos like year 0999 / 9999 and future days out of the
     * rendered-hours totals.
     */
    public const MIN_ENTRY_DATE = '2000-01-01';

    /**
     * A manual entry: the date (unique per student) and optional H:i times.
     * An empty time means "no time" for that leg (clearing it on an edit).
     *
     * @return array{date: string, time_in: ?string, time_out: ?string}
     */
    protected function validateAttendanceEntry(Request $request, Student $student, ?int $ignoreId = null): array
    {
        $request->merge([
            'time_in' => $request->input('time_in') ?: null,
            'time_out' => $request->input('time_out') ?: null,
        ]);

        return $request->validate([
            'date' => [
                'required',
                'date_format:Y-m-d',
                'after_or_equal:'.self::MIN_ENTRY_DATE,
                'before_or_equal:'.today()->toDateString(),
                Rule::unique('attendances', 'date')
                    ->where('student_id', $student->id)
                    ->ignore($ignoreId),
            ],
            'time_in' => ['nullable', 'date_format:H:i'],
            'time_out' => ['nullable', 'date_format:H:i', 'after:time_in'],
        ], [
            'date.after_or_equal' => 'The date must be on or after January 1, 2000.',
            'date.before_or_equal' => 'The date cannot be in the future.',
        ]);
    }

    /**
     * Required hours: a whole number, or empty / 0 for "not set".
     *
     * @return array{required_hours: ?int}
     */
    protected function validateRequiredHours(Request $request): array
    {
        $request->merge(['required_hours' => $request->input('required_hours') ?: null]);

        return $request->validate([
            'required_hours' => ['nullable', 'numeric', 'integer', 'min:0', 'max:'.self::MAX_REQUIRED_HOURS],
        ]);
    }
}
