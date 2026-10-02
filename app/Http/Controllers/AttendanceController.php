<?php

namespace App\Http\Controllers;

use App\Exceptions\AttendanceRuleException;
use App\Services\AttendanceService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Inertia\Response;

class AttendanceController extends Controller
{
    private const NO_PROFILE_MESSAGE = 'No student profile is linked to your account yet. Please contact your coordinator.';

    public function __construct(private AttendanceService $attendance) {}

    public function index(): Response|RedirectResponse
    {
        $student = Auth::user()->student;

        if (! $student) {
            return redirect()->route('dashboard')
                ->with('error', self::NO_PROFILE_MESSAGE);
        }

        $attendances = $student->attendances()
            ->orderByDesc('date')
            ->get();

        $today = $attendances->first(
            fn ($attendance) => $attendance->date->format('Y-m-d') === today()->toDateString()
        );

        return Inertia::render('Attendance/Index', [
            'student' => $student,
            'attendances' => $attendances,
            'totalRenderedHours' => (float) $attendances->sum('rendered_hours'),
            'todayRecord' => $today,
        ]);
    }

    public function timeIn(Request $request): RedirectResponse
    {
        $student = Auth::user()->student;

        if (! $student) {
            return redirect()->route('dashboard')
                ->with('error', self::NO_PROFILE_MESSAGE);
        }

        $validated = $request->validate(AttendanceService::captureRules());

        try {
            $this->attendance->timeIn($student, Auth::user(), $request->file('photo'), $validated);
        } catch (AttendanceRuleException $e) {
            return $this->refused($e);
        }

        return redirect()->route('attendance.index')
            ->with('success', 'Time-in submitted for approval.');
    }

    public function timeOut(Request $request): RedirectResponse
    {
        $student = Auth::user()->student;

        if (! $student) {
            return redirect()->route('dashboard')
                ->with('error', self::NO_PROFILE_MESSAGE);
        }

        $validated = $request->validate(AttendanceService::captureRules());

        try {
            $this->attendance->timeOut($student, Auth::user(), $request->file('photo'), $validated);
        } catch (AttendanceRuleException $e) {
            return $this->refused($e);
        }

        return redirect()->route('attendance.index')
            ->with('success', 'Time-out submitted for approval.');
    }

    public function emergencyTimeOut(Request $request): RedirectResponse
    {
        $student = Auth::user()->student;

        if (! $student) {
            return redirect()->route('dashboard')
                ->with('error', self::NO_PROFILE_MESSAGE);
        }

        $validated = $request->validate(AttendanceService::emergencyTimeOutRules());

        try {
            $this->attendance->emergencyTimeOut($student, Auth::user(), $request->file('photo'), $validated);
        } catch (AttendanceRuleException $e) {
            return $this->refused($e);
        }

        return redirect()->route('attendance.index')
            ->with('success', 'Emergency time-out submitted for approval.');
    }

    private function refused(AttendanceRuleException $e): RedirectResponse
    {
        return redirect()->route('attendance.index')
            ->with('error', $e->getMessage());
    }
}
