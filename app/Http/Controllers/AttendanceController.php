<?php

namespace App\Http\Controllers;

use App\Models\Attendance;
use App\Models\Student;
use App\Services\NotificationService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response;
use Throwable;

class AttendanceController extends Controller
{
    private const NO_PROFILE_MESSAGE = 'No student profile is linked to your account yet. Please contact your coordinator.';

    /**
     * Private disk: photos are only ever served through
     * AttendancePhotoController, which enforces role scoping.
     */
    public const PHOTO_DISK = 'local';

    /** Largest value the decimal(8,2) *_accuracy columns can hold. */
    private const MAX_ACCURACY = 999999.99;

    public function __construct(private NotificationService $notifications) {}

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

        $validated = $this->validateCapture($request);

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

        $this->saveWithEvidence($record, 'time_in', $student, $request, $validated);

        $this->notifications->attendanceSubmitted($record, 'time_in');

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

        $validated = $this->validateCapture($request);

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

        $this->saveWithEvidence($record, 'time_out', $student, $request, $validated);

        $this->notifications->attendanceSubmitted($record, 'time_out');

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

        $validated = $this->validateCapture($request, [
            'note' => ['required', 'string', 'max:1000'],
        ]);

        $record = $student->attendances()->firstOrNew([
            'date' => today()->toDateString(),
        ]);

        if (! $record->exists || empty($record->time_in)) {
            return redirect()->route('attendance.index')
                ->with('error', 'You must time in before using emergency time-out.');
        }

        if ($record->time_in_status === 'rejected') {
            return redirect()->route('attendance.index')
                ->with('error', 'Your time-in was rejected. Emergency time-out requires a non-rejected time-in.');
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

        $this->saveWithEvidence($record, 'time_out', $student, $request, $validated);

        $this->notifications->attendanceSubmitted($record, 'time_out');

        return redirect()->route('attendance.index')
            ->with('success', 'Emergency time-out submitted for approval.');
    }

    /**
     * Live camera photo + device GPS are required on every self-service leg.
     */
    private function validateCapture(Request $request, array $extra = []): array
    {
        return $request->validate(array_merge([
            'photo' => ['required', 'image', 'mimes:jpg,jpeg,png,webp', 'max:5120'],
            'latitude' => ['required', 'numeric', 'between:-90,90'],
            'longitude' => ['required', 'numeric', 'between:-180,180'],
            'accuracy' => ['nullable', 'numeric', 'min:0'],
        ], $extra));
    }

    /**
     * The browser forwards the device's accuracy unchanged, so it can exceed
     * the decimal(8,2) column. Rejecting it would block that device on every
     * retry, so it is clamped instead: 999999.99 m still reads as "useless
     * fix" to a reviewer. Clamp before rounding so 999999.995 can't round up
     * past the column max.
     */
    private function clampAccuracy(mixed $accuracy): ?float
    {
        if ($accuracy === null) {
            return null;
        }

        return round(min((float) $accuracy, self::MAX_ACCURACY), 2);
    }

    /**
     * Store the photo for this leg, write its coordinates, and save the
     * record. A photo previously stored for the same leg (re-submission
     * after a rejection) is deleted only once the new one is persisted.
     */
    private function saveWithEvidence(Attendance $record, string $leg, Student $student, Request $request, array $validated): void
    {
        $oldPath = $record->{"{$leg}_photo_path"};
        $newPath = $request->file('photo')->store("attendance-photos/{$student->id}", self::PHOTO_DISK);

        $record->{"{$leg}_photo_path"} = $newPath;
        $record->{"{$leg}_latitude"} = $validated['latitude'];
        $record->{"{$leg}_longitude"} = $validated['longitude'];
        $record->{"{$leg}_accuracy"} = $this->clampAccuracy($validated['accuracy'] ?? null);

        try {
            $record->save();
        } catch (Throwable $e) {
            Storage::disk(self::PHOTO_DISK)->delete($newPath);

            throw $e;
        }

        if ($oldPath && $oldPath !== $newPath) {
            Storage::disk(self::PHOTO_DISK)->delete($oldPath);
        }
    }
}
