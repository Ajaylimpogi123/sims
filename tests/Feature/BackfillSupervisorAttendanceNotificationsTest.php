<?php

namespace Tests\Feature;

use App\Models\Attendance;
use App\Models\Notification;
use App\Models\Student;
use App\Models\User;
use App\Services\NotificationService;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BackfillSupervisorAttendanceNotificationsTest extends TestCase
{
    use RefreshDatabase;

    private User $supervisor;

    private User $admin;

    private User $coordinator;

    private Student $student;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);

        $this->supervisor = User::factory()->create(['role_id' => 3]);
        $this->admin = User::factory()->create(['role_id' => 4]);
        $this->coordinator = User::factory()->create(['role_id' => 2]);

        $studentUser = User::factory()->create(['role_id' => 1, 'name' => 'Juan Dela Cruz']);
        $this->student = Student::factory()->create([
            'user_id' => $studentUser->id,
            'supervisor_id' => $this->supervisor->id,
        ]);
    }

    private function pendingAttendance(array $attributes = []): Attendance
    {
        return Attendance::factory()->pendingTimeIn()->create(array_merge([
            'student_id' => $this->student->id,
            'date' => '2026-10-02',
            'time_in' => '12:02:55',
        ], $attributes));
    }

    private function supervisorNotifications()
    {
        return Notification::where('user_id', $this->supervisor->id)->where('type', 'attendance_pending');
    }

    public function test_notifies_the_supervisor_about_pending_attendance_they_missed(): void
    {
        $attendance = $this->pendingAttendance();

        $this->artisan('notifications:backfill-supervisor-attendance')->assertSuccessful();

        $notification = $this->supervisorNotifications()->sole();

        $this->assertSame(['attendance_id' => $attendance->id], $notification->data);
        $this->assertNull($notification->read_at);
        $this->assertSame('New attendance approval request', $notification->title);
        $this->assertSame('Juan Dela Cruz submitted a time-in for October 2, 2026 awaiting your approval.', $notification->body);
        $this->assertSame(
            route('attendance-approvals.index', ['highlight' => $attendance->id]),
            app(NotificationService::class)->urlFor($notification, $this->supervisor),
        );

        // Admins and coordinators are never part of the backfill.
        $this->assertSame(0, Notification::whereIn('user_id', [$this->admin->id, $this->coordinator->id])->count());
    }

    public function test_uses_the_pending_time_out_leg_when_there_is_one(): void
    {
        $this->pendingAttendance(['time_out' => '17:00:00', 'time_out_status' => 'pending', 'time_in_status' => 'approved']);

        $this->artisan('notifications:backfill-supervisor-attendance')->assertSuccessful();

        $this->assertStringContainsString('submitted a time-out', $this->supervisorNotifications()->sole()->body);
    }

    public function test_is_idempotent_and_skips_already_notified_or_non_pending_attendance(): void
    {
        $missed = $this->pendingAttendance();

        $alreadyNotified = $this->pendingAttendance(['date' => '2026-10-03']);
        app(NotificationService::class)->notifyAttendanceSubmitted($this->supervisor, $alreadyNotified, 'time_in');

        Attendance::factory()->create(['student_id' => $this->student->id, 'date' => '2026-10-01']); // fully approved

        $this->artisan('notifications:backfill-supervisor-attendance')->assertSuccessful();
        $this->artisan('notifications:backfill-supervisor-attendance')->assertSuccessful();

        $this->assertSame(2, $this->supervisorNotifications()->count());
        $this->assertSame(1, $this->supervisorNotifications()->where('data->attendance_id', $missed->id)->count());
        $this->assertSame(1, $this->supervisorNotifications()->where('data->attendance_id', $alreadyNotified->id)->count());
    }

    public function test_skips_students_whose_supervisor_no_longer_has_the_supervisor_role(): void
    {
        $this->supervisor->update(['role_id' => 2]);
        $this->pendingAttendance();

        $this->artisan('notifications:backfill-supervisor-attendance')->assertSuccessful();

        $this->assertSame(0, Notification::count());
    }

    public function test_dry_run_writes_nothing(): void
    {
        $attendance = $this->pendingAttendance();

        $this->artisan('notifications:backfill-supervisor-attendance', ['--dry-run' => true])
            ->expectsOutputToContain('Notifications to create: 1')
            ->assertSuccessful();

        $this->assertSame(0, Notification::count());
        $this->assertNotNull($attendance->fresh());
    }

    public function test_live_submission_still_notifies_admins_and_the_supervisor_once_each(): void
    {
        $attendance = $this->pendingAttendance();

        app(NotificationService::class)->attendanceSubmitted($attendance, 'time_in');

        $this->assertSame(1, $this->supervisorNotifications()->count());
        $this->assertSame(1, Notification::where('user_id', $this->admin->id)->count());
        $this->assertSame(0, Notification::where('user_id', $this->coordinator->id)->count());
    }
}
