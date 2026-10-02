<?php

namespace App\Http\Controllers;

use App\Models\Attendance;
use App\Services\AttendanceService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Inertia\Response;

class AttendanceApprovalController extends Controller
{
    public function __construct(private AttendanceService $attendance) {}

    public function index(): Response
    {
        $attendances = Attendance::query()
            ->with('student.user:id,name')
            ->where(fn ($query) => $query
                ->where('time_in_status', 'pending')
                ->orWhere('time_out_status', 'pending'))
            ->visibleTo(Auth::user())
            ->orderBy('date')
            ->get();

        return Inertia::render('AttendanceApprovals/Index', [
            'attendances' => $attendances,
        ]);
    }

    public function approveTimeIn(Attendance $attendance): RedirectResponse
    {
        $this->authorize('review', $attendance);

        $this->attendance->approveTimeIn($attendance);

        return redirect()->route('attendance-approvals.index')
            ->with('success', 'Time-in approved.');
    }

    public function rejectTimeIn(Request $request, Attendance $attendance): RedirectResponse
    {
        $this->authorize('review', $attendance);

        $validated = $request->validate(AttendanceService::rejectionRules());

        $this->attendance->rejectTimeIn($attendance, $validated['reason'] ?? null);

        return redirect()->route('attendance-approvals.index')
            ->with('success', 'Time-in rejected.');
    }

    public function approveTimeOut(Attendance $attendance): RedirectResponse
    {
        $this->authorize('review', $attendance);

        $this->attendance->approveTimeOut($attendance);

        return redirect()->route('attendance-approvals.index')
            ->with('success', 'Time-out approved.');
    }

    public function rejectTimeOut(Request $request, Attendance $attendance): RedirectResponse
    {
        $this->authorize('review', $attendance);

        $validated = $request->validate(AttendanceService::rejectionRules());

        $this->attendance->rejectTimeOut($attendance, $validated['reason'] ?? null);

        return redirect()->route('attendance-approvals.index')
            ->with('success', 'Time-out rejected.');
    }
}
