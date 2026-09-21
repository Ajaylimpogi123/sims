<?php

namespace Tests\Feature;

use App\Models\Attendance;
use App\Models\Company;
use App\Models\Evaluation;
use App\Models\InternshipReport;
use App\Models\Student;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class DashboardTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);
    }

    public function test_student_sees_their_own_hours_summary(): void
    {
        $user = User::factory()->create(['role_id' => 1]);
        $student = Student::factory()->create([
            'user_id' => $user->id,
            'required_hours' => 100,
        ]);
        Attendance::factory()->create([
            'student_id' => $student->id,
            'rendered_hours' => 8,
        ]);

        $this->actingAs($user)
            ->get('/dashboard')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Dashboard/Index')
                ->where('roleId', 1)
                ->where('hours.rendered', 8)
                ->where('hours.required', 100)
            );
    }

    public function test_student_sees_their_assigned_company_and_supervisor_names(): void
    {
        $user = User::factory()->create(['role_id' => 1]);
        $supervisor = User::factory()->create(['role_id' => 3, 'name' => 'Jane Supervisor']);
        $company = Company::factory()->create(['company_name' => 'Acme Corp']);
        Student::factory()->create([
            'user_id' => $user->id,
            'company_id' => $company->id,
            'supervisor_id' => $supervisor->id,
        ]);

        $this->actingAs($user)
            ->get('/dashboard')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Dashboard/Index')
                ->where('student.company_name', 'Acme Corp')
                ->where('student.supervisor_name', 'Jane Supervisor')
            );
    }

    public function test_student_without_profile_sees_no_profile_notice(): void
    {
        $user = User::factory()->create(['role_id' => 1]);

        $this->actingAs($user)
            ->get('/dashboard')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Dashboard/Index')
                ->where('noProfile', true)
            );
    }

    public function test_coordinator_and_admin_see_staff_counts(): void
    {
        Company::factory()->create(['status' => 'active']);
        $students = Student::factory()->count(2)->create();
        InternshipReport::factory()->create([
            'status' => 'pending',
            'student_id' => $students->first()->id,
        ]);

        foreach ([2, 4] as $roleId) {
            $user = User::factory()->create(['role_id' => $roleId]);

            $this->actingAs($user)
                ->get('/dashboard')
                ->assertOk()
                ->assertInertia(fn (Assert $page) => $page
                    ->component('Dashboard/Index')
                    ->where('roleId', $roleId)
                    ->where('counts.students', 2)
                    ->where('counts.companies', 1)
                    ->where('counts.pendingReportReviews', 1)
                );
        }
    }

    public function test_supervisor_sees_only_their_supervised_students(): void
    {
        $supervisor = User::factory()->create(['role_id' => 3]);
        $otherSupervisor = User::factory()->create(['role_id' => 3]);

        Student::factory()->create(['supervisor_id' => $supervisor->id]);
        Student::factory()->create(['supervisor_id' => $otherSupervisor->id]);

        $this->actingAs($supervisor)
            ->get('/dashboard')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Dashboard/Index')
                ->where('roleId', 3)
                ->where('counts.supervisedStudents', 1)
            );
    }

    // -----------------------------------------------------------------
    // Admin vs Coordinator divergence — today's tests above only prove
    // they see identical `counts`; the new `kpis` payload is where they
    // actually diverge now (DashboardController::adminDashboard() vs
    // coordinatorDashboard()).
    // -----------------------------------------------------------------

    public function test_admin_and_coordinator_dashboards_now_diverge_on_kpis(): void
    {
        Student::factory()->create(['company_id' => null]);

        $admin = User::factory()->create(['role_id' => 4]);
        $coordinator = User::factory()->create(['role_id' => 2]);

        $this->actingAs($admin)
            ->get('/admin-dashboard')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('kpis.totalSupervisors')
                ->has('kpis.pendingSupervisorEvaluations')
                ->missing('kpis.studentsWithoutCompany')
                ->missing('kpis.internshipCompletionProgress')
            );

        $this->actingAs($coordinator)
            ->get('/dashboard')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('kpis.studentsWithoutCompany')
                ->has('kpis.internshipCompletionProgress')
                ->missing('kpis.totalSupervisors')
                ->missing('kpis.pendingSupervisorEvaluations')
            );
    }

    // -----------------------------------------------------------------
    // Admin KPIs — exact counts against seeded known data
    // -----------------------------------------------------------------

    public function test_admin_kpi_total_students_exact_count(): void
    {
        Student::factory()->count(3)->create();
        $admin = User::factory()->create(['role_id' => 4]);

        $this->actingAs($admin)->get('/admin-dashboard')->assertInertia(fn (Assert $page) => $page
            ->where('kpis.totalStudents.value', 3)
            ->where('kpis.totalStudents.trend', null)
        );
    }

    public function test_admin_kpi_total_companies_counts_only_active(): void
    {
        Company::factory()->count(2)->create(['status' => 'active']);
        Company::factory()->create(['status' => 'inactive']);
        $admin = User::factory()->create(['role_id' => 4]);

        $this->actingAs($admin)->get('/admin-dashboard')->assertInertia(fn (Assert $page) => $page
            ->where('kpis.totalCompanies.value', 2)
        );
    }

    public function test_admin_kpi_total_supervisors_counts_only_active(): void
    {
        User::factory()->count(2)->create(['role_id' => 3, 'status' => 'active']);
        User::factory()->create(['role_id' => 3, 'status' => 'inactive']);
        $admin = User::factory()->create(['role_id' => 4]);

        $this->actingAs($admin)->get('/admin-dashboard')->assertInertia(fn (Assert $page) => $page
            ->where('kpis.totalSupervisors.value', 2)
        );
    }

    public function test_admin_kpi_active_and_completed_internship_assignments(): void
    {
        Student::factory()->count(2)->create(['internship_status' => 'ongoing']);
        Student::factory()->create(['internship_status' => 'completed']);
        Student::factory()->create(['internship_status' => 'not_started']);
        $admin = User::factory()->create(['role_id' => 4]);

        $this->actingAs($admin)->get('/admin-dashboard')->assertInertia(fn (Assert $page) => $page
            ->where('kpis.activeInternshipAssignments.value', 2)
            ->where('kpis.completedInternships.value', 1)
            ->where('kpis.studentsCurrentlyOnInternship.value', 2)
        );
    }

    public function test_admin_kpi_pending_approvals_counts_rows_with_either_leg_pending(): void
    {
        Attendance::factory()->create(); // fully approved, not pending
        Attendance::factory()->pendingTimeIn()->create();
        Attendance::factory()->create()->update(['time_out_status' => 'pending']);
        $admin = User::factory()->create(['role_id' => 4]);

        $this->actingAs($admin)->get('/admin-dashboard')->assertInertia(fn (Assert $page) => $page
            ->where('kpis.pendingApprovals.value', 2)
        );
    }

    public function test_admin_kpi_attendance_summary_present_and_rejected(): void
    {
        Attendance::factory()->create(['time_in_status' => 'approved', 'time_out_status' => 'approved']);
        Attendance::factory()->create(['time_in_status' => 'rejected', 'time_out_status' => null]);
        Attendance::factory()->create(['time_in_status' => 'approved', 'time_out_status' => 'rejected']);
        $admin = User::factory()->create(['role_id' => 4]);

        $this->actingAs($admin)->get('/admin-dashboard')->assertInertia(fn (Assert $page) => $page
            ->where('kpis.attendanceSummary.present', 2)
            ->where('kpis.attendanceSummary.rejected', 2)
        );
    }

    public function test_admin_kpi_students_nearing_completion(): void
    {
        $nearing = Student::factory()->create(['required_hours' => 100, 'internship_status' => 'ongoing']);
        Attendance::factory()->create(['student_id' => $nearing->id, 'rendered_hours' => 95]);

        $notNearing = Student::factory()->create(['required_hours' => 100, 'internship_status' => 'ongoing']);
        Attendance::factory()->create(['student_id' => $notNearing->id, 'rendered_hours' => 50]);

        $alreadyDone = Student::factory()->create(['required_hours' => 100, 'internship_status' => 'completed']);
        Attendance::factory()->create(['student_id' => $alreadyDone->id, 'rendered_hours' => 100]);

        $admin = User::factory()->create(['role_id' => 4]);

        $this->actingAs($admin)->get('/admin-dashboard')->assertInertia(fn (Assert $page) => $page
            ->where('kpis.studentsNearingCompletion.value', 1)
        );
    }

    public function test_admin_kpi_students_with_attendance_issues_uses_frequent_rejection_threshold(): void
    {
        $flagged = Student::factory()->create();
        foreach (range(0, 2) as $daysAgo) {
            Attendance::factory()->create([
                'student_id' => $flagged->id,
                'date' => today()->subDays($daysAgo)->toDateString(),
                'time_in_status' => 'rejected',
            ]);
        }

        $notFlagged = Student::factory()->create();
        foreach (range(0, 1) as $daysAgo) {
            Attendance::factory()->create([
                'student_id' => $notFlagged->id,
                'date' => today()->subDays($daysAgo)->toDateString(),
                'time_in_status' => 'rejected',
            ]);
        }

        $admin = User::factory()->create(['role_id' => 4]);

        $this->actingAs($admin)->get('/admin-dashboard')->assertInertia(fn (Assert $page) => $page
            ->where('kpis.studentsWithAttendanceIssues.value', 1)
        );
    }

    public function test_admin_kpi_pending_supervisor_evaluations_counts_drafts_only(): void
    {
        Evaluation::factory()->count(2)->create(['status' => 'draft']);
        Evaluation::factory()->submitted()->create();
        $admin = User::factory()->create(['role_id' => 4]);

        $this->actingAs($admin)->get('/admin-dashboard')->assertInertia(fn (Assert $page) => $page
            ->where('kpis.pendingSupervisorEvaluations.value', 2)
        );
    }

    // -----------------------------------------------------------------
    // Coordinator KPIs
    // -----------------------------------------------------------------

    public function test_coordinator_kpi_students_without_company(): void
    {
        Student::factory()->create(['company_id' => null]);
        Student::factory()->create(['company_id' => Company::factory()->create()->id]);
        $coordinator = User::factory()->create(['role_id' => 2]);

        $this->actingAs($coordinator)->get('/dashboard')->assertInertia(fn (Assert $page) => $page
            ->where('kpis.studentsWithoutCompany.value', 1)
        );
    }

    public function test_coordinator_kpi_internship_completion_progress_is_averaged_across_students(): void
    {
        $a = Student::factory()->create(['required_hours' => 100]);
        Attendance::factory()->create(['student_id' => $a->id, 'rendered_hours' => 50]);

        $b = Student::factory()->create(['required_hours' => 200]);
        Attendance::factory()->create(['student_id' => $b->id, 'rendered_hours' => 200]);

        $coordinator = User::factory()->create(['role_id' => 2]);

        $this->actingAs($coordinator)->get('/dashboard')->assertInertia(fn (Assert $page) => $page
            ->where('kpis.internshipCompletionProgress.value', 75)
        );
    }

    public function test_coordinator_kpi_reports_awaiting_review(): void
    {
        $student = Student::factory()->create();
        InternshipReport::factory()->create(['student_id' => $student->id, 'status' => 'pending']);
        InternshipReport::factory()->reviewed()->create(['student_id' => $student->id]);
        $coordinator = User::factory()->create(['role_id' => 2]);

        $this->actingAs($coordinator)->get('/dashboard')->assertInertia(fn (Assert $page) => $page
            ->where('kpis.reportsAwaitingReview.value', 1)
        );
    }

    // -----------------------------------------------------------------
    // Supervisor KPIs — must be scoped to their own students only
    // -----------------------------------------------------------------

    public function test_supervisor_kpis_are_exact_and_scoped_to_own_students(): void
    {
        $supervisor = User::factory()->create(['role_id' => 3]);
        $otherSupervisor = User::factory()->create(['role_id' => 3]);

        $own = Student::factory()->create(['supervisor_id' => $supervisor->id, 'internship_status' => 'ongoing']);
        Attendance::factory()->create(['student_id' => $own->id, 'date' => today(), 'time_in_status' => 'approved']);

        Student::factory()->create(['supervisor_id' => $otherSupervisor->id, 'internship_status' => 'ongoing']);

        InternshipReport::factory()->create(['student_id' => $own->id, 'status' => 'pending']);
        Evaluation::factory()->create(['student_id' => $own->id, 'supervisor_id' => $supervisor->id, 'status' => 'draft']);
        Evaluation::factory()->submitted()->create(['student_id' => $own->id, 'supervisor_id' => $otherSupervisor->id]);

        $this->actingAs($supervisor)->get('/dashboard')->assertInertia(fn (Assert $page) => $page
            ->where('kpis.assignedStudents.value', 1)
            ->where('kpis.activeInternships.value', 1)
            ->where('kpis.todaysAttendance.value', 1)
            ->where('kpis.reportsAwaitingReview.value', 1)
            ->where('kpis.pendingEvaluations.value', 1)
            ->where('kpis.completedEvaluations.value', 0)
        );
    }

    // -----------------------------------------------------------------
    // Empty database — every KPI must be 0, never null/error; every
    // trend must be null, never fabricated.
    // -----------------------------------------------------------------

    public function test_admin_dashboard_on_empty_database_returns_zeros_not_errors(): void
    {
        $admin = User::factory()->create(['role_id' => 4]);

        $this->actingAs($admin)->get('/admin-dashboard')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('kpis.totalStudents.value', 0)
            ->where('kpis.totalStudents.trend', null)
            ->where('kpis.totalCompanies.value', 0)
            ->where('kpis.totalSupervisors.value', 0)
            ->where('kpis.activeInternshipAssignments.value', 0)
            ->where('kpis.completedInternships.value', 0)
            ->where('kpis.pendingApprovals.value', 0)
            ->where('kpis.attendanceSummary.present', 0)
            ->where('kpis.attendanceSummary.rejected', 0)
            ->where('kpis.studentsCurrentlyOnInternship.value', 0)
            ->where('kpis.studentsNearingCompletion.value', 0)
            ->where('kpis.studentsWithAttendanceIssues.value', 0)
            ->where('kpis.pendingSupervisorEvaluations.value', 0)
            ->where('actionItems', [])
        );
    }

    public function test_coordinator_dashboard_on_empty_database_returns_zeros_not_errors(): void
    {
        $coordinator = User::factory()->create(['role_id' => 2]);

        $this->actingAs($coordinator)->get('/dashboard')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('kpis.totalStudents.value', 0)
            ->where('kpis.studentsWithoutCompany.value', 0)
            ->where('kpis.internshipCompletionProgress.value', 0)
            ->where('kpis.reportsAwaitingReview.value', 0)
            ->where('actionItems', [])
        );
    }

    public function test_supervisor_dashboard_on_empty_database_returns_zeros_not_errors(): void
    {
        $supervisor = User::factory()->create(['role_id' => 3]);

        $this->actingAs($supervisor)->get('/dashboard')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('kpis.assignedStudents.value', 0)
            ->where('kpis.activeInternships.value', 0)
            ->where('kpis.todaysAttendance.value', 0)
            ->where('kpis.pendingAttendanceApprovals.value', 0)
            ->where('kpis.reportsAwaitingReview.value', 0)
            ->where('kpis.pendingEvaluations.value', 0)
            ->where('kpis.completedEvaluations.value', 0)
            ->where('kpis.studentsRequiringAttention.value', 0)
            ->where('actionItems', [])
        );
    }
}
