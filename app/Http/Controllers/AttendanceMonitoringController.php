<?php

namespace App\Http\Controllers;

use App\Models\Attendance;
use App\Models\Student;
use Carbon\Carbon;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class AttendanceMonitoringController extends Controller
{
    public function index(): Response
    {
        $students = Student::query()
            ->with([
                'user:id,name',
                'company:id,company_name',
                'attendances' => fn ($query) => $query->orderByDesc('date'),
            ])
            ->withSum('attendances as total_rendered_hours', 'rendered_hours')
            ->when(
                Auth::user()->role_id === 3,
                fn ($query) => $query->where('supervisor_id', Auth::id()),
            )
            ->orderBy('created_at', 'desc')
            ->get();

        return Inertia::render('AttendanceMonitoring/Index', [
            'students' => $students,
        ]);
    }

    public function updateRequiredHours(Request $request, Student $student): RedirectResponse
    {
        $this->authorizeSupervisedStudent($student);

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
        $this->authorizeSupervisedStudent($student);

        $validated = $this->validateEntry($request, $student);

        $validated['rendered_hours'] = $this->computeRenderedHours($validated);
        $validated['recorded_by'] = Auth::id();
        $validated['time_in_status'] = $validated['time_in'] ? 'approved' : null;
        $validated['time_out_status'] = $validated['time_out'] ? 'approved' : null;

        $student->attendances()->create($validated);

        return redirect()->route('attendance-monitoring.index')
            ->with('success', 'Attendance entry added.');
    }

    public function update(Request $request, Attendance $attendance): RedirectResponse
    {
        $this->authorizeSupervisedStudent($attendance->student);

        $validated = $this->validateEntry($request, $attendance->student, $attendance->id);

        $validated['rendered_hours'] = $this->computeRenderedHours($validated);
        $validated['recorded_by'] = Auth::id();
        $validated['time_in_status'] = $validated['time_in'] ? 'approved' : null;
        $validated['time_out_status'] = $validated['time_out'] ? 'approved' : null;

        $attendance->update($validated);

        return redirect()->route('attendance-monitoring.index')
            ->with('success', 'Attendance entry updated.');
    }

    public function destroy(Attendance $attendance): RedirectResponse
    {
        $this->authorizeSupervisedStudent($attendance->student);

        $attendance->delete();

        return redirect()->route('attendance-monitoring.index')
            ->with('success', 'Attendance entry deleted.');
    }

    private function authorizeSupervisedStudent(Student $student): void
    {
        if (Auth::user()->role_id === 3) {
            abort_unless($student->supervisor_id === Auth::id(), 403);
        }
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

    private function computeRenderedHours(array $validated): ?float
    {
        if (empty($validated['time_in']) || empty($validated['time_out'])) {
            return null;
        }

        $timeIn = Carbon::parse($validated['date'].' '.$validated['time_in']);
        $timeOut = Carbon::parse($validated['date'].' '.$validated['time_out']);

        return round($timeOut->diffInMinutes($timeIn) / 60, 2);
    }
}
