<?php

namespace Tests\Feature;

use App\Models\Attendance;
use App\Models\Student;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AttendanceEmergencyTimeOutTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);
    }

    private function studentUser(): User
    {
        $user = User::factory()->create(['role_id' => 1]);
        Student::factory()->create(['user_id' => $user->id]);

        return $user;
    }

    public function test_emergency_time_out_succeeds_when_time_in_exists_but_is_not_yet_approved(): void
    {
        $user = $this->studentUser();
        $student = $user->student;

        Attendance::factory()->create([
            'student_id' => $student->id,
            'date' => today()->toDateString(),
            'time_in' => '08:00:00',
            'time_in_status' => 'pending',
            'time_out' => null,
            'time_out_status' => null,
        ]);

        $response = $this->actingAs($user)->post('/my-attendance/emergency-time-out', [
            'note' => 'Family emergency, had to leave.',
        ]);

        $response->assertRedirect(route('attendance.index', absolute: false));
        $response->assertSessionHas('success');
        $this->assertDatabaseHas('attendances', [
            'student_id' => $student->id,
            'date' => today()->toDateString(),
            'time_out_status' => 'pending',
            'is_emergency' => true,
        ]);
    }

    public function test_emergency_time_out_is_blocked_when_no_time_in_exists_yet(): void
    {
        $user = $this->studentUser();
        $student = $user->student;

        $response = $this->actingAs($user)->post('/my-attendance/emergency-time-out', [
            'note' => 'Trying to skip time-in entirely.',
        ]);

        $response->assertRedirect(route('attendance.index', absolute: false));
        $response->assertSessionHas('error', 'You must time in before using emergency time-out.');

        $this->assertDatabaseMissing('attendances', [
            'student_id' => $student->id,
            'date' => today()->toDateString(),
        ]);
    }

    public function test_emergency_time_out_succeeds_when_time_in_is_already_approved(): void
    {
        $user = $this->studentUser();
        $student = $user->student;

        Attendance::factory()->create([
            'student_id' => $student->id,
            'date' => today()->toDateString(),
            'time_in' => '08:00:00',
            'time_in_status' => 'approved',
            'time_out' => null,
            'time_out_status' => null,
        ]);

        $response = $this->actingAs($user)->post('/my-attendance/emergency-time-out', [
            'note' => 'Need to leave early.',
        ]);

        $response->assertRedirect(route('attendance.index', absolute: false));
        $response->assertSessionHas('success');
    }

    public function test_emergency_time_out_is_blocked_when_time_in_was_rejected(): void
    {
        // Regression test: unlike the normal time-out flow (which requires
        // time_in_status === 'approved'), emergency time-out previously only
        // checked that time_in was non-empty, letting a rejected time-in be
        // "fixed" via an approvable emergency time-out request.
        $user = $this->studentUser();
        $student = $user->student;

        Attendance::factory()->create([
            'student_id' => $student->id,
            'date' => today()->toDateString(),
            'time_in' => '08:00:00',
            'time_in_status' => 'rejected',
            'time_out' => null,
            'time_out_status' => null,
        ]);

        $response = $this->actingAs($user)->post('/my-attendance/emergency-time-out', [
            'note' => 'Trying to work around a rejected time-in.',
        ]);

        $response->assertRedirect(route('attendance.index', absolute: false));
        $response->assertSessionHas('error');
        $this->assertDatabaseHas('attendances', [
            'student_id' => $student->id,
            'date' => today()->toDateString(),
            'time_in_status' => 'rejected',
            'time_out_status' => null,
        ]);
    }
}
