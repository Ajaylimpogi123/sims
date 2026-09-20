<?php

namespace Tests\Feature;

use App\Models\Student;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class SupervisorMonitoringTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);
    }

    public function test_supervisor_can_view_attendance_monitoring_scoped_to_their_students(): void
    {
        $supervisor = User::factory()->create(['role_id' => 3]);
        $otherSupervisor = User::factory()->create(['role_id' => 3]);

        $ownStudent = Student::factory()->create(['supervisor_id' => $supervisor->id]);
        Student::factory()->create(['supervisor_id' => $otherSupervisor->id]);

        $this->actingAs($supervisor)
            ->get('/attendance-monitoring')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('AttendanceMonitoring/Index')
                ->has('students', 1)
                ->where('students.0.id', $ownStudent->id)
            );
    }

    public function test_supervisor_can_view_progress_monitoring_scoped_to_their_students(): void
    {
        $supervisor = User::factory()->create(['role_id' => 3]);
        $otherSupervisor = User::factory()->create(['role_id' => 3]);

        $ownStudent = Student::factory()->create(['supervisor_id' => $supervisor->id]);
        Student::factory()->create(['supervisor_id' => $otherSupervisor->id]);

        $this->actingAs($supervisor)
            ->get('/progress-monitoring')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('ProgressMonitoring/Index')
                ->has('students', 1)
                ->where('students.0.id', $ownStudent->id)
            );
    }

    public function test_supervisor_cannot_edit_attendance_for_a_student_they_do_not_supervise(): void
    {
        $supervisor = User::factory()->create(['role_id' => 3]);
        $otherSupervisor = User::factory()->create(['role_id' => 3]);

        $student = Student::factory()->create(['supervisor_id' => $otherSupervisor->id]);

        $this->actingAs($supervisor)
            ->post("/attendance-monitoring/{$student->id}/attendances", [
                'date' => '2026-01-05',
                'time_in' => '08:00',
                'time_out' => '17:00',
            ])
            ->assertForbidden();
    }

    public function test_supervisor_can_add_attendance_for_their_own_student(): void
    {
        $supervisor = User::factory()->create(['role_id' => 3]);
        $student = Student::factory()->create(['supervisor_id' => $supervisor->id]);

        $response = $this->actingAs($supervisor)->post(
            "/attendance-monitoring/{$student->id}/attendances",
            [
                'date' => '2026-01-05',
                'time_in' => '08:00',
                'time_out' => '17:00',
            ],
        );

        $response->assertRedirect(route('attendance-monitoring.index', absolute: false));
        $this->assertDatabaseHas('attendances', [
            'student_id' => $student->id,
            'date' => '2026-01-05',
        ]);
    }

    public function test_coordinator_and_admin_still_see_all_students(): void
    {
        Student::factory()->count(3)->create();

        foreach ([2, 4] as $roleId) {
            $user = User::factory()->create(['role_id' => $roleId]);

            $this->actingAs($user)
                ->get('/attendance-monitoring')
                ->assertOk()
                ->assertInertia(fn (Assert $page) => $page->has('students', 3));
        }
    }
}
