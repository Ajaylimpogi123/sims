<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\ValidatesAttendanceMonitoring;
use App\Models\Attendance;
use App\Models\Student;
use App\Services\AttendanceService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Inertia\Response;

class AttendanceMonitoringController extends Controller
{
    use ValidatesAttendanceMonitoring;

    public function __construct(private AttendanceService $attendance) {}

    public function index(): Response
    {
        $students = Student::query()
            ->with([
                'user:id,name',
                'company:id,company_name',
                'attendances' => fn ($query) => $query->orderByDesc('date'),
            ])
            ->monitoredBy(Auth::user())
            ->get();

        return Inertia::render('AttendanceMonitoring/Index', [
            'students' => $students,
        ]);
    }

    public function updateRequiredHours(Request $request, Student $student): RedirectResponse
    {
        $this->authorize('manageAttendance', $student);

        $student->update($this->validateRequiredHours($request));

        return redirect()->route('attendance-monitoring.index')
            ->with('success', 'Required hours updated.');
    }

    public function store(Request $request, Student $student): RedirectResponse
    {
        $this->authorize('manageAttendance', $student);

        $validated = $this->validateAttendanceEntry($request, $student);

        $this->attendance->createEntry($student, Auth::user(), $validated);

        return redirect()->route('attendance-monitoring.index')
            ->with('success', 'Attendance entry added.');
    }

    public function update(Request $request, Attendance $attendance): RedirectResponse
    {
        $this->authorize('update', $attendance);

        $validated = $this->validateAttendanceEntry($request, $attendance->student, $attendance->id);

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
}
