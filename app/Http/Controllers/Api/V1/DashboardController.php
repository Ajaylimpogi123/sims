<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\NotificationResource;
use App\Models\Attendance;
use App\Models\InternshipReport;
use App\Models\Notification;
use App\Models\User;
use App\Services\DashboardAnalyticsService;
use App\Services\NotificationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * The signed-in user's role dashboard: the same numbers and sections as the
 * website dashboard for that user. Every figure comes from
 * DashboardAnalyticsService (Supervisor scoping included); this controller
 * only reshapes them for the app.
 */
class DashboardController extends Controller
{
    private const STAFF_AND_SUPERVISOR_ROLE_IDS = [
        DashboardAnalyticsService::COORDINATOR_ROLE_ID,
        DashboardAnalyticsService::SUPERVISOR_ROLE_ID,
        DashboardAnalyticsService::ADMIN_ROLE_ID,
    ];

    public function __construct(
        private DashboardAnalyticsService $analytics,
        private NotificationService $notifications,
    ) {}

    /**
     * Always the same six keys; the ones a role doesn't have are null
     * (objects) or [] (lists). See docs/api/v1.md for the shape per role.
     */
    public function show(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $roleId = (int) $user->role_id;
        $isStaffOrSupervisor = in_array($roleId, self::STAFF_AND_SUPERVISOR_ROLE_IDS, true);

        return response()->json([
            'role_id' => $roleId,
            'student' => $roleId === DashboardAnalyticsService::STUDENT_ROLE_ID ? $this->studentSummary($user) : null,
            'kpis' => $this->kpis($user, $roleId),
            'supervised_students' => $roleId === DashboardAnalyticsService::SUPERVISOR_ROLE_ID
                ? $this->supervisedStudents($user)
                : null,
            'action_items' => $isStaffOrSupervisor ? $this->analytics->appActionItems($user) : [],
            'recent_activity' => $isStaffOrSupervisor ? $this->recentActivity($request, $user) : [],
        ]);
    }

    /**
     * Null when the Student account has no student profile (same as /me).
     */
    private function studentSummary(User $user): ?array
    {
        $student = $user->student;

        if (! $student) {
            return null;
        }

        $summary = $this->analytics->studentSummary($student);
        $hours = $summary['hours'];

        return [
            'internship' => [
                'status' => $summary['internship_status'],
                'company' => $summary['company']
                    ? ['id' => $summary['company']->id, 'name' => $summary['company']->company_name]
                    : null,
                'supervisor' => $summary['supervisor']
                    ? ['id' => $summary['supervisor']->id, 'name' => $summary['supervisor']->name]
                    : null,
            ],
            'hours' => [
                'rendered' => self::hours($hours['rendered']),
                'required' => $hours['required'] !== null ? (int) $hours['required'] : null,
                'remaining' => $hours['remaining'] !== null ? self::hours($hours['remaining']) : null,
                'progress_percent' => self::progressPercent($hours['rendered'], $hours['required']),
            ],
            'today_attendance' => $this->attendance($summary['todayAttendance']),
            'reports' => $summary['reportCounts'],
            'recent_reports' => $summary['recentReports']->map(fn (InternshipReport $report) => [
                'id' => $report->id,
                'type' => $report->type,
                'period_start' => $report->period_start?->format('Y-m-d'),
                'period_end' => $report->period_end?->format('Y-m-d'),
                'status' => $report->status,
            ])->values()->all(),
        ];
    }

    private function attendance(?Attendance $attendance): ?array
    {
        if (! $attendance) {
            return null;
        }

        $date = $attendance->date?->format('Y-m-d');

        return [
            'id' => $attendance->id,
            'date' => $date,
            'time_in' => self::dateTime($date, $attendance->time_in),
            'time_in_status' => $attendance->time_in_status,
            'time_out' => self::dateTime($date, $attendance->time_out),
            'time_out_status' => $attendance->time_out_status,
        ];
    }

    /**
     * The role's KPI set with snake_case keys. Values stay exactly as the
     * service computed them (counts are ints, the completion progress is a
     * percentage with 1 decimal, trends are a month-over-month % or null).
     */
    private function kpis(User $user, int $roleId): ?array
    {
        $kpis = match ($roleId) {
            DashboardAnalyticsService::COORDINATOR_ROLE_ID => $this->analytics->coordinatorKpis(),
            DashboardAnalyticsService::SUPERVISOR_ROLE_ID => $this->analytics->supervisorKpis($user),
            DashboardAnalyticsService::ADMIN_ROLE_ID => $this->analytics->adminKpis(),
            default => null,
        };

        if ($kpis === null) {
            return null;
        }

        return collect($kpis)->mapWithKeys(fn (array $kpi, string $key) => [Str::snake($key) => $kpi])->all();
    }

    private function supervisedStudents(User $user): array
    {
        return $this->analytics->supervisedStudentsProgress($user)
            ->map(fn (array $student) => [
                'id' => $student['id'],
                'name' => $student['name'],
                'internship_status' => $student['internship_status'],
                'rendered_hours' => self::hours($student['rendered_hours']),
                'required_hours' => $student['required_hours'] !== null ? (int) $student['required_hours'] : null,
                'progress_percent' => self::progressPercent($student['rendered_hours'], $student['required_hours']),
            ])
            ->values()
            ->all();
    }

    /**
     * The website's Recent Activity feed as Notification objects, plus
     * `is_own`: a Supervisor also sees their students' notifications, which
     * aren't in their inbox (marking one read would 404).
     */
    private function recentActivity(Request $request, User $user): array
    {
        return $this->notifications->recentActivity($user)
            ->map(fn (Notification $notification) => [
                ...(new NotificationResource($notification))->resolve($request),
                'is_own' => (int) $notification->user_id === (int) $user->id,
            ])
            ->values()
            ->all();
    }

    private static function hours(float|int|string $value): float
    {
        return round((float) $value, 2);
    }

    /**
     * Same as the website's hours progress bar: rendered / required as a
     * whole percentage capped at 100, 0 when required is 0, null when no
     * target is set.
     */
    private static function progressPercent(float|int|string $rendered, float|int|string|null $required): ?int
    {
        if ($required === null) {
            return null;
        }

        $required = (float) $required;

        if ($required <= 0) {
            return 0;
        }

        return (int) min(round(((float) $rendered / $required) * 100), 100);
    }

    /**
     * A local (Asia/Manila) date + TIME column as ISO 8601 with offset.
     */
    private static function dateTime(?string $date, ?string $time): ?string
    {
        if ($date === null || $time === null || $time === '') {
            return null;
        }

        return Carbon::parse("{$date} {$time}", config('app.timezone'))->toIso8601String();
    }
}
