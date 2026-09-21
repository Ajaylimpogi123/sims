<?php

namespace App\Http\Controllers;

use App\Models\Attendance;
use App\Models\Student;
use App\Services\NotificationService;
use Carbon\Carbon;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Inertia\Response;

class AttendanceApprovalController extends Controller
{
    public function __construct(private NotificationService $notifications) {}

    public function index(): Response
    {
        $attendances = Attendance::query()
            ->with('student.user:id,name')
            ->where(fn ($query) => $query
                ->where('time_in_status', 'pending')
                ->orWhere('time_out_status', 'pending'))
            ->when(
                Auth::user()->role_id === 3,
                fn ($query) => $query->whereHas(
                    'student',
                    fn ($q) => $q->where('supervisor_id', Auth::id()),
                ),
            )
            ->orderBy('date')
            ->get();

        return Inertia::render('AttendanceApprovals/Index', [
            'attendances' => $attendances,
        ]);
    }

    public function approveTimeIn(Attendance $attendance): RedirectResponse
    {
        $this->authorizeSupervisedStudent($attendance->student);

        $attendance->update([
            'time_in_status' => 'approved',
            'time_in_rejection_reason' => null,
        ]);

        $this->notifications->attendanceReviewed($attendance, 'time_in', 'approved');

        return redirect()->route('attendance-approvals.index')
            ->with('success', 'Time-in approved.');
    }

    public function rejectTimeIn(Request $request, Attendance $attendance): RedirectResponse
    {
        $this->authorizeSupervisedStudent($attendance->student);

        $validated = $request->validate([
            'reason' => ['nullable', 'string', 'max:500'],
        ]);

        $attendance->update([
            'time_in_status' => 'rejected',
            'time_in_rejection_reason' => $validated['reason'] ?? null,
        ]);

        $this->notifications->attendanceReviewed($attendance, 'time_in', 'rejected', $validated['reason'] ?? null);

        return redirect()->route('attendance-approvals.index')
            ->with('success', 'Time-in rejected.');
    }

    public function approveTimeOut(Attendance $attendance): RedirectResponse
    {
        $this->authorizeSupervisedStudent($attendance->student);

        $renderedHours = null;

        if ($attendance->time_in && $attendance->time_in_status === 'approved' && $attendance->time_out) {
            $timeIn = Carbon::parse($attendance->date->format('Y-m-d').' '.$attendance->time_in);
            $timeOut = Carbon::parse($attendance->date->format('Y-m-d').' '.$attendance->time_out);
            $renderedHours = round($timeOut->diffInMinutes($timeIn) / 60, 2);
        }

        $attendance->update([
            'time_out_status' => 'approved',
            'time_out_rejection_reason' => null,
            'rendered_hours' => $renderedHours,
        ]);

        $this->notifications->attendanceReviewed($attendance, 'time_out', 'approved');

        return redirect()->route('attendance-approvals.index')
            ->with('success', 'Time-out approved.');
    }

    public function rejectTimeOut(Request $request, Attendance $attendance): RedirectResponse
    {
        $this->authorizeSupervisedStudent($attendance->student);

        $validated = $request->validate([
            'reason' => ['nullable', 'string', 'max:500'],
        ]);

        $attendance->update([
            'time_out_status' => 'rejected',
            'time_out_rejection_reason' => $validated['reason'] ?? null,
        ]);

        $this->notifications->attendanceReviewed($attendance, 'time_out', 'rejected', $validated['reason'] ?? null);

        return redirect()->route('attendance-approvals.index')
            ->with('success', 'Time-out rejected.');
    }

    private function authorizeSupervisedStudent(Student $student): void
    {
        if (Auth::user()->role_id === 3) {
            abort_unless($student->supervisor_id === Auth::id(), 403);
        }
    }
}
