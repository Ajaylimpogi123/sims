<?php

namespace App\Services;

use App\Models\Attendance;
use App\Models\Company;
use App\Models\Evaluation;
use App\Models\EvaluationResponse;
use App\Models\InternshipReport;
use App\Models\Student;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Single source of truth for every scoped KPI/chart query the Dashboard &
 * Analytics module needs. Supervisor scoping is applied unconditionally by
 * the private scoped*Query() helpers — request-supplied filters can only
 * narrow, never widen, what a Supervisor sees (see resolveFilters()).
 *
 * Schema-gap scoping decisions (do not fabricate data for these):
 * - InternshipReport::status only ever has pending/reviewed values — there
 *   is no "returned/rejected" concept, so no such KPI/chart segment exists
 *   anywhere in this service.
 * - There is no absent/late attendance status or structured schedule table.
 *   Attendance outcomes are reported as Present (an approved time-in row
 *   exists for the date) + Rejected only.
 */
class DashboardAnalyticsService
{
    public const STUDENT_ROLE_ID = 1;

    public const COORDINATOR_ROLE_ID = 2;

    public const SUPERVISOR_ROLE_ID = 3;

    public const ADMIN_ROLE_ID = 4;

    /**
     * "Students with attendance issues" is scoped honestly as students with
     * at least this many rejected attendance legs (time-in or time-out
     * combined) — a proxy for chronic issues, not true absence tracking.
     */
    public const FREQUENT_REJECTION_THRESHOLD = 3;

    /**
     * A student is "nearing completion" once rendered hours reach this
     * percentage of their required hours (and they haven't finished yet).
     */
    public const NEARING_COMPLETION_PERCENT = 90;

    // -----------------------------------------------------------------
    // Filters
    // -----------------------------------------------------------------

    /**
     * Validate + scope incoming Analytics filter params. For a Supervisor,
     * supervisor_id is silently forced to their own id regardless of what
     * was submitted (never a 403 — we don't want to leak that the param
     * exists), while company_id/student_id are checked against their own
     * supervised students and rejected with a 403 if they don't belong to
     * that scope. Coordinator/Admin are never scoped — they may pass any
     * supervisor_id/company_id/student_id.
     */
    public function resolveFilters(Request $request, User $user): array
    {
        $validated = $request->validate([
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date', 'after_or_equal:date_from'],
            'company_id' => ['nullable', 'integer', 'exists:companies,id'],
            'supervisor_id' => ['nullable', 'integer', 'exists:users,id'],
            'student_id' => ['nullable', 'integer', 'exists:students,id'],
        ]);

        $filters = [
            'date_from' => $validated['date_from'] ?? null,
            'date_to' => $validated['date_to'] ?? null,
            'company_id' => isset($validated['company_id']) ? (int) $validated['company_id'] : null,
            'supervisor_id' => isset($validated['supervisor_id']) ? (int) $validated['supervisor_id'] : null,
            'student_id' => isset($validated['student_id']) ? (int) $validated['student_id'] : null,
        ];

        if ((int) $user->role_id === self::SUPERVISOR_ROLE_ID) {
            $filters['supervisor_id'] = $user->id;

            if ($filters['company_id'] !== null) {
                abort_unless(
                    $user->supervisedStudents()->where('company_id', $filters['company_id'])->exists(),
                    403,
                );
            }

            if ($filters['student_id'] !== null) {
                abort_unless(
                    $user->supervisedStudents()->where('id', $filters['student_id'])->exists(),
                    403,
                );
            }
        }

        return $filters;
    }

    /**
     * Filter-dropdown option lists sent to the frontend — built from scoped
     * data for a Supervisor so their own UI can never present another
     * supervisor's students/companies as selectable.
     */
    public function filterOptions(User $user): array
    {
        $studentsQuery = Student::query()->with('user:id,name')
            ->when(
                (int) $user->role_id === self::SUPERVISOR_ROLE_ID,
                fn (Builder $q) => $q->where('supervisor_id', $user->id),
            )
            ->orderBy('created_at', 'desc')
            ->get(['id', 'user_id', 'student_number', 'company_id', 'supervisor_id']);

        $companiesQuery = Company::query()
            ->when(
                (int) $user->role_id === self::SUPERVISOR_ROLE_ID,
                fn (Builder $q) => $q->whereHas('students', fn (Builder $sq) => $sq->where('supervisor_id', $user->id)),
            )
            ->orderBy('company_name')
            ->get(['id', 'company_name']);

        $supervisorsQuery = (int) $user->role_id === self::SUPERVISOR_ROLE_ID
            ? User::where('id', $user->id)->get(['id', 'name'])
            : User::where('role_id', self::SUPERVISOR_ROLE_ID)->orderBy('name')->get(['id', 'name']);

        return [
            'students' => $studentsQuery,
            'companies' => $companiesQuery,
            'supervisors' => $supervisorsQuery,
        ];
    }

    // -----------------------------------------------------------------
    // Scoped base queries
    // -----------------------------------------------------------------

    private function scopedStudentQuery(User $user, array $filters = []): Builder
    {
        return Student::query()
            ->when(
                (int) $user->role_id === self::SUPERVISOR_ROLE_ID,
                fn (Builder $q) => $q->where('students.supervisor_id', $user->id),
            )
            ->when(
                ! empty($filters['supervisor_id']) && (int) $user->role_id !== self::SUPERVISOR_ROLE_ID,
                fn (Builder $q) => $q->where('students.supervisor_id', $filters['supervisor_id']),
            )
            ->when(! empty($filters['company_id']), fn (Builder $q) => $q->where('students.company_id', $filters['company_id']))
            ->when(! empty($filters['student_id']), fn (Builder $q) => $q->where('students.id', $filters['student_id']));
    }

    private function scopedAttendanceQuery(User $user, array $filters = []): Builder
    {
        return Attendance::query()
            ->whereHas('student', function (Builder $q) use ($user, $filters) {
                $q->when(
                    (int) $user->role_id === self::SUPERVISOR_ROLE_ID,
                    fn (Builder $sq) => $sq->where('supervisor_id', $user->id),
                )
                    ->when(
                        ! empty($filters['supervisor_id']) && (int) $user->role_id !== self::SUPERVISOR_ROLE_ID,
                        fn (Builder $sq) => $sq->where('supervisor_id', $filters['supervisor_id']),
                    )
                    ->when(! empty($filters['company_id']), fn (Builder $sq) => $sq->where('company_id', $filters['company_id']))
                    ->when(! empty($filters['student_id']), fn (Builder $sq) => $sq->where('id', $filters['student_id']));
            })
            ->when(! empty($filters['date_from']), fn (Builder $q) => $q->whereDate('date', '>=', $filters['date_from']))
            ->when(! empty($filters['date_to']), fn (Builder $q) => $q->whereDate('date', '<=', $filters['date_to']));
    }

    private function scopedReportQuery(User $user, array $filters = []): Builder
    {
        return InternshipReport::query()
            ->whereHas('student', function (Builder $q) use ($user, $filters) {
                $q->when(
                    (int) $user->role_id === self::SUPERVISOR_ROLE_ID,
                    fn (Builder $sq) => $sq->where('supervisor_id', $user->id),
                )
                    ->when(
                        ! empty($filters['supervisor_id']) && (int) $user->role_id !== self::SUPERVISOR_ROLE_ID,
                        fn (Builder $sq) => $sq->where('supervisor_id', $filters['supervisor_id']),
                    )
                    ->when(! empty($filters['company_id']), fn (Builder $sq) => $sq->where('company_id', $filters['company_id']))
                    ->when(! empty($filters['student_id']), fn (Builder $sq) => $sq->where('id', $filters['student_id']));
            })
            ->when(! empty($filters['date_from']), fn (Builder $q) => $q->whereDate('created_at', '>=', $filters['date_from']))
            ->when(! empty($filters['date_to']), fn (Builder $q) => $q->whereDate('created_at', '<=', $filters['date_to']));
    }

    private function scopedEvaluationQuery(User $user, array $filters = []): Builder
    {
        return Evaluation::query()
            ->when(
                (int) $user->role_id === self::SUPERVISOR_ROLE_ID,
                fn (Builder $q) => $q->where('supervisor_id', $user->id),
            )
            ->when(
                ! empty($filters['supervisor_id']) && (int) $user->role_id !== self::SUPERVISOR_ROLE_ID,
                fn (Builder $q) => $q->where('supervisor_id', $filters['supervisor_id']),
            )
            ->when(! empty($filters['company_id']), fn (Builder $q) => $q->where('company_id', $filters['company_id']))
            ->when(! empty($filters['student_id']), fn (Builder $q) => $q->where('student_id', $filters['student_id']))
            ->when(! empty($filters['date_from']), fn (Builder $q) => $q->whereDate('created_at', '>=', $filters['date_from']))
            ->when(! empty($filters['date_to']), fn (Builder $q) => $q->whereDate('created_at', '<=', $filters['date_to']));
    }

    // -----------------------------------------------------------------
    // Shared helpers
    // -----------------------------------------------------------------

    private function pendingApprovalsCountFor(?User $user = null): int
    {
        $query = Attendance::query()->where(fn (Builder $q) => $q
            ->where('time_in_status', 'pending')
            ->orWhere('time_out_status', 'pending'));

        if ($user && (int) $user->role_id === self::SUPERVISOR_ROLE_ID) {
            $query->whereHas('student', fn (Builder $q) => $q->where('supervisor_id', $user->id));
        }

        return $query->count();
    }

    private function attendanceSummaryFor(?User $user = null): array
    {
        $query = Attendance::query();

        if ($user && (int) $user->role_id === self::SUPERVISOR_ROLE_ID) {
            $query->whereHas('student', fn (Builder $q) => $q->where('supervisor_id', $user->id));
        }

        $present = (clone $query)->where('time_in_status', 'approved')->count();
        $rejected = (clone $query)
            ->where(fn (Builder $q) => $q->where('time_in_status', 'rejected')->orWhere('time_out_status', 'rejected'))
            ->count();

        return ['present' => $present, 'rejected' => $rejected];
    }

    /**
     * Student ids whose rendered hours have reached NEARING_COMPLETION_PERCENT
     * of required_hours but the internship isn't marked completed yet.
     */
    private function nearingCompletionStudentIds(?User $user = null): \Illuminate\Support\Collection
    {
        return Student::query()
            ->when($user && (int) $user->role_id === self::SUPERVISOR_ROLE_ID, fn (Builder $q) => $q->where('supervisor_id', $user->id))
            ->whereNotNull('required_hours')
            ->where('required_hours', '>', 0)
            ->where('internship_status', '!=', 'completed')
            ->withSum('attendances as total_rendered_hours', 'rendered_hours')
            ->get()
            ->filter(function (Student $student) {
                $rendered = (float) ($student->total_rendered_hours ?? 0);
                $percent = ($rendered / $student->required_hours) * 100;

                return $percent >= self::NEARING_COMPLETION_PERCENT;
            })
            ->pluck('id');
    }

    /**
     * Student ids with >= FREQUENT_REJECTION_THRESHOLD rejected attendance
     * legs (time-in + time-out combined) — labeled honestly as a rejected-
     * entry count, not true absence tracking.
     */
    private function frequentRejectionStudentIds(?User $user = null): \Illuminate\Support\Collection
    {
        return Student::query()
            ->when($user && (int) $user->role_id === self::SUPERVISOR_ROLE_ID, fn (Builder $q) => $q->where('supervisor_id', $user->id))
            ->withCount([
                'attendances as rejection_count' => fn (Builder $q) => $q
                    ->where('time_in_status', 'rejected')
                    ->orWhere('time_out_status', 'rejected'),
            ])
            ->having('rejection_count', '>=', self::FREQUENT_REJECTION_THRESHOLD)
            ->pluck('id');
    }

    private function computeTrend(int $current, int $previous): ?float
    {
        if ($previous === 0) {
            return null;
        }

        return round((($current - $previous) / $previous) * 100, 1);
    }

    /**
     * Month-over-month growth trend for a base query filtered by created_at.
     * Returns null when the prior period has zero eligible rows.
     */
    private function monthlyCreationTrend(Builder $query): ?float
    {
        $now = Carbon::now();
        $thisMonthStart = $now->copy()->startOfMonth();
        $lastMonthStart = $now->copy()->subMonthNoOverflow()->startOfMonth();
        $lastMonthEnd = $now->copy()->subMonthNoOverflow()->endOfMonth();

        $current = (clone $query)->where('created_at', '>=', $thisMonthStart)->count();
        $previous = (clone $query)->whereBetween('created_at', [$lastMonthStart, $lastMonthEnd])->count();

        return $this->computeTrend($current, $previous);
    }

    // -----------------------------------------------------------------
    // KPI sets
    // -----------------------------------------------------------------

    public function adminKpis(): array
    {
        return [
            'totalStudents' => ['value' => Student::count(), 'trend' => $this->monthlyCreationTrend(Student::query())],
            'totalCompanies' => ['value' => Company::where('status', 'active')->count(), 'trend' => null],
            'totalSupervisors' => ['value' => User::where('role_id', self::SUPERVISOR_ROLE_ID)->where('status', 'active')->count(), 'trend' => null],
            'activeInternshipAssignments' => ['value' => Student::where('internship_status', 'ongoing')->count(), 'trend' => null],
            'completedInternships' => ['value' => Student::where('internship_status', 'completed')->count(), 'trend' => null],
            'pendingApprovals' => ['value' => $this->pendingApprovalsCountFor(), 'trend' => null],
            'attendanceSummary' => $this->attendanceSummaryFor(),
            'studentsCurrentlyOnInternship' => ['value' => Student::where('internship_status', 'ongoing')->count(), 'trend' => null],
            'studentsNearingCompletion' => ['value' => $this->nearingCompletionStudentIds()->count(), 'trend' => null],
            'studentsWithAttendanceIssues' => ['value' => $this->frequentRejectionStudentIds()->count(), 'trend' => null],
            'pendingSupervisorEvaluations' => ['value' => Evaluation::where('status', 'draft')->count(), 'trend' => null],
        ];
    }

    public function coordinatorKpis(): array
    {
        $activeStudents = Student::where('internship_status', 'ongoing')->get(['id', 'required_hours']);
        $progress = $this->averageCompletionProgress();

        return [
            'totalStudents' => ['value' => Student::count(), 'trend' => $this->monthlyCreationTrend(Student::query())],
            'activeInternshipAssignments' => ['value' => Student::where('internship_status', 'ongoing')->count(), 'trend' => null],
            'companies' => ['value' => Company::where('status', 'active')->count(), 'trend' => null],
            'activeSupervisors' => ['value' => User::where('role_id', self::SUPERVISOR_ROLE_ID)->where('status', 'active')->count(), 'trend' => null],
            'studentsCurrentlyOnInternship' => ['value' => Student::where('internship_status', 'ongoing')->count(), 'trend' => null],
            'studentsWithoutCompany' => ['value' => Student::whereNull('company_id')->count(), 'trend' => null],
            'pendingApprovals' => ['value' => $this->pendingApprovalsCountFor(), 'trend' => null],
            'attendanceSummary' => $this->attendanceSummaryFor(),
            'internshipCompletionProgress' => ['value' => $progress, 'trend' => null],
            'reportsAwaitingReview' => ['value' => InternshipReport::where('status', 'pending')->count(), 'trend' => null],
            'studentsRequiringAttention' => ['value' => $this->frequentRejectionStudentIds()->count(), 'trend' => null],
        ];
    }

    public function supervisorKpis(User $user): array
    {
        $studentsQuery = $this->scopedStudentQuery($user);
        $today = today()->toDateString();

        $todayPresent = (clone $studentsQuery)->whereHas(
            'attendances',
            fn (Builder $q) => $q->where('date', $today)->where('time_in_status', 'approved'),
        )->count();

        $attendanceSummary = $this->attendanceSummaryFor($user);

        return [
            'assignedStudents' => ['value' => (clone $studentsQuery)->count(), 'trend' => null],
            'activeInternships' => ['value' => (clone $studentsQuery)->where('internship_status', 'ongoing')->count(), 'trend' => null],
            'todaysAttendance' => ['value' => $todayPresent, 'trend' => null],
            'presentCount' => ['value' => $attendanceSummary['present'], 'trend' => null],
            'rejectedCount' => ['value' => $attendanceSummary['rejected'], 'trend' => null],
            'pendingAttendanceApprovals' => ['value' => $this->pendingApprovalsCountFor($user), 'trend' => null],
            'reportsAwaitingReview' => ['value' => InternshipReport::whereHas('student', fn (Builder $q) => $q->where('supervisor_id', $user->id))->where('status', 'pending')->count(), 'trend' => null],
            'pendingEvaluations' => ['value' => Evaluation::where('supervisor_id', $user->id)->where('status', 'draft')->count(), 'trend' => null],
            'completedEvaluations' => ['value' => Evaluation::where('supervisor_id', $user->id)->whereIn('status', ['submitted', 'locked'])->count(), 'trend' => null],
            'studentsRequiringAttention' => ['value' => $this->frequentRejectionStudentIds($user)->count(), 'trend' => null],
        ];
    }

    private function averageCompletionProgress(): float
    {
        $students = Student::whereNotNull('required_hours')
            ->where('required_hours', '>', 0)
            ->withSum('attendances as total_rendered_hours', 'rendered_hours')
            ->get();

        if ($students->isEmpty()) {
            return 0.0;
        }

        $percentages = $students->map(function (Student $student) {
            $rendered = (float) ($student->total_rendered_hours ?? 0);

            return min(($rendered / $student->required_hours) * 100, 100);
        });

        return round($percentages->avg(), 1);
    }

    // -----------------------------------------------------------------
    // Alerts / action items
    // -----------------------------------------------------------------

    public function actionItems(User $user): array
    {
        $roleId = (int) $user->role_id;
        $items = [];

        if (in_array($roleId, [self::COORDINATOR_ROLE_ID, self::ADMIN_ROLE_ID], true)) {
            if ($roleId === self::ADMIN_ROLE_ID) {
                $pending = $this->pendingApprovalsCountFor();
                if ($pending > 0) {
                    $items[] = $this->item('pending_approvals', "{$pending} attendance approval(s) awaiting action", $pending, 'attendance-approvals.index');
                }
            }

            $withoutCompany = Student::whereNull('company_id')->count();
            if ($withoutCompany > 0) {
                $items[] = $this->item('students_without_company', "{$withoutCompany} student(s) without a company assignment", $withoutCompany, 'internship-assignment.index');
            }

            $reportsAwaiting = InternshipReport::where('status', 'pending')->count();
            if ($reportsAwaiting > 0) {
                $items[] = $this->item('reports_awaiting_review', "{$reportsAwaiting} report(s) awaiting review", $reportsAwaiting, 'report-reviews.index');
            }

            $evalDrafts = Evaluation::where('status', 'draft')->count();
            if ($evalDrafts > 0) {
                $items[] = $this->item('evaluations_awaiting_completion', "{$evalDrafts} evaluation(s) still in draft", $evalDrafts, 'supervisor-evaluations.index');
            }

            $nearing = $this->nearingCompletionStudentIds()->count();
            if ($nearing > 0) {
                $items[] = $this->item('nearing_completion', "{$nearing} student(s) nearing internship completion", $nearing, 'progress-monitoring.index');
            }

            $rejections = $this->frequentRejectionStudentIds()->count();
            if ($rejections > 0) {
                $items[] = $this->item('frequent_rejections', "{$rejections} student(s) with repeated attendance rejections", $rejections, 'attendance-monitoring.index');
            }
        }

        if ($roleId === self::SUPERVISOR_ROLE_ID) {
            $pending = $this->pendingApprovalsCountFor($user);
            if ($pending > 0) {
                $items[] = $this->item('pending_approvals', "{$pending} attendance approval(s) awaiting your action", $pending, 'attendance-approvals.index');
            }

            $reportsAwaiting = InternshipReport::whereHas('student', fn (Builder $q) => $q->where('supervisor_id', $user->id))->where('status', 'pending')->count();
            if ($reportsAwaiting > 0) {
                $items[] = $this->item('reports_awaiting_review', "{$reportsAwaiting} report(s) awaiting your review", $reportsAwaiting, 'report-reviews.index');
            }

            $evalDrafts = Evaluation::where('supervisor_id', $user->id)->where('status', 'draft')->count();
            if ($evalDrafts > 0) {
                $items[] = $this->item('evaluations_awaiting_completion', "{$evalDrafts} evaluation(s) still in draft", $evalDrafts, 'supervisor-evaluations.index');
            }

            $nearing = $this->nearingCompletionStudentIds($user)->count();
            if ($nearing > 0) {
                $items[] = $this->item('nearing_completion', "{$nearing} student(s) nearing internship completion", $nearing, 'progress-monitoring.index');
            }

            $rejections = $this->frequentRejectionStudentIds($user)->count();
            if ($rejections > 0) {
                $items[] = $this->item('frequent_rejections', "{$rejections} student(s) with repeated attendance rejections", $rejections, 'attendance-monitoring.index');
            }
        }

        return $items;
    }

    private function item(string $key, string $label, int $count, string $routeName): array
    {
        return [
            'key' => $key,
            'label' => $label,
            'count' => $count,
            'href' => route($routeName),
        ];
    }

    // -----------------------------------------------------------------
    // Analytics tab charts
    // -----------------------------------------------------------------

    public function internshipStatusBreakdown(User $user, array $filters): array
    {
        return $this->scopedStudentQuery($user, $filters)
            ->select('internship_status', DB::raw('count(*) as total'))
            ->groupBy('internship_status')
            ->get()
            ->map(fn ($row) => ['status' => $row->internship_status, 'total' => (int) $row->total])
            ->values()
            ->all();
    }

    public function studentsByCompany(User $user, array $filters): array
    {
        return $this->scopedStudentQuery($user, $filters)
            ->whereNotNull('students.company_id')
            ->join('companies', 'companies.id', '=', 'students.company_id')
            ->select('companies.company_name', DB::raw('count(students.id) as total'))
            ->groupBy('companies.company_name')
            ->orderByDesc('total')
            ->limit(10)
            ->get()
            ->map(fn ($row) => ['company' => $row->company_name, 'total' => (int) $row->total])
            ->values()
            ->all();
    }

    public function completionProgressBuckets(User $user, array $filters): array
    {
        $buckets = [
            '0-25%' => 0,
            '25-50%' => 0,
            '50-75%' => 0,
            '75-100%' => 0,
            '100%+' => 0,
        ];

        $students = $this->scopedStudentQuery($user, $filters)
            ->whereNotNull('required_hours')
            ->where('required_hours', '>', 0)
            ->withSum('attendances as total_rendered_hours', 'rendered_hours')
            ->get();

        foreach ($students as $student) {
            $rendered = (float) ($student->total_rendered_hours ?? 0);
            $percent = ($rendered / $student->required_hours) * 100;

            $bucket = match (true) {
                $percent >= 100 => '100%+',
                $percent >= 75 => '75-100%',
                $percent >= 50 => '50-75%',
                $percent >= 25 => '25-50%',
                default => '0-25%',
            };

            $buckets[$bucket]++;
        }

        return collect($buckets)->map(fn ($total, $bucket) => ['bucket' => $bucket, 'total' => $total])->values()->all();
    }

    public function attendanceOutcomes(User $user, array $filters): array
    {
        $query = $this->scopedAttendanceQuery($user, $filters);

        $present = (clone $query)->where('time_in_status', 'approved')->count();
        $rejected = (clone $query)
            ->where(fn (Builder $q) => $q->where('time_in_status', 'rejected')->orWhere('time_out_status', 'rejected'))
            ->count();

        return [
            ['outcome' => 'present', 'total' => $present],
            ['outcome' => 'rejected', 'total' => $rejected],
        ];
    }

    public function attendanceTrend(User $user, array $filters): array
    {
        $from = ! empty($filters['date_from']) ? Carbon::parse($filters['date_from']) : now()->subDays(29);
        $to = ! empty($filters['date_to']) ? Carbon::parse($filters['date_to']) : now();

        $rows = $this->scopedAttendanceQuery($user, array_merge($filters, [
            'date_from' => $from->toDateString(),
            'date_to' => $to->toDateString(),
        ]))
            ->select('date', DB::raw("sum(case when time_in_status = 'approved' then 1 else 0 end) as present"), DB::raw("sum(case when time_in_status = 'rejected' or time_out_status = 'rejected' then 1 else 0 end) as rejected"))
            ->groupBy('date')
            ->orderBy('date')
            ->get();

        return $rows->map(fn ($row) => [
            'date' => $row->date instanceof \DateTimeInterface ? $row->date->format('Y-m-d') : (string) $row->date,
            'present' => (int) $row->present,
            'rejected' => (int) $row->rejected,
        ])->values()->all();
    }

    public function frequentRejections(User $user, array $filters): array
    {
        return $this->scopedStudentQuery($user, $filters)
            ->with('user:id,name')
            ->withCount([
                'attendances as rejection_count' => fn (Builder $q) => $q
                    ->where('time_in_status', 'rejected')
                    ->orWhere('time_out_status', 'rejected'),
            ])
            ->having('rejection_count', '>=', self::FREQUENT_REJECTION_THRESHOLD)
            ->orderByDesc('rejection_count')
            ->limit(10)
            ->get()
            ->map(fn (Student $student) => [
                'student_id' => $student->id,
                'name' => $student->user?->name,
                'rejection_count' => $student->rejection_count,
            ])
            ->values()
            ->all();
    }

    public function evaluationCompletion(User $user, array $filters): array
    {
        return $this->scopedEvaluationQuery($user, $filters)
            ->select('status', DB::raw('count(*) as total'))
            ->groupBy('status')
            ->get()
            ->map(fn ($row) => ['status' => $row->status, 'total' => (int) $row->total])
            ->values()
            ->all();
    }

    public function evaluationByCategory(User $user, array $filters): array
    {
        $evaluationIds = $this->scopedEvaluationQuery($user, $filters)->pluck('id');

        return EvaluationResponse::query()
            ->whereIn('evaluation_id', $evaluationIds)
            ->join('evaluation_criteria', 'evaluation_criteria.id', '=', 'evaluation_responses.evaluation_criteria_id')
            ->select('evaluation_criteria.category', DB::raw('avg(evaluation_responses.rating) as average_rating'))
            ->groupBy('evaluation_criteria.category')
            ->orderBy('evaluation_criteria.category')
            ->get()
            ->map(fn ($row) => ['category' => $row->category, 'average_rating' => round((float) $row->average_rating, 2)])
            ->values()
            ->all();
    }

    public function reportsFunnel(User $user, array $filters): array
    {
        return $this->scopedReportQuery($user, $filters)
            ->select('status', DB::raw('count(*) as total'))
            ->groupBy('status')
            ->get()
            ->map(fn ($row) => ['status' => $row->status, 'total' => (int) $row->total])
            ->values()
            ->all();
    }

    public function reportSubmissionTrend(User $user, array $filters): array
    {
        return $this->scopedReportQuery($user, $filters)
            ->select(DB::raw('DATE(created_at) as day'), DB::raw('count(*) as total'))
            ->groupBy('day')
            ->orderBy('day')
            ->get()
            ->map(fn ($row) => ['date' => (string) $row->day, 'total' => (int) $row->total])
            ->values()
            ->all();
    }
}
