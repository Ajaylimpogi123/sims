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

    private const STUDENT_ROLE_ID = 1;

    private const COORDINATOR_ROLE_ID = 2;

    private const SUPERVISOR_ROLE_ID = 3;

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
     * now needs approval. Approvals are Supervisor/Admin only
     * (/attendance-approvals is role:3,4), so this goes to every Admin plus
     * the student's own supervisor — never to Coordinators, who can't act
     * on it.
     */
    public function attendanceSubmitted(Attendance $attendance, string $leg): void
    {
        $recipients = User::where('role_id', self::ADMIN_ROLE_ID)->get();

        $supervisor = $this->attendanceSupervisor($attendance);

        if ($supervisor) {
            $recipients->push($supervisor);
        }

        foreach ($recipients->unique('id') as $recipient) {
            $this->notifyAttendanceSubmitted($recipient, $attendance, $leg);
        }
    }

    /**
     * Send the attendance_pending notification for one leg to a single
     * recipient. attendanceSubmitted() uses this for every recipient, and
     * the supervisor backfill command uses it so the wording never drifts.
     */
    public function notifyAttendanceSubmitted(User $recipient, Attendance $attendance, string $leg): Notification
    {
        $studentName = $attendance->student?->user?->name ?? 'A student';
        $legLabel = $leg === 'time_in' ? 'time-in' : 'time-out';
        $date = $attendance->date?->format('F j, Y');

        return $this->notify(
            $recipient,
            'attendance_pending',
            'New attendance approval request',
            "{$studentName} submitted a {$legLabel} for {$date} awaiting your approval.",
            ['attendance_id' => $attendance->id],
        );
    }

    /**
     * The student's assigned supervisor, but only while that account still
     * holds the Supervisor role — only role 3 can act on approvals.
     */
    public function attendanceSupervisor(Attendance $attendance): ?User
    {
        $supervisor = $attendance->student?->supervisor;

        return $supervisor && (int) $supervisor->role_id === self::SUPERVISOR_ROLE_ID
            ? $supervisor
            : null;
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

    /**
     * Serialize notifications for an Inertia payload, adding the
     * viewer-specific destination `url` to each one.
     *
     * @param  iterable<Notification>  $notifications
     * @return Collection<int, array<string, mixed>>
     */
    public function withUrls(iterable $notifications, User $viewer): Collection
    {
        return collect($notifications)
            ->map(fn (Notification $notification) => $this->present($notification, $viewer))
            ->values();
    }

    /**
     * @return array<string, mixed>
     */
    public function present(Notification $notification, User $viewer): array
    {
        return [
            ...$notification->toArray(),
            'url' => $this->urlFor($notification, $viewer),
        ];
    }

    /**
     * Where clicking a notification should take the given viewer. Resolved
     * from the notification type and the viewer's *current* role — not the
     * recipient's — so a Supervisor looking at a student's notification in
     * Recent Activity lands on a page the Supervisor can open.
     *
     * Every destination is a list page the viewer's role can access; the
     * related record is never queried, so a deleted record just means
     * nothing gets highlighted. Unknown types, unmapped type/role combos,
     * and notifications missing their data id fall back to the inbox.
     */
    public function urlFor(Notification $notification, User $viewer): string
    {
        [$routeName, $parameters] = $this->destinationFor($notification, (int) $viewer->role_id)
            ?? ['notifications.index', []];

        return route($routeName, $parameters);
    }

    /**
     * @return array{0: string, 1: array<string, int>}|null
     */
    private function destinationFor(Notification $notification, int $roleId): ?array
    {
        $data = is_array($notification->data) ? $notification->data : [];

        $idKey = match ($notification->type) {
            'attendance_pending', 'attendance_reviewed' => 'attendance_id',
            'report_submitted', 'report_reviewed' => 'report_id',
            'assignment_updated' => 'student_id',
            'student_registered' => 'user_id',
            default => null,
        };

        $id = $idKey !== null ? $this->positiveInt($data[$idKey] ?? null) : null;

        if ($id === null) {
            return null;
        }

        $highlight = ['highlight' => $id];

        return match ($notification->type) {
            'attendance_pending' => match ($roleId) {
                self::SUPERVISOR_ROLE_ID, self::ADMIN_ROLE_ID => ['attendance-approvals.index', $highlight],
                // Coordinators received these before approvals moved to
                // Supervisor/Admin; old rows open read-only monitoring
                // instead of a 403.
                self::COORDINATOR_ROLE_ID => ['attendance-monitoring.index', []],
                default => null,
            },
            'attendance_reviewed' => match ($roleId) {
                self::STUDENT_ROLE_ID => ['attendance.index', $highlight],
                self::COORDINATOR_ROLE_ID, self::SUPERVISOR_ROLE_ID, self::ADMIN_ROLE_ID => ['attendance-monitoring.index', []],
                default => null,
            },
            'report_submitted' => match ($roleId) {
                self::COORDINATOR_ROLE_ID, self::SUPERVISOR_ROLE_ID, self::ADMIN_ROLE_ID => ['report-reviews.index', $highlight],
                default => null,
            },
            'report_reviewed' => match ($roleId) {
                self::STUDENT_ROLE_ID => ['reports.index', $highlight],
                self::COORDINATOR_ROLE_ID, self::SUPERVISOR_ROLE_ID, self::ADMIN_ROLE_ID => ['report-reviews.index', $highlight],
                default => null,
            },
            'assignment_updated' => match ($roleId) {
                self::STUDENT_ROLE_ID => ['dashboard', []],
                self::SUPERVISOR_ROLE_ID => ['progress-monitoring.index', []],
                self::COORDINATOR_ROLE_ID, self::ADMIN_ROLE_ID => ['internship-assignment.index', []],
                default => null,
            },
            'student_registered' => match ($roleId) {
                self::COORDINATOR_ROLE_ID, self::ADMIN_ROLE_ID => ['internship-assignment.index', []],
                default => null,
            },
            default => null,
        };
    }

    private function positiveInt(mixed $value): ?int
    {
        $id = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);

        return $id === false ? null : $id;
    }
}
