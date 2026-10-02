<?php

namespace Tests\Feature;

use App\Models\Attendance;
use App\Models\Student;
use App\Models\User;
use App\Services\AttendanceService;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Rendered hours credited to a student must be positive (time-out minus
 * time-in). Carbon 3's diffIn* methods are signed, so the argument order
 * matters.
 */
class RenderedHoursTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);
    }

    public function test_rendered_hours_helper_is_positive(): void
    {
        $this->assertSame(9.0, AttendanceService::renderedHours('2026-10-01', '08:00:00', '17:00:00'));
        $this->assertSame(3.5, AttendanceService::renderedHours('2026-10-01', '16:30', '20:00'));
    }

    public function test_approving_a_time_out_credits_positive_hours(): void
    {
        $supervisor = User::factory()->create(['role_id' => 3]);
        $student = Student::factory()->create(['supervisor_id' => $supervisor->id]);
        $attendance = Attendance::factory()->create([
            'student_id' => $student->id,
            'date' => today()->subDay()->toDateString(),
            'time_in' => '08:00:00',
            'time_in_status' => 'approved',
            'time_out' => '17:00:00',
            'time_out_status' => 'pending',
        ]);

        $this->actingAs($supervisor)
            ->patch("/attendance-approvals/{$attendance->id}/approve-time-out")
            ->assertRedirect();

        $this->assertEquals(9.0, $attendance->fresh()->rendered_hours);
    }

    public function test_manual_monitoring_entry_credits_positive_hours(): void
    {
        $supervisor = User::factory()->create(['role_id' => 3]);
        $student = Student::factory()->create(['supervisor_id' => $supervisor->id]);

        $this->actingAs($supervisor)
            ->post("/attendance-monitoring/{$student->id}/attendances", [
                'date' => '2026-09-15',
                'time_in' => '08:00',
                'time_out' => '12:30',
            ])
            ->assertSessionHasNoErrors();

        $this->assertEquals(4.5, $student->attendances()->first()->rendered_hours);
    }
}
