<?php

namespace App\Services;

use App\Exceptions\AttendanceRuleException;
use App\Models\Attendance;
use App\Models\Student;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Attendance write logic shared by the website and the mobile API:
 * student self-service legs (time-in, time-out, emergency time-out) with
 * their photo + GPS evidence, supervisor approve / reject, and the
 * Attendance Monitoring entry edits.
 *
 * Authorization is the caller's job (AttendancePolicy / StudentPolicy);
 * this class enforces the state rules and owns the evidence files.
 */
class AttendanceService
{
    /**
     * Private disk: photos are only ever served through
     * AttendancePhotoController, which enforces role scoping.
     */
    public const PHOTO_DISK = 'local';

    /** Largest value the decimal(8,2) *_accuracy columns can hold. */
    public const MAX_ACCURACY = 999999.99;

    public function __construct(private NotificationService $notifications) {}

    /**
     * Live camera photo + device GPS are required on every self-service leg.
     */
    public static function captureRules(): array
    {
        return [
            'photo' => ['required', 'image', 'mimes:jpg,jpeg,png,webp', 'max:5120'],
            'latitude' => ['required', 'numeric', 'between:-90,90'],
            'longitude' => ['required', 'numeric', 'between:-180,180'],
            'accuracy' => ['nullable', 'numeric', 'min:0'],
        ];
    }

    public static function emergencyTimeOutRules(): array
    {
        return array_merge(self::captureRules(), [
            'note' => ['required', 'string', 'max:1000'],
        ]);
    }

    public static function rejectionRules(): array
    {
        return [
            'reason' => ['nullable', 'string', 'max:500'],
        ];
    }

    /**
     * The student's attendance row for today (app timezone), if any.
     */
    public function todayRecord(Student $student): ?Attendance
    {
        return $student->attendances()
            ->where('date', today()->toDateString())
            ->first();
    }

    /**
     * Why each self-service action is currently refused for today's row
     * (null = allowed). The same checks the actions below throw on, so the
     * mobile app's "what can I do now" never drifts from what is enforced.
     *
     * @return array{time_in: string|null, time_out: string|null, emergency_time_out: string|null}
     */
    public static function refusals(?Attendance $today): array
    {
        return [
            'time_in' => self::timeInRefusal($today),
            'time_out' => self::timeOutRefusal($today),
            'emergency_time_out' => self::emergencyTimeOutRefusal($today),
        ];
    }

    public static function timeInRefusal(?Attendance $today): ?string
    {
        if (in_array($today?->time_in_status, ['pending', 'approved'], true)) {
            return 'You already have a time-in request for today.';
        }

        return null;
    }

    public static function timeOutRefusal(?Attendance $today): ?string
    {
        if (! $today || $today->time_in_status !== 'approved') {
            return 'Your time-in must be approved before you can time out.';
        }

        if (in_array($today->time_out_status, ['pending', 'approved'], true)) {
            return 'You already have a time-out request for today.';
        }

        return null;
    }

    public static function emergencyTimeOutRefusal(?Attendance $today): ?string
    {
        if (! $today?->exists || empty($today->time_in)) {
            return 'You must time in before using emergency time-out.';
        }

        if ($today->time_in_status === 'rejected') {
            return 'Your time-in was rejected. Emergency time-out requires a non-rejected time-in.';
        }

        if (in_array($today->time_out_status, ['pending', 'approved'], true)) {
            return 'You already have a time-out request for today.';
        }

        return null;
    }

    /**
     * @param  array{latitude: mixed, longitude: mixed, accuracy?: mixed, mocked?: bool|null}  $capture  validated captureRules() data
     *
     * @throws AttendanceRuleException
     */
    public function timeIn(Student $student, User $actor, UploadedFile $photo, array $capture): Attendance
    {
        $record = $student->attendances()->firstOrNew([
            'date' => today()->toDateString(),
        ]);

        if ($reason = self::timeInRefusal($record)) {
            throw new AttendanceRuleException($reason);
        }

        $record->time_in = now()->format('H:i:s');
        $record->time_in_status = 'pending';
        $record->time_in_rejection_reason = null;
        $record->recorded_by = $actor->id;

        $this->saveWithEvidence($record, 'time_in', $student, $photo, $capture);

        $this->notifications->attendanceSubmitted($record, 'time_in');

        return $record;
    }

    /**
     * @param  array{latitude: mixed, longitude: mixed, accuracy?: mixed, mocked?: bool|null}  $capture  validated captureRules() data
     *
     * @throws AttendanceRuleException
     */
    public function timeOut(Student $student, User $actor, UploadedFile $photo, array $capture): Attendance
    {
        $record = $this->todayRecord($student);

        if ($reason = self::timeOutRefusal($record)) {
            throw new AttendanceRuleException($reason);
        }

        $record->time_out = now()->format('H:i:s');
        $record->time_out_status = 'pending';
        $record->time_out_rejection_reason = null;
        $record->is_emergency = false;
        $record->note = null;
        $record->recorded_by = $actor->id;

        $this->saveWithEvidence($record, 'time_out', $student, $photo, $capture);

        $this->notifications->attendanceSubmitted($record, 'time_out');

        return $record;
    }

    /**
     * @param  array{latitude: mixed, longitude: mixed, accuracy?: mixed, mocked?: bool|null, note: string}  $capture  validated emergencyTimeOutRules() data
     *
     * @throws AttendanceRuleException
     */
    public function emergencyTimeOut(Student $student, User $actor, UploadedFile $photo, array $capture): Attendance
    {
        $record = $this->todayRecord($student);

        if ($reason = self::emergencyTimeOutRefusal($record)) {
            throw new AttendanceRuleException($reason);
        }

        $record->time_out = now()->format('H:i:s');
        $record->time_out_status = 'pending';
        $record->time_out_rejection_reason = null;
        $record->is_emergency = true;
        $record->note = $capture['note'];
        $record->recorded_by = $actor->id;

        $this->saveWithEvidence($record, 'time_out', $student, $photo, $capture);

        $this->notifications->attendanceSubmitted($record, 'time_out');

        return $record;
    }

    /**
     * Supervisor / Admin review of a submitted leg. Each action only applies
     * to a leg that is pending review (otherwise AttendanceRuleException:
     * the website flashes it, the API returns 422), so a stale page or a
     * replayed request can't flip an earlier decision.
     *
     * @throws AttendanceRuleException
     */
    public function approveTimeIn(Attendance $attendance): Attendance
    {
        return $this->review($attendance, 'time_in', 'approved');
    }

    /**
     * @throws AttendanceRuleException
     */
    public function rejectTimeIn(Attendance $attendance, ?string $reason): Attendance
    {
        return $this->review($attendance, 'time_in', 'rejected', $reason);
    }

    /**
     * @throws AttendanceRuleException
     */
    public function approveTimeOut(Attendance $attendance): Attendance
    {
        return $this->review($attendance, 'time_out', 'approved');
    }

    /**
     * @throws AttendanceRuleException
     */
    public function rejectTimeOut(Attendance $attendance, ?string $reason): Attendance
    {
        return $this->review($attendance, 'time_out', 'rejected', $reason);
    }

    /**
     * Decide one leg, then recompute the day's hours: credited exactly when
     * both legs are approved, whichever was approved last (an emergency
     * time-out can be submitted, and approved, while the time-in is still
     * pending); cleared when either leg is rejected. The row is locked so
     * two reviewers can't both act on the same pending leg.
     *
     * @param  'time_in'|'time_out'  $leg
     * @param  'approved'|'rejected'  $decision
     *
     * @throws AttendanceRuleException
     */
    private function review(Attendance $attendance, string $leg, string $decision, ?string $reason = null): Attendance
    {
        DB::transaction(function () use ($attendance, $leg, $decision, $reason) {
            $current = Attendance::query()->lockForUpdate()->findOrFail($attendance->id);

            if ($current->{"{$leg}_status"} !== 'pending' || empty($current->{$leg})) {
                $label = $leg === 'time_in' ? 'time-in' : 'time-out';

                throw new AttendanceRuleException("This {$label} is not pending review.");
            }

            $current->{"{$leg}_status"} = $decision;
            $current->{"{$leg}_rejection_reason"} = $decision === 'rejected' ? $reason : null;
            $current->rendered_hours = self::hoursIfFullyApproved($current);
            $current->save();

            $attendance->setRawAttributes($current->getAttributes(), true);
        });

        $this->notifications->attendanceReviewed($attendance, $leg, $decision, $reason);

        return $attendance;
    }

    private static function hoursIfFullyApproved(Attendance $attendance): ?float
    {
        if ($attendance->time_in_status !== 'approved' || $attendance->time_out_status !== 'approved'
            || empty($attendance->time_in) || empty($attendance->time_out)) {
            return null;
        }

        return self::renderedHours($attendance->date->format('Y-m-d'), $attendance->time_in, $attendance->time_out);
    }

    /**
     * Attendance Monitoring: staff add an entry directly. Any leg with a time
     * counts as approved.
     *
     * @param  array{date: string, time_in: ?string, time_out: ?string}  $entry
     */
    public function createEntry(Student $student, User $actor, array $entry): Attendance
    {
        return $student->attendances()->create($this->entryAttributes($entry, $actor));
    }

    /**
     * Attendance Monitoring: staff edit an entry. A leg staff clear no longer
     * has a submission, so its captured photo + GPS go with it. A leg whose
     * time is merely overridden keeps its evidence on purpose: it still
     * documents what the student sent.
     *
     * @param  array{date: string, time_in: ?string, time_out: ?string}  $entry
     */
    public function updateEntry(Attendance $attendance, User $actor, array $entry): Attendance
    {
        $attributes = $this->entryAttributes($entry, $actor);

        $orphanedPhotos = [];

        foreach (['time_in', 'time_out'] as $leg) {
            if (($attributes[$leg] ?? null) !== null) {
                continue;
            }

            $orphanedPhotos[] = $attendance->{"{$leg}_photo_path"};

            foreach (['photo_path', 'latitude', 'longitude', 'accuracy', 'mocked'] as $field) {
                $attributes["{$leg}_{$field}"] = null;
            }
        }

        $attendance->update($attributes);

        if ($orphanedPhotos = array_filter($orphanedPhotos)) {
            Storage::disk(self::PHOTO_DISK)->delete($orphanedPhotos);
        }

        return $attendance;
    }

    public function deleteEntry(Attendance $attendance): void
    {
        $photoPaths = array_filter([
            $attendance->time_in_photo_path,
            $attendance->time_out_photo_path,
        ]);

        $attendance->delete();

        if ($photoPaths) {
            Storage::disk(self::PHOTO_DISK)->delete($photoPaths);
        }
    }

    /**
     * Hours between time-in and time-out on the given date. Carbon 3's
     * diffIn* methods are signed ($a->diffInMinutes($b) = $b - $a), so the
     * time-in is the receiver.
     */
    public static function renderedHours(string $date, string $timeIn, string $timeOut): float
    {
        $in = Carbon::parse($date.' '.$timeIn);
        $out = Carbon::parse($date.' '.$timeOut);

        return round($in->diffInMinutes($out) / 60, 2);
    }

    /**
     * @param  array{date: string, time_in: ?string, time_out: ?string}  $entry
     */
    private function entryAttributes(array $entry, User $actor): array
    {
        $entry['rendered_hours'] = empty($entry['time_in']) || empty($entry['time_out'])
            ? null
            : self::renderedHours($entry['date'], $entry['time_in'], $entry['time_out']);
        $entry['recorded_by'] = $actor->id;
        $entry['time_in_status'] = $entry['time_in'] ? 'approved' : null;
        $entry['time_out_status'] = $entry['time_out'] ? 'approved' : null;

        return $entry;
    }

    /**
     * The browser forwards the device's accuracy unchanged, so it can exceed
     * the decimal(8,2) column. Rejecting it would block that device on every
     * retry, so it is clamped instead: 999999.99 m still reads as "useless
     * fix" to a reviewer. Clamp before rounding so 999999.995 can't round up
     * past the column max.
     */
    public static function clampAccuracy(mixed $accuracy): ?float
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
    private function saveWithEvidence(Attendance $record, string $leg, Student $student, UploadedFile $photo, array $capture): void
    {
        $oldPath = $record->{"{$leg}_photo_path"};
        $newPath = $photo->store("attendance-photos/{$student->id}", self::PHOTO_DISK);

        $record->{"{$leg}_photo_path"} = $newPath;
        $record->{"{$leg}_latitude"} = $capture['latitude'];
        $record->{"{$leg}_longitude"} = $capture['longitude'];
        $record->{"{$leg}_accuracy"} = self::clampAccuracy($capture['accuracy'] ?? null);
        // Only the mobile app can detect a mocked fix; the website never
        // passes it, so a web (re-)submission stores null = unknown.
        $record->{"{$leg}_mocked"} = $capture['mocked'] ?? null;

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
