<?php

namespace Tests\Feature;

use App\Models\InternshipReport;
use App\Models\Student;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Defense-in-depth coverage for Finding 1: a Student-role user should never
 * be able to originate without a matching Student profile row via the
 * admin-driven User Management flow (see RegistrationTest and
 * UserManagementTest for that root-cause fix). These tests instead cover the
 * belt-and-suspenders guard: even if a role_id=1 user with no Student row
 * exists for some other reason (direct DB manipulation, a future admin
 * flow), the student-only self-service pages must degrade gracefully
 * instead of hard-crashing with a 500.
 */
class StudentRoleWithoutProfileGuardTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);
    }

    private function studentRoleUserWithNoProfile(): User
    {
        return User::factory()->create(['role_id' => 1]);
    }

    public function test_attendance_index_does_not_crash_for_a_student_role_user_with_no_profile(): void
    {
        $user = $this->studentRoleUserWithNoProfile();

        $response = $this->actingAs($user)->get('/my-attendance');

        $response->assertRedirect(route('dashboard', absolute: false));
        $response->assertSessionHas('error');
    }

    public function test_attendance_time_in_does_not_crash_for_a_student_role_user_with_no_profile(): void
    {
        $user = $this->studentRoleUserWithNoProfile();

        $response = $this->actingAs($user)->post('/my-attendance/time-in');

        $response->assertRedirect(route('dashboard', absolute: false));
        $response->assertSessionHas('error');
    }

    public function test_attendance_time_out_does_not_crash_for_a_student_role_user_with_no_profile(): void
    {
        $user = $this->studentRoleUserWithNoProfile();

        $response = $this->actingAs($user)->post('/my-attendance/time-out');

        $response->assertRedirect(route('dashboard', absolute: false));
        $response->assertSessionHas('error');
    }

    public function test_attendance_emergency_time_out_does_not_crash_for_a_student_role_user_with_no_profile(): void
    {
        $user = $this->studentRoleUserWithNoProfile();

        $response = $this->actingAs($user)->post('/my-attendance/emergency-time-out', [
            'note' => 'Trying to use emergency time-out with no profile.',
        ]);

        $response->assertRedirect(route('dashboard', absolute: false));
        $response->assertSessionHas('error');
    }

    public function test_reports_index_does_not_crash_for_a_student_role_user_with_no_profile(): void
    {
        $user = $this->studentRoleUserWithNoProfile();

        $response = $this->actingAs($user)->get('/my-reports');

        $response->assertRedirect(route('dashboard', absolute: false));
        $response->assertSessionHas('error');
    }

    public function test_reports_store_does_not_crash_for_a_student_role_user_with_no_profile(): void
    {
        $user = $this->studentRoleUserWithNoProfile();

        $response = $this->actingAs($user)->post('/my-reports', [
            'type' => 'daily',
            'period_start' => '2026-01-05',
            'period_end' => '2026-01-05',
            'content' => 'Attempting to submit with no profile.',
        ]);

        $response->assertRedirect(route('dashboard', absolute: false));
        $response->assertSessionHas('error');
        $this->assertDatabaseMissing('internship_reports', [
            'content' => 'Attempting to submit with no profile.',
        ]);
    }

    public function test_reports_update_does_not_crash_for_a_student_role_user_with_no_profile(): void
    {
        $user = $this->studentRoleUserWithNoProfile();
        $report = InternshipReport::factory()->create([
            'student_id' => Student::factory()->create()->id,
        ]);

        $response = $this->actingAs($user)->patch("/my-reports/{$report->id}", [
            'type' => 'daily',
            'period_start' => '2026-01-05',
            'period_end' => '2026-01-05',
            'content' => 'Attempting to update with no profile.',
        ]);

        $response->assertRedirect(route('dashboard', absolute: false));
        $response->assertSessionHas('error');
    }

    public function test_reports_destroy_does_not_crash_for_a_student_role_user_with_no_profile(): void
    {
        $user = $this->studentRoleUserWithNoProfile();
        $report = InternshipReport::factory()->create([
            'student_id' => Student::factory()->create()->id,
        ]);

        $response = $this->actingAs($user)->delete("/my-reports/{$report->id}");

        $response->assertRedirect(route('dashboard', absolute: false));
        $response->assertSessionHas('error');
        $this->assertDatabaseHas('internship_reports', ['id' => $report->id]);
    }
}
