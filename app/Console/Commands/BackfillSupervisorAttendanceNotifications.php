<?php

namespace App\Console\Commands;

use App\Models\Attendance;
use App\Models\Notification;
use App\Services\NotificationService;
use Illuminate\Console\Command;

/**
 * One-off: attendanceSubmitted() only started notifying the student's
 * supervisor in commit 5597120, so attendance submitted before that never
 * reached the supervisor who now has to approve it.
 *
 * For every attendance with a currently pending leg, if the student's
 * supervisor (role 3) has no attendance_pending notification for that
 * attendance_id, send them one through NotificationService so the wording
 * matches a live submission. Admins/Coordinators are never touched.
 * Idempotent: a second run finds the notifications it created and skips.
 */
class BackfillSupervisorAttendanceNotifications extends Command
{
    protected $signature = 'notifications:backfill-supervisor-attendance
                            {--dry-run : List the notifications that would be created without writing anything}';

    protected $description = 'One-off: notify supervisors about pending attendance submitted before they were added as recipients';

    public function handle(NotificationService $notifications): int
    {
        $dryRun = (bool) $this->option('dry-run');

        if ($dryRun) {
            $this->warn('DRY RUN — nothing will be written.');
        }

        $pending = Attendance::query()
            ->where(fn ($q) => $q->where('time_in_status', 'pending')->orWhere('time_out_status', 'pending'))
            ->with('student.user', 'student.supervisor')
            ->orderBy('id')
            ->get();

        $rows = [];

        foreach ($pending as $attendance) {
            $supervisor = $notifications->attendanceSupervisor($attendance);

            if (! $supervisor) {
                continue;
            }

            $alreadyNotified = Notification::query()
                ->where('user_id', $supervisor->id)
                ->where('type', 'attendance_pending')
                ->where('data->attendance_id', $attendance->id)
                ->exists();

            if ($alreadyNotified) {
                continue;
            }

            // A pending time-out is the most recent submission; otherwise it
            // is the time-in. One notification per attendance is enough —
            // it links to the approvals page, which shows both legs.
            $leg = $attendance->time_out_status === 'pending' ? 'time_out' : 'time_in';

            if (! $dryRun) {
                $notifications->notifyAttendanceSubmitted($supervisor, $attendance, $leg);
            }

            $rows[] = [$attendance->id, $attendance->date?->toDateString(), $leg, "{$supervisor->name} (#{$supervisor->id})"];
        }

        $this->info('Pending attendance checked: '.$pending->count().'. Notifications '.($dryRun ? 'to create' : 'created').': '.count($rows).'.');

        if ($rows !== []) {
            $this->table(['Attendance', 'Date', 'Leg', 'Supervisor'], $rows);
        }

        return self::SUCCESS;
    }
}
