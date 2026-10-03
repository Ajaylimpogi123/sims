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

        $summary = $this->analytics->studentSummary($student);

        return Inertia::render('Dashboard/Index', [
            'roleId' => 1,
            'student' => [
                'internship_status' => $summary['internship_status'],
                'company_name' => $summary['company']?->company_name,
                'supervisor_name' => $summary['supervisor']?->name,
            ],
            'hours' => $summary['hours'],
            'todayAttendance' => $summary['todayAttendance'],
            'pendingReportsCount' => $summary['reportCounts']['pending'],
            'recentReports' => $summary['recentReports'],
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
        $supervisedStudents = $this->analytics->supervisedStudentsProgress($user);

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
            'supervisedStudents' => $supervisedStudents,
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
            ...$this->analytics->charts($user, $filters),
        ];
    }
}
