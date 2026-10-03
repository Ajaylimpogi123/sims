<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\AnalyticsFiltersRequest;
use App\Models\Company;
use App\Models\Student;
use App\Models\User;
use App\Services\DashboardAnalyticsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The website dashboard's Analytics tab (Coordinator, Supervisor, Admin) as
 * plain series data. Filters are validated and Supervisor-scoped by
 * DashboardAnalyticsService::resolveFilters() and every dataset comes from
 * DashboardAnalyticsService::charts(), so the numbers equal the website's
 * for the same user and filters.
 */
class AnalyticsController extends Controller
{
    /**
     * Display labels, the same as the website's charts. Unknown keys fall
     * back to the key itself.
     */
    private const INTERNSHIP_STATUS_LABELS = [
        'not_started' => 'Not Started',
        'ongoing' => 'Ongoing',
        'completed' => 'Completed',
    ];

    private const OUTCOME_LABELS = [
        'present' => 'Present',
        'rejected' => 'Rejected',
    ];

    private const EVALUATION_STATUS_LABELS = [
        'draft' => 'Draft',
        'submitted' => 'Submitted',
        'locked' => 'Locked',
    ];

    private const REPORT_STATUS_LABELS = [
        'pending' => 'Pending',
        'reviewed' => 'Reviewed',
    ];

    public function __construct(private DashboardAnalyticsService $analytics) {}

    public function index(AnalyticsFiltersRequest $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        if ((int) $user->role_id === DashboardAnalyticsService::SUPERVISOR_ROLE_ID) {
            $this->guardSupervisorScope($request, $user);
        }

        $filters = $this->analytics->resolveFilters($request, $user);
        $charts = $this->analytics->charts($user, $filters);

        return response()->json([
            'filters' => $filters,
            'internship' => [
                'status_breakdown' => self::series($charts['internship']['statusBreakdown'], 'status', 'total', self::INTERNSHIP_STATUS_LABELS),
                'students_by_company' => self::series($charts['internship']['studentsByCompany'], 'company', 'total'),
                'completion_progress' => self::series($charts['internship']['completionProgressBuckets'], 'bucket', 'total'),
            ],
            'attendance' => [
                'outcomes' => self::series($charts['attendance']['outcomes'], 'outcome', 'total', self::OUTCOME_LABELS),
                'trend' => array_map(fn (array $row) => [
                    'date' => $row['date'],
                    'present' => $row['present'],
                    'rejected' => $row['rejected'],
                ], $charts['attendance']['trend']),
                'frequent_rejections' => array_map(fn (array $row) => [
                    'student_id' => $row['student_id'],
                    'name' => $row['name'],
                    'rejection_count' => (int) $row['rejection_count'],
                ], $charts['attendance']['frequentRejections']),
            ],
            'evaluation' => [
                'completion' => self::series($charts['evaluation']['completion'], 'status', 'total', self::EVALUATION_STATUS_LABELS),
                'by_category' => self::series($charts['evaluation']['byCategory'], 'category', 'average_rating'),
            ],
            'reports' => [
                'funnel' => self::series($charts['reports']['funnel'], 'status', 'total', self::REPORT_STATUS_LABELS),
                'submission_trend' => array_map(fn (array $row) => [
                    'date' => $row['date'],
                    'value' => $row['total'],
                ], $charts['reports']['submissionTrend']),
            ],
        ]);
    }

    /**
     * Scope-check a Supervisor's filters *before* resolveFilters() runs its
     * `exists` rules, so a missing id and another supervisor's id look the
     * same (403) and can't be used to probe which ids exist. supervisor_id
     * is dropped: resolveFilters() forces it to the Supervisor anyway.
     */
    private function guardSupervisorScope(AnalyticsFiltersRequest $request, User $user): void
    {
        $request->query->remove('supervisor_id');

        $studentId = $request->validated('student_id');
        $companyId = $request->validated('company_id');

        if ($studentId !== null) {
            abort_unless($user->supervisedStudents()->whereKey((int) $studentId)->exists(), 403);
        }

        if ($companyId !== null) {
            abort_unless($user->supervisedStudents()->where('company_id', (int) $companyId)->exists(), 403);
        }
    }

    /**
     * Options for the filter pickers. Built from scoped data, so a
     * Supervisor only ever gets their own students and their companies, and
     * only themselves as supervisor.
     */
    public function filterOptions(Request $request): JsonResponse
    {
        $options = $this->analytics->filterOptions($request->user());

        return response()->json([
            'companies' => $options['companies']->map(fn (Company $company) => [
                'id' => $company->id,
                'name' => $company->company_name,
            ])->values()->all(),
            'supervisors' => $options['supervisors']->map(fn (User $supervisor) => [
                'id' => $supervisor->id,
                'name' => $supervisor->name,
            ])->values()->all(),
            'students' => $options['students']->map(fn (Student $student) => [
                'id' => $student->id,
                'name' => $student->user?->name,
                'student_number' => $student->student_number,
            ])->values()->all(),
        ]);
    }

    /**
     * Category chart rows as `{key, label, value}`.
     *
     * @param  array<int, array<string, mixed>>  $rows
     * @param  array<string, string>  $labels
     * @return array<int, array{key: mixed, label: mixed, value: mixed}>
     */
    private static function series(array $rows, string $keyField, string $valueField, array $labels = []): array
    {
        return array_map(fn (array $row) => [
            'key' => $row[$keyField],
            'label' => $labels[$row[$keyField]] ?? $row[$keyField],
            'value' => $row[$valueField],
        ], $rows);
    }
}
