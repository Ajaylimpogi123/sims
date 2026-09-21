<?php

namespace Tests\Feature;

use App\Models\Attendance;
use App\Models\Student;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class AttendanceApprovalTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);
    }

    private function pendingTimeIn(Student $student): Attendance
    {
        return Attendance::factory()->create([
            'student_id' => $student->id,
            'date' => today()->toDateString(),
            'time_in' => '08:00:00',
            'time_in_status' => 'pending',
            'time_out' => null,
            'time_out_status' => null,
        ]);
    }

    private function pendingTimeOut(Student $student): Attendance
    {
        // A different date than pendingTimeIn() so both can coexist for the
        // same student without tripping the attendances(student_id, date)
        // unique constraint.
        return Attendance::factory()->create([
            'student_id' => $student->id,
            'date' => today()->subDay()->toDateString(),
            'time_in' => '08:00:00',
            'time_in_status' => 'approved',
            'time_out' => '17:00:00',
            'time_out_status' => 'pending',
        ]);
    }

    public function test_coordinator_can_no_longer_view_pending_approvals(): void
    {
        $coordinator = User::factory()->create(['role_id' => 2]);

        $this->actingAs($coordinator)
            ->get('/attendance-approvals')
            ->assertForbidden();
    }

    public function test_coordinator_can_no_longer_approve_or_reject_attendance(): void
    {
        $coordinator = User::factory()->create(['role_id' => 2]);
        $student = Student::factory()->create();
        $timeIn = $this->pendingTimeIn($student);
        $timeOut = $this->pendingTimeOut($student);

        $this->actingAs($coordinator)
            ->patch("/attendance-approvals/{$timeIn->id}/approve-time-in")
            ->assertForbidden();

        $this->actingAs($coordinator)
            ->patch("/attendance-approvals/{$timeIn->id}/reject-time-in")
            ->assertForbidden();

        $this->actingAs($coordinator)
            ->patch("/attendance-approvals/{$timeOut->id}/approve-time-out")
            ->assertForbidden();

        $this->actingAs($coordinator)
            ->patch("/attendance-approvals/{$timeOut->id}/reject-time-out")
            ->assertForbidden();
    }

    public function test_supervisor_only_sees_pending_requests_for_their_own_students(): void
    {
        $supervisor = User::factory()->create(['role_id' => 3]);
        $otherSupervisor = User::factory()->create(['role_id' => 3]);

        $ownStudent = Student::factory()->create(['supervisor_id' => $supervisor->id]);
        $otherStudent = Student::factory()->create(['supervisor_id' => $otherSupervisor->id]);

        $this->pendingTimeIn($ownStudent);
        $this->pendingTimeIn($otherStudent);

        $this->actingAs($supervisor)
            ->get('/attendance-approvals')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('AttendanceApprovals/Index')
                ->has('attendances', 1)
                ->where('attendances.0.student_id', $ownStudent->id)
            );
    }

    public function test_supervisor_can_approve_and_reject_their_own_students_attendance(): void
    {
        $supervisor = User::factory()->create(['role_id' => 3]);
        $student = Student::factory()->create(['supervisor_id' => $supervisor->id]);
        $timeIn = $this->pendingTimeIn($student);

        $response = $this->actingAs($supervisor)->patch(
            "/attendance-approvals/{$timeIn->id}/approve-time-in",
        );

        $response->assertRedirect(route('attendance-approvals.index', absolute: false));
        $this->assertDatabaseHas('attendances', [
            'id' => $timeIn->id,
            'time_in_status' => 'approved',
        ]);

        $timeOut = $this->pendingTimeOut($student);

        $this->actingAs($supervisor)
            ->patch("/attendance-approvals/{$timeOut->id}/reject-time-out", [
                'reason' => 'Needs correction',
            ])
            ->assertRedirect(route('attendance-approvals.index', absolute: false));

        $this->assertDatabaseHas('attendances', [
            'id' => $timeOut->id,
            'time_out_status' => 'rejected',
            'time_out_rejection_reason' => 'Needs correction',
        ]);
    }

    public function test_supervisor_cannot_act_on_another_supervisors_student_via_direct_id(): void
    {
        $supervisor = User::factory()->create(['role_id' => 3]);
        $otherSupervisor = User::factory()->create(['role_id' => 3]);
        $otherStudent = Student::factory()->create(['supervisor_id' => $otherSupervisor->id]);

        $timeIn = $this->pendingTimeIn($otherStudent);
        $timeOut = $this->pendingTimeOut($otherStudent);

        $this->actingAs($supervisor)
            ->patch("/attendance-approvals/{$timeIn->id}/approve-time-in")
            ->assertForbidden();

        $this->actingAs($supervisor)
            ->patch("/attendance-approvals/{$timeIn->id}/reject-time-in")
            ->assertForbidden();

        $this->actingAs($supervisor)
            ->patch("/attendance-approvals/{$timeOut->id}/approve-time-out")
            ->assertForbidden();

        $this->actingAs($supervisor)
            ->patch("/attendance-approvals/{$timeOut->id}/reject-time-out")
            ->assertForbidden();

        $this->assertDatabaseHas('attendances', [
            'id' => $timeIn->id,
            'time_in_status' => 'pending',
        ]);
        $this->assertDatabaseHas('attendances', [
            'id' => $timeOut->id,
            'time_out_status' => 'pending',
        ]);
    }

    public function test_admin_sees_and_can_act_on_every_students_pending_requests(): void
    {
        $admin = User::factory()->create(['role_id' => 4]);
        $supervisor = User::factory()->create(['role_id' => 3]);
        $student = Student::factory()->create(['supervisor_id' => $supervisor->id]);
        $timeIn = $this->pendingTimeIn($student);

        $this->actingAs($admin)
            ->get('/attendance-approvals')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->has('attendances', 1));

        $response = $this->actingAs($admin)->patch(
            "/attendance-approvals/{$timeIn->id}/approve-time-in",
        );

        $response->assertRedirect(route('attendance-approvals.index', absolute: false));
        $this->assertDatabaseHas('attendances', [
            'id' => $timeIn->id,
            'time_in_status' => 'approved',
        ]);
    }
}
