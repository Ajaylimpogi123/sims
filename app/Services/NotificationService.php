<?php

namespace App\Services;

use App\Models\Attendance;
use App\Models\InternshipReport;
use App\Models\Notification;
use App\Models\Student;
use App\Models\User;
use Illuminate\Support\Collection;

class NotificationService
{
    /**
     * Coordinator + Administrator role IDs, used whenever a notification
     * needs to reach "staff" rather than a specific student/supervisor.
     */
    private const STAFF_ROLE_IDS = [2, 4];

    private const ADMIN_ROLE_ID = 4;

    public function notify(User $user, string $type, string $title, ?string $body = null, array $data = []): Notification
    {
        return $user->notifications()->create([
            'type' => $type,
            'title' => $title,
            'body' => $body,
            'data' => $data,
        ]);
    }

    /**
     * @param  iterable<User>  $users
     */
    public function notifyMany(iterable $users, string $type, string $title, ?string $body = null, array $data = []): void
    {
        foreach ($users as $user) {
            $this->notify($user, $type, $title, $body, $data);
        }
    }

    /**
     * A student submitted a time-in/time-out (or emergency time-out) that
     * now needs coordinator/admin approval.
     */
    public function attendanceSubmitted(Attendance $attendance, string $leg): void
    {
        $studentName = $attendance->student?->user?->name ?? 'A student';
        $legLabel = $leg === 'time_in' ? 'time-in' : 'time-out';
        $date = $attendance->date?->format('F j, Y');

        $this->notifyMany(
            $this->staffUsers(),
            'attendance_pending',
            'New attendance approval request',
            "{$studentName} submitted a {$legLabel} for {$date} awaiting your approval.",
            ['attendance_id' => $attendance->id],
        );
    }

    /**
     * A coordinator/admin approved or rejected a student's time-in/out leg.
     */
    public function attendanceReviewed(Attendance $attendance, string $leg, string $status, ?string $reason = null): void
    {
        $user = $attendance->student?->user;

        if (! $user) {
            return;
        }

        $legLabel = $leg === 'time_in' ? 'Time-in' : 'Time-out';
        $date = $attendance->date?->format('F j, Y');
        $body = "Your {$legLabel} for {$date} was {$status}.";

        if ($status === 'rejected' && $reason) {
            $body .= " Reason: {$reason}";
        }

        $this->notify(
            $user,
            'attendance_reviewed',
            "{$legLabel} {$status}",
            $body,
            ['attendance_id' => $attendance->id],
        );
    }

    /**
     * A student submitted a new internship report that needs review.
     */
    public function reportSubmitted(InternshipReport $report): void
    {
        $student = $report->student;
        $studentName = $student?->user?->name ?? 'A student';

        $recipients = $this->staffUsers();

        if ($student?->supervisor) {
            $recipients->push($student->supervisor);
        }

        $this->notifyMany(
            $recipients->unique('id'),
            'report_submitted',
            'New '.$report->type.' report submitted',
            "{$studentName} submitted a {$report->type} report for review.",
            ['report_id' => $report->id],
        );
    }

    /**
     * A reviewer marked a student's report as reviewed.
     */
    public function reportReviewed(InternshipReport $report): void
    {
        $user = $report->student?->user;

        if (! $user) {
            return;
        }

        $body = 'Your '.$report->type.' report has been reviewed.';

        if ($report->reviewer_comment) {
            $body .= ' Comment: '.$report->reviewer_comment;
        }

        $this->notify(
            $user,
            'report_reviewed',
            'Your report was reviewed',
            $body,
            ['report_id' => $report->id],
        );
    }

    /**
     * A coordinator/admin changed a student's company/supervisor/status.
     */
    public function assignmentUpdated(Student $student): void
    {
        $companyName = $student->company?->company_name ?? 'no company';
        $body = "Your internship assignment was updated — company: {$companyName}, status: {$student->internship_status}.";

        $recipients = collect();

        if ($student->user) {
            $recipients->push($student->user);
        }

        if ($student->supervisor) {
            $recipients->push($student->supervisor);
        }

        $this->notifyMany(
            $recipients->unique('id'),
            'assignment_updated',
            'Internship assignment updated',
            $body,
            ['student_id' => $student->id],
        );
    }

    /**
     * A new student self-registered — let admins know.
     */
    public function studentRegistered(User $studentUser, string $studentNumber): void
    {
        $this->notifyMany(
            User::where('role_id', self::ADMIN_ROLE_ID)->get(),
            'student_registered',
            'New student registration',
            "{$studentUser->name} ({$studentNumber}) just registered.",
            ['user_id' => $studentUser->id],
        );
    }

    /**
     * @return Collection<int, User>
     */
    private function staffUsers(): Collection
    {
        return User::whereIn('role_id', self::STAFF_ROLE_IDS)->get();
    }

    /**
     * Recent Activity feed for the Dashboard's Overview tab. Reuses the
     * existing per-recipient Notification inbox rather than a new
     * activity/audit table.
     *
     * Admin/Coordinator: their own notification inbox.
     * Supervisor: their own inbox, unioned with notifications belonging to
     * their supervised students' user accounts — scoped via
     * supervisedStudents(), never the company_supervisors roster pivot, so
     * a supervisor never sees another supervisor's students' events.
     *
     * @return Collection<int, Notification>
     */
    public function recentActivity(User $user, int $limit = 10): Collection
    {
        if ((int) $user->role_id === 3) {
            $studentUserIds = $user->supervisedStudents()->pluck('user_id');

            return Notification::query()
                ->where('user_id', $user->id)
                ->orWhereIn('user_id', $studentUserIds)
                ->orderByDesc('created_at')
                ->orderByDesc('id')
                ->limit($limit)
                ->get();
        }

        return $user->notifications()->limit($limit)->get();
    }
}
