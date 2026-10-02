<?php

namespace App\Http\Controllers;

use App\Models\Attendance;
use App\Models\Student;
use App\Services\AttendanceService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class AttendanceMonitoringController extends Controller
{
    public function __construct(private AttendanceService $attendance) {}

    public function index(): Response
    {
        $students = Student::query()
            ->with([
                'user:id,name',
                'company:id,company_name',
                'attendances' => fn ($query) => $query->orderByDesc('date'),
            ])
            ->withSum('attendances as total_rendered_hours', 'rendered_hours')
            ->visibleTo(Auth::user())
            ->orderBy('created_at', 'desc')
            ->get();

        return Inertia::render('AttendanceMonitoring/Index', [
            'students' => $students,
        ]);
    }

    public function updateRequiredHours(Request $request, Student $student): RedirectResponse
    {
        $this->authorize('manageAttendance', $student);

        $request->merge(['required_hours' => $request->required_hours ?: null]);

        $validated = $request->validate([
            'required_hours' => ['nullable', 'integer', 'min:0'],
        ]);

        $student->update($validated);

        return redirect()->route('attendance-monitoring.index')
            ->with('success', 'Required hours updated.');
    }

    public function store(Request $request, Student $student): RedirectResponse
    {
        $this->authorize('manageAttendance', $student);

        $validated = $this->validateEntry($request, $student);

        $this->attendance->createEntry($student, Auth::user(), $validated);

        return redirect()->route('attendance-monitoring.index')
            ->with('success', 'Attendance entry added.');
    }

    public function update(Request $request, Attendance $attendance): RedirectResponse
    {
        $this->authorize('update', $attendance);

        $validated = $this->validateEntry($request, $attendance->student, $attendance->id);

        $this->attendance->updateEntry($attendance, Auth::user(), $validated);

        return redirect()->route('attendance-monitoring.index')
            ->with('success', 'Attendance entry updated.');
    }

    public function destroy(Attendance $attendance): RedirectResponse
    {
        $this->authorize('delete', $attendance);

        $this->attendance->deleteEntry($attendance);

        return redirect()->route('attendance-monitoring.index')
            ->with('success', 'Attendance entry deleted.');
    }

    private function validateEntry(Request $request, Student $student, ?int $ignoreId = null): array
    {
        $request->merge([
            'time_in' => $request->time_in ?: null,
            'time_out' => $request->time_out ?: null,
        ]);

        return $request->validate([
            'date' => [
                'required',
                'date',
                Rule::unique('attendances', 'date')
                    ->where('student_id', $student->id)
                    ->ignore($ignoreId),
            ],
            'time_in' => ['nullable', 'date_format:H:i'],
            'time_out' => ['nullable', 'date_format:H:i', 'after:time_in'],
        ]);
    }
}
