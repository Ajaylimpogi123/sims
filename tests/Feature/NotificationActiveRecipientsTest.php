<?php

namespace Tests\Feature;

use App\Models\Attendance;
use App\Models\InternshipReport;
use App\Models\Notification;
use App\Models\Student;
use App\Models\User;
use App\Services\NotificationService;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Staff notifications (role-wide, and to a student's supervisor) are only
 * sent to active accounts. Notifications to the student themselves are
 * still sent while they're deactivated.
 */
class NotificationActiveRecipientsTest extends TestCase
{
    use RefreshDatabase;

    private NotificationService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);
        $this->service = app(NotificationService::class);
    }

    private function user(int $roleId, string $status = 'active'): User
    {
        return User::factory()->create(['role_id' => $roleId, 'status' => $status]);
    }

    private function sent(User $user, string $type): int
    {
        return Notification::where('user_id', $user->id)->where('type', $type)->count();
    }

    public function test_attendance_pending_skips_inactive_admins_and_an_inactive_supervisor(): void
    {
        $admin = $this->user(User::ROLE_ADMIN);
        $inactiveAdmin = $this->user(User::ROLE_ADMIN, 'inactive');
        $inactiveSupervisor = $this->user(User::ROLE_SUPERVISOR, 'inactive');
        $student = Student::factory()->create(['supervisor_id' => $inactiveSupervisor->id]);
        $attendance = Attendance::factory()->create(['student_id' => $student->id, 'time_in_status' => 'pending']);

        $this->service->attendanceSubmitted($attendance, 'time_in');

        $this->assertSame(1, $this->sent($admin, 'attendance_pending'));
        $this->assertSame(0, $this->sent($inactiveAdmin, 'attendance_pending'));
        $this->assertSame(0, $this->sent($inactiveSupervisor, 'attendance_pending'));
        $this->assertNull($this->service->attendanceSupervisor($attendance));
    }

    public function test_attendance_pending_still_reaches_an_active_supervisor(): void
    {
        $supervisor = $this->user(User::ROLE_SUPERVISOR);
        $student = Student::factory()->create(['supervisor_id' => $supervisor->id]);
        $attendance = Attendance::factory()->create(['student_id' => $student->id, 'time_in_status' => 'pending']);

        $this->service->attendanceSubmitted($attendance, 'time_in');

        $this->assertSame(1, $this->sent($supervisor, 'attendance_pending'));
    }

    public function test_report_submitted_skips_inactive_staff_and_an_inactive_supervisor(): void
    {
        $coordinator = $this->user(User::ROLE_COORDINATOR);
        $admin = $this->user(User::ROLE_ADMIN);
        $inactiveCoordinator = $this->user(User::ROLE_COORDINATOR, 'inactive');
        $inactiveAdmin = $this->user(User::ROLE_ADMIN, 'inactive');
        $inactiveSupervisor = $this->user(User::ROLE_SUPERVISOR, 'inactive');
        $student = Student::factory()->create(['supervisor_id' => $inactiveSupervisor->id]);
        $report = InternshipReport::factory()->create(['student_id' => $student->id]);

        $this->service->reportSubmitted($report);

        $this->assertSame(1, $this->sent($coordinator, 'report_submitted'));
        $this->assertSame(1, $this->sent($admin, 'report_submitted'));
        $this->assertSame(0, $this->sent($inactiveCoordinator, 'report_submitted'));
        $this->assertSame(0, $this->sent($inactiveAdmin, 'report_submitted'));
        $this->assertSame(0, $this->sent($inactiveSupervisor, 'report_submitted'));
    }

    public function test_student_registered_skips_inactive_admins(): void
    {
        $admin = $this->user(User::ROLE_ADMIN);
        $inactiveAdmin = $this->user(User::ROLE_ADMIN, 'inactive');
        $studentUser = $this->user(User::ROLE_STUDENT);

        $this->service->studentRegistered($studentUser, '2026-00001');

        $this->assertSame(1, $this->sent($admin, 'student_registered'));
        $this->assertSame(0, $this->sent($inactiveAdmin, 'student_registered'));
    }

    public function test_student_registration_through_the_website_skips_inactive_admins(): void
    {
        $admin = $this->user(User::ROLE_ADMIN);
        $inactiveAdmin = $this->user(User::ROLE_ADMIN, 'inactive');

        $this->post('/register', [
            'name' => 'New Student',
            'email' => 'new.student@example.com',
            'student_number' => '2026-00042',
            'course' => 'BSIT',
            'section' => 'A',
            'password' => 'password',
            'password_confirmation' => 'password',
        ]);

        $this->assertSame(1, $this->sent($admin, 'student_registered'));
        $this->assertSame(0, $this->sent($inactiveAdmin, 'student_registered'));
    }

    public function test_assignment_updated_skips_an_inactive_supervisor_but_still_tells_an_inactive_student(): void
    {
        $inactiveSupervisor = $this->user(User::ROLE_SUPERVISOR, 'inactive');
        $student = Student::factory()->create(['supervisor_id' => $inactiveSupervisor->id]);
        $student->user->forceFill(['status' => 'inactive'])->save();

        $this->service->assignmentUpdated($student->fresh());

        $this->assertSame(1, $this->sent($student->user, 'assignment_updated'));
        $this->assertSame(0, $this->sent($inactiveSupervisor, 'assignment_updated'));
    }

    public function test_student_targeted_notifications_still_reach_an_inactive_student(): void
    {
        $student = Student::factory()->create();
        $student->user->forceFill(['status' => 'inactive'])->save();
        $report = InternshipReport::factory()->create(['student_id' => $student->id, 'status' => 'reviewed']);
        $attendance = Attendance::factory()->create(['student_id' => $student->id]);

        $this->service->reportReviewed($report);
        $this->service->attendanceReviewed($attendance, 'time_in', 'approved');

        $this->assertSame(1, $this->sent($student->user, 'report_reviewed'));
        $this->assertSame(1, $this->sent($student->user, 'attendance_reviewed'));
    }
}
