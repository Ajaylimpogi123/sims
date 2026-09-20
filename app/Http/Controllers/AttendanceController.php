<?php

namespace App\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Inertia\Response;

class AttendanceController extends Controller
{
    public function index(): Response
    {
        $student = Auth::user()->student;

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

    public function timeIn(): RedirectResponse
    {
        $student = Auth::user()->student;

        $record = $student->attendances()->firstOrNew([
            'date' => today()->toDateString(),
        ]);

        if (in_array($record->time_in_status, ['pending', 'approved'], true)) {
            return redirect()->route('attendance.index')
                ->with('error', 'You already have a time-in request for today.');
        }

        $record->time_in = now()->format('H:i:s');
        $record->time_in_status = 'pending';
        $record->time_in_rejection_reason = null;
        $record->recorded_by = Auth::id();
        $record->save();

        return redirect()->route('attendance.index')
            ->with('success', 'Time-in submitted for approval.');
    }

    public function timeOut(): RedirectResponse
    {
        $student = Auth::user()->student;

        $record = $student->attendances()
            ->where('date', today()->toDateString())
            ->first();

        if (! $record || $record->time_in_status !== 'approved') {
            return redirect()->route('attendance.index')
                ->with('error', 'Your time-in must be approved before you can time out.');
        }

        if (in_array($record->time_out_status, ['pending', 'approved'], true)) {
            return redirect()->route('attendance.index')
                ->with('error', 'You already have a time-out request for today.');
        }

        $record->time_out = now()->format('H:i:s');
        $record->time_out_status = 'pending';
        $record->time_out_rejection_reason = null;
        $record->is_emergency = false;
        $record->note = null;
        $record->recorded_by = Auth::id();
        $record->save();

        return redirect()->route('attendance.index')
            ->with('success', 'Time-out submitted for approval.');
    }

    public function emergencyTimeOut(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'note' => ['required', 'string', 'max:1000'],
        ]);

        $student = Auth::user()->student;

        $record = $student->attendances()->firstOrNew([
            'date' => today()->toDateString(),
        ]);

        if (! $record->exists || empty($record->time_in)) {
            return redirect()->route('attendance.index')
                ->with('error', 'You must time in before using emergency time-out.');
        }

        if (in_array($record->time_out_status, ['pending', 'approved'], true)) {
            return redirect()->route('attendance.index')
                ->with('error', 'You already have a time-out request for today.');
        }

        $record->time_out = now()->format('H:i:s');
        $record->time_out_status = 'pending';
        $record->time_out_rejection_reason = null;
        $record->is_emergency = true;
        $record->note = $validated['note'];
        $record->recorded_by = Auth::id();
        $record->save();

        return redirect()->route('attendance.index')
            ->with('success', 'Emergency time-out submitted for approval.');
    }
}
