<?php

namespace App\Http\Controllers;

use App\Exceptions\AttendanceRuleException;
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
            ->awaitingReview()
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

        return $this->decide(fn () => $this->attendance->approveTimeIn($attendance), 'Time-in approved.');
    }

    public function rejectTimeIn(Request $request, Attendance $attendance): RedirectResponse
    {
        $this->authorize('review', $attendance);

        $validated = $request->validate(AttendanceService::rejectionRules());

        return $this->decide(
            fn () => $this->attendance->rejectTimeIn($attendance, $validated['reason'] ?? null),
            'Time-in rejected.',
        );
    }

    public function approveTimeOut(Attendance $attendance): RedirectResponse
    {
        $this->authorize('review', $attendance);

        return $this->decide(fn () => $this->attendance->approveTimeOut($attendance), 'Time-out approved.');
    }

    public function rejectTimeOut(Request $request, Attendance $attendance): RedirectResponse
    {
        $this->authorize('review', $attendance);

        $validated = $request->validate(AttendanceService::rejectionRules());

        return $this->decide(
            fn () => $this->attendance->rejectTimeOut($attendance, $validated['reason'] ?? null),
            'Time-out rejected.',
        );
    }

    /**
     * A leg that is no longer pending (already decided, or a stale page)
     * comes back as a flash error instead of overwriting the decision.
     */
    private function decide(callable $action, string $success): RedirectResponse
    {
        try {
            $action();
        } catch (AttendanceRuleException $e) {
            return redirect()->route('attendance-approvals.index')->with('error', $e->getMessage());
        }

        return redirect()->route('attendance-approvals.index')->with('success', $success);
    }
}
