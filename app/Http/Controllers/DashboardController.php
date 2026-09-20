<?php

namespace App\Http\Controllers;

use App\Models\Attendance;
use App\Models\Company;
use App\Models\InternshipReport;
use App\Models\Student;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    public function index(): Response
    {
        /** @var User $user */
        $user = Auth::user();

        return match ((int) $user->role_id) {
            1 => $this->studentDashboard($user),
            3 => $this->supervisorDashboard($user),
            2, 4 => $this->staffDashboard(),
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

    private function staffDashboard(): Response
    {
        return Inertia::render('Dashboard/Index', [
            'roleId' => Auth::user()->role_id,
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
        ]);
    }

    private function supervisorDashboard(User $user): Response
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
        ]);
    }
}
