<?php

namespace App\Http\Controllers\Concerns;

use App\Models\Student;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Attendance Monitoring input rules, shared by the website's
 * AttendanceMonitoringController and the mobile API's MonitoringController
 * so both accept exactly the same entries and required hours.
 */
trait ValidatesAttendanceMonitoring
{
    /** students.required_hours is an unsigned INT. */
    public const MAX_REQUIRED_HOURS = 4294967295;

    public const REQUIRED_HOURS_LOCKED_MESSAGE = 'Required hours can\'t be cleared while the internship is Ongoing.';

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
                'bail',
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

    /**
     * Validate and save the student's required hours. While the internship
     * is ongoing the target can't be cleared (marking a student Ongoing
     * needs one; see InternshipAssignmentService). The status is read under
     * the student's row lock, the same row an assignment save locks, so a
     * concurrent status change can't slip in between.
     *
     * @throws ValidationException
     * @throws ModelNotFoundException the student was deleted meanwhile
     */
    protected function saveRequiredHours(Request $request, Student $student): Student
    {
        $requiredHours = $this->validateRequiredHours($request)['required_hours'];

        DB::transaction(function () use ($student, $requiredHours) {
            $current = Student::query()->lockForUpdate()->findOrFail($student->id);

            if ($requiredHours === null && $current->internship_status === Student::SUBMITTING_STATUS) {
                throw ValidationException::withMessages([
                    'required_hours' => self::REQUIRED_HOURS_LOCKED_MESSAGE,
                ]);
            }

            $current->update(['required_hours' => $requiredHours]);
            $student->setRawAttributes($current->getAttributes(), true);
        });

        return $student;
    }
}
