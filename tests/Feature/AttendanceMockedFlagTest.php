<?php

namespace Tests\Feature;

use App\Models\Attendance;
use App\Models\Student;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * Fake-GPS flag (time_in_mocked / time_out_mocked): only the mobile app can
 * set it. The website stores null (unknown) and shows the flag to
 * reviewers through the attendance records it already passes as props.
 */
class AttendanceMockedFlagTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);
        Storage::fake('local');
    }

    private function payload(array $overrides = []): array
    {
        return array_merge([
            'photo' => UploadedFile::fake()->image('capture.jpg'),
            'latitude' => '10.5',
            'longitude' => '122.9',
            'accuracy' => '8',
        ], $overrides);
    }

    public function test_website_submission_stores_unknown_and_ignores_a_posted_flag(): void
    {
        $user = User::factory()->create(['role_id' => 1]);
        $student = Student::factory()->create(['user_id' => $user->id]);

        $this->actingAs($user)->post('/my-attendance/time-in', $this->payload(['mocked' => '1']))
            ->assertSessionHas('success');

        $this->assertNull(Attendance::where('student_id', $student->id)->sole()->time_in_mocked);
    }

    public function test_website_resubmission_resets_a_flag_left_by_the_app(): void
    {
        $user = User::factory()->create(['role_id' => 1]);
        $student = Student::factory()->create(['user_id' => $user->id]);
        $record = Attendance::factory()->create([
            'student_id' => $student->id,
            'date' => today()->toDateString(),
            'time_in_status' => 'rejected',
            'time_in_mocked' => true,
            'time_out' => null,
            'time_out_status' => null,
            'rendered_hours' => null,
        ]);

        $this->actingAs($user)->post('/my-attendance/time-in', $this->payload())->assertSessionHas('success');

        $this->assertNull($record->refresh()->time_in_mocked);
    }

    public function test_pending_approvals_props_carry_the_flags(): void
    {
        $supervisor = User::factory()->create(['role_id' => 3]);
        $student = Student::factory()->create(['supervisor_id' => $supervisor->id]);
        Attendance::factory()->pendingTimeIn()->create([
            'student_id' => $student->id,
            'time_in_mocked' => true,
        ]);

        $this->actingAs($supervisor)->get('/attendance-approvals')
            ->assertInertia(fn (Assert $page) => $page
                ->component('AttendanceApprovals/Index')
                ->where('attendances.0.time_in_mocked', true)
                ->where('attendances.0.time_out_mocked', null));
    }

    public function test_attendance_monitoring_props_carry_the_flags(): void
    {
        $student = Student::factory()->create();
        Attendance::factory()->create([
            'student_id' => $student->id,
            'time_in_mocked' => false,
            'time_out_mocked' => true,
        ]);

        $this->actingAs(User::factory()->create(['role_id' => 2]))->get('/attendance-monitoring')
            ->assertInertia(fn (Assert $page) => $page
                ->component('AttendanceMonitoring/Index')
                ->where('students.0.attendances.0.time_in_mocked', false)
                ->where('students.0.attendances.0.time_out_mocked', true));
    }

    public function test_clearing_a_leg_in_monitoring_clears_its_flag(): void
    {
        $supervisor = User::factory()->create(['role_id' => 3]);
        $student = Student::factory()->create(['supervisor_id' => $supervisor->id]);
        $record = Attendance::factory()->create([
            'student_id' => $student->id,
            'date' => today()->toDateString(),
            'time_in_mocked' => true,
            'time_out_mocked' => true,
        ]);

        $this->actingAs($supervisor)
            ->patch("/attendance-monitoring/attendances/{$record->id}", [
                'date' => today()->toDateString(),
                'time_in' => '08:00',
                'time_out' => '',
            ])
            ->assertSessionHas('success');

        $record->refresh();
        $this->assertTrue($record->time_in_mocked);
        $this->assertNull($record->time_out_mocked);
    }
}
