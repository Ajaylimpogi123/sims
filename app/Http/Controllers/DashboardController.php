<?php

namespace App\Http\Controllers;

use App\Models\Attendance;
use App\Models\Company;
use App\Models\InternshipReport;
use App\Models\Student;
use App\Models\User;
use App\Services\DashboardAnalyticsService;
use App\Services\NotificationService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    public function __construct(
        private DashboardAnalyticsService $analytics,
        private NotificationService $notifications,
    ) {}

    public function index(Request $request): Response
    {
        /** @var User $user */
        $user = Auth::user();

        return match ((int) $user->role_id) {
            1 => $this->studentDashboard($user),
            2 => $this->coordinatorDashboard($user, $request),
            3 => $this->supervisorDashboard($user, $request),
            4 => $this->adminDashboard($user, $request),
            default => Inertia::render('Dashboard/Index', [
                'roleId' => $user->role_id,
            ]),
        };
    }

    private function studentDashboard(User $user): Response
    {
        $student = $user->student;

        if (! $student) {
            return Inertia::render('Dashboard/Index', [
                'roleId' => 1,
                'noProfile' => true,
            ]);
        }

        $renderedHours = (float) $student->attendances()->sum('rendered_hours');
        $requiredHours = $student->required_hours;

        $todayAttendance = $student->attendances()
            ->where('date', today()->toDateString())
            ->first();

        $recentReports = $student->internshipReports()
            ->orderByDesc('period_start')
            ->limit(5)
            ->get(['id', 'type', 'period_start', 'period_end', 'status']);

        return Inertia::render('Dashboard/Index', [
            'roleId' => 1,
            'student' => [
                'internship_status' => $student->internship_status,
                'company_name' => $student->company?->company_name,
                'supervisor_name' => $student->supervisor?->name,
            ],
            'hours' => [
                'rendered' => $renderedHours,
                'required' => $requiredHours,
                'remaining' => $requiredHours !== null
                    ? max($requiredHours - $renderedHours, 0)
                    : null,
            ],
            'todayAttendance' => $todayAttendance,
            'pendingReportsCount' => $student->internshipReports()->where('status', 'pending')->count(),
            'recentReports' => $recentReports,
        ]);
    }

    private function adminDashboard(User $user, Request $request): Response
    {
        return Inertia::render('Dashboard/Index', [
            'roleId' => 4,
            // Legacy shape kept for backward compatibility with anything
            // still reading `counts` directly.
            'counts' => [
                'students' => Student::count(),
                'companies' => Company::where('status', 'active')->count(),
                'pendingApprovals' => Attendance::query()
                    ->where(fn ($query) => $query
                        ->where('time_in_status', 'pending')
                        ->orWhere('time_out_status', 'pending'))
                    ->count(),
                'pendingReportReviews' => InternshipReport::where('status', 'pending')->count(),
            ],
            'kpis' => $this->analytics->adminKpis(),
            'actionItems' => $this->analytics->actionItems($user),
            'recentActivity' => $this->notifications->withUrls($this->notifications->recentActivity($user), $user),
            'filterOptions' => $this->analytics->filterOptions($user),
            'analytics' => Inertia::defer(fn () => $this->analyticsPayload($user, $request)),
        ]);
    }

    private function coordinatorDashboard(User $user, Request $request): Response
    {
        return Inertia::render('Dashboard/Index', [
            'roleId' => 2,
            'counts' => [
                'students' => Student::count(),
                'companies' => Company::where('status', 'active')->count(),
                'pendingApprovals' => Attendance::query()
                    ->where(fn ($query) => $query
                        ->where('time_in_status', 'pending')
                        ->orWhere('time_out_status', 'pending'))
                    ->count(),
                'pendingReportReviews' => InternshipReport::where('status', 'pending')->count(),
            ],
            'kpis' => $this->analytics->coordinatorKpis(),
            'actionItems' => $this->analytics->actionItems($user),
            'recentActivity' => $this->notifications->withUrls($this->notifications->recentActivity($user), $user),
            'filterOptions' => $this->analytics->filterOptions($user),
            'analytics' => Inertia::defer(fn () => $this->analyticsPayload($user, $request)),
        ]);
    }

    private function supervisorDashboard(User $user, Request $request): Response
    {
        $supervisedStudents = $user->supervisedStudents()
            ->with('user:id,name')
            ->withSum('attendances as total_rendered_hours', 'rendered_hours')
            ->get();

        $pendingReportReviews = InternshipReport::query()
            ->whereHas('student', fn ($query) => $query->where('supervisor_id', $user->id))
            ->where('status', 'pending')
            ->count();

        return Inertia::render('Dashboard/Index', [
            'roleId' => 3,
            'counts' => [
                'supervisedStudents' => $supervisedStudents->count(),
                'pendingReportReviews' => $pendingReportReviews,
            ],
            'supervisedStudents' => $supervisedStudents->map(fn (Student $student) => [
                'id' => $student->id,
                'name' => $student->user?->name,
                'internship_status' => $student->internship_status,
                'rendered_hours' => (float) ($student->total_rendered_hours ?? 0),
                'required_hours' => $student->required_hours,
            ]),
            'kpis' => $this->analytics->supervisorKpis($user),
            'actionItems' => $this->analytics->actionItems($user),
            'recentActivity' => $this->notifications->withUrls($this->notifications->recentActivity($user), $user),
            'filterOptions' => $this->analytics->filterOptions($user),
            'analytics' => Inertia::defer(fn () => $this->analyticsPayload($user, $request)),
        ]);
    }

    /**
     * Chart data for the Analytics tab — shipped as an Inertia::defer()
     * prop so the initial dashboard load stays light. Filters are resolved
     * (and Supervisor-scoped) fresh on every request, including deferred
     * reloads, so a Supervisor can never widen their own scope via query
     * params.
     */
    private function analyticsPayload(User $user, Request $request): array
    {
        $filters = $this->analytics->resolveFilters($request, $user);

        return [
            'filters' => $filters,
            'internship' => [
                'statusBreakdown' => $this->analytics->internshipStatusBreakdown($user, $filters),
                'studentsByCompany' => $this->analytics->studentsByCompany($user, $filters),
                'completionProgressBuckets' => $this->analytics->completionProgressBuckets($user, $filters),
            ],
            'attendance' => [
                'outcomes' => $this->analytics->attendanceOutcomes($user, $filters),
                'trend' => $this->analytics->attendanceTrend($user, $filters),
                'frequentRejections' => $this->analytics->frequentRejections($user, $filters),
            ],
            'evaluation' => [
                'completion' => $this->analytics->evaluationCompletion($user, $filters),
                'byCategory' => $this->analytics->evaluationByCategory($user, $filters),
            ],
            'reports' => [
                'funnel' => $this->analytics->reportsFunnel($user, $filters),
                'submissionTrend' => $this->analytics->reportSubmissionTrend($user, $filters),
            ],
        ];
    }
}
