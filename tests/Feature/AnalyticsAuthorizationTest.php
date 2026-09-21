<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Student;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * Modeled on tests/Feature/SupervisorMonitoringTest.php's isolation-test
 * style: direct query-param manipulation against the dashboard/analytics
 * endpoint, proving Supervisor scoping is enforced server-side (silent
 * override for supervisor_id, 403 for company_id/student_id that don't
 * belong to them) and that Coordinator/Admin are correctly NOT scoped.
 */
class AnalyticsAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);
    }

    private function fetchAnalytics(User $user, array $query = []): TestResponse
    {
        $queryString = $query ? ('?'.http_build_query($query)) : '';

        return $this->actingAs($user)
            ->withHeaders([
                'X-Inertia-Partial-Data' => 'analytics',
                'X-Inertia-Partial-Component' => 'Dashboard/Index',
            ])
            ->get('/dashboard'.$queryString);
    }

    public function test_supervisor_id_query_param_is_silently_overridden_for_a_supervisor(): void
    {
        $supervisor = User::factory()->create(['role_id' => 3]);
        $otherSupervisor = User::factory()->create(['role_id' => 3]);

        $ownStudent = Student::factory()->create(['supervisor_id' => $supervisor->id]);
        Student::factory()->create(['supervisor_id' => $otherSupervisor->id]);

        // Attempt to widen scope to the other supervisor via the raw param.
        $this->fetchAnalytics($supervisor, ['supervisor_id' => $otherSupervisor->id])
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('analytics.filters.supervisor_id', $supervisor->id)
                ->has('analytics.internship.statusBreakdown', 1)
            );
    }

    public function test_company_id_query_param_for_a_company_outside_supervisors_scope_is_forbidden(): void
    {
        $supervisor = User::factory()->create(['role_id' => 3]);
        $foreignCompany = Company::factory()->create();
        Student::factory()->create(['supervisor_id' => $supervisor->id]);

        $this->fetchAnalytics($supervisor, ['company_id' => $foreignCompany->id])
            ->assertForbidden();
    }

    public function test_student_id_query_param_for_a_student_outside_supervisors_scope_is_forbidden(): void
    {
        $supervisor = User::factory()->create(['role_id' => 3]);
        $otherSupervisor = User::factory()->create(['role_id' => 3]);
        $foreignStudent = Student::factory()->create(['supervisor_id' => $otherSupervisor->id]);

        $this->fetchAnalytics($supervisor, ['student_id' => $foreignStudent->id])
            ->assertForbidden();
    }

    public function test_company_id_query_param_for_supervisors_own_company_is_allowed(): void
    {
        $supervisor = User::factory()->create(['role_id' => 3]);
        $company = Company::factory()->create();
        Student::factory()->create(['supervisor_id' => $supervisor->id, 'company_id' => $company->id]);

        $this->fetchAnalytics($supervisor, ['company_id' => $company->id])
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('analytics.filters.company_id', $company->id)
            );
    }

    public function test_student_id_query_param_for_supervisors_own_student_is_allowed(): void
    {
        $supervisor = User::factory()->create(['role_id' => 3]);
        $student = Student::factory()->create(['supervisor_id' => $supervisor->id]);

        $this->fetchAnalytics($supervisor, ['student_id' => $student->id])
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('analytics.filters.student_id', $student->id)
            );
    }

    public function test_supervisors_recent_activity_excludes_another_supervisors_students_events(): void
    {
        $supervisor = User::factory()->create(['role_id' => 3]);
        $otherSupervisor = User::factory()->create(['role_id' => 3]);

        $ownStudentUser = User::factory()->create(['role_id' => 1]);
        Student::factory()->create(['user_id' => $ownStudentUser->id, 'supervisor_id' => $supervisor->id]);

        $otherStudentUser = User::factory()->create(['role_id' => 1]);
        Student::factory()->create(['user_id' => $otherStudentUser->id, 'supervisor_id' => $otherSupervisor->id]);

        app(\App\Services\NotificationService::class)->notify($ownStudentUser, 'report_reviewed', 'Own student event');
        app(\App\Services\NotificationService::class)->notify($otherStudentUser, 'report_reviewed', 'Other supervisors student event');

        $this->actingAs($supervisor)
            ->get('/dashboard')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('recentActivity', 1)
                ->where('recentActivity.0.title', 'Own student event')
            );
    }

    public function test_coordinator_and_admin_are_not_scoped_by_a_supervisor_id_filter(): void
    {
        $supervisorA = User::factory()->create(['role_id' => 3]);
        $supervisorB = User::factory()->create(['role_id' => 3]);

        Student::factory()->create(['supervisor_id' => $supervisorA->id]);
        Student::factory()->create(['supervisor_id' => $supervisorB->id]);

        foreach ([2, 4] as $roleId) {
            $staff = User::factory()->create(['role_id' => $roleId]);

            // Passing an arbitrary supervisor_id should NOT restrict what
            // Coordinator/Admin see — they're meant to see everything, this
            // filter is meaningful for them only as an explicit narrowing
            // choice, never an implicit restriction.
            $this->fetchAnalytics($staff, ['supervisor_id' => $supervisorA->id])
                ->assertOk()
                ->assertInertia(fn (Assert $page) => $page
                    ->where('analytics.filters.supervisor_id', $supervisorA->id)
                );

            // And without the filter at all, both students are visible.
            $this->fetchAnalytics($staff)
                ->assertOk()
                ->assertInertia(fn (Assert $page) => $page
                    ->has('analytics.internship.statusBreakdown', 1) // both students share the same not_started status -> 1 group
                );
        }
    }

    public function test_coordinator_and_admin_can_pass_any_company_or_student_id_without_403(): void
    {
        $company = Company::factory()->create();
        $student = Student::factory()->create(['company_id' => $company->id]);

        foreach ([2, 4] as $roleId) {
            $staff = User::factory()->create(['role_id' => $roleId]);

            $this->fetchAnalytics($staff, ['company_id' => $company->id, 'student_id' => $student->id])
                ->assertOk();
        }
    }
}
