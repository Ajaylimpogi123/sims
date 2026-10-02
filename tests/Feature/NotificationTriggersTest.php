<?php

namespace Tests\Feature;

use App\Models\Attendance;
use App\Models\Company;
use App\Models\InternshipReport;
use App\Models\Notification;
use App\Models\Student;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class NotificationTriggersTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);
    }

    private function studentWithUser(array $overrides = []): Student
    {
        $user = User::factory()->create(['role_id' => 1]);

        return Student::factory()->create(array_merge(['user_id' => $user->id], $overrides));
    }

    public function test_submitting_a_time_in_notifies_admins_and_the_students_supervisor_only(): void
    {
        // Approvals are role:3,4 — Coordinators can't act on a pending
        // attendance, so they must not be notified; the student's own
        // supervisor (who approves) must be.
        $coordinator = User::factory()->create(['role_id' => 2]);
        $admin = User::factory()->create(['role_id' => 4]);
        $otherAdmin = User::factory()->create(['role_id' => 4]);
        $supervisor = User::factory()->create(['role_id' => 3]);
        $otherSupervisor = User::factory()->create(['role_id' => 3]);
        $student = $this->studentWithUser(['supervisor_id' => $supervisor->id]);
        Storage::fake('local');

        $this->actingAs($student->user)->post('/my-attendance/time-in', [
            'photo' => UploadedFile::fake()->image('capture.jpg'),
            'latitude' => '10.6765432',
            'longitude' => '122.9509876',
        ])->assertSessionHas('success');

        foreach ([$admin, $otherAdmin, $supervisor] as $recipient) {
            $this->assertSame(
                1,
                Notification::where('user_id', $recipient->id)->where('type', 'attendance_pending')->count(),
            );
        }

        $this->assertDatabaseMissing('notifications', [
            'user_id' => $coordinator->id,
            'type' => 'attendance_pending',
        ]);
        $this->assertDatabaseMissing('notifications', [
            'user_id' => $otherSupervisor->id,
        ]);
        $this->assertDatabaseMissing('notifications', [
            'user_id' => $student->user_id,
        ]);
    }

    public function test_submitting_a_time_in_without_a_supervisor_notifies_only_admins(): void
    {
        $coordinator = User::factory()->create(['role_id' => 2]);
        $admin = User::factory()->create(['role_id' => 4]);
        $supervisor = User::factory()->create(['role_id' => 3]);
        $student = $this->studentWithUser();
        Storage::fake('local');

        $this->actingAs($student->user)->post('/my-attendance/time-in', [
            'photo' => UploadedFile::fake()->image('capture.jpg'),
            'latitude' => '10.6765432',
            'longitude' => '122.9509876',
        ])->assertSessionHas('success');

        $this->assertDatabaseHas('notifications', [
            'user_id' => $admin->id,
            'type' => 'attendance_pending',
        ]);
        $this->assertSame(
            0,
            Notification::whereIn('user_id', [$coordinator->id, $supervisor->id])->count(),
        );
    }

    public function test_approving_a_time_in_notifies_the_student(): void
    {
        // /attendance-approvals is role:3,4 (Supervisor + Admin) —
        // Coordinator no longer has access here.
        $admin = User::factory()->create(['role_id' => 4]);
        $student = $this->studentWithUser();

        $attendance = Attendance::factory()->create([
            'student_id' => $student->id,
            'date' => today()->toDateString(),
            'time_in' => '08:00:00',
            'time_in_status' => 'pending',
            'time_out' => null,
            'time_out_status' => null,
        ]);

        $this->actingAs($admin)->patch("/attendance-approvals/{$attendance->id}/approve-time-in");

        $this->assertDatabaseHas('notifications', [
            'user_id' => $student->user_id,
            'type' => 'attendance_reviewed',
        ]);
    }

    public function test_submitting_a_report_notifies_staff_and_assigned_supervisor(): void
    {
        $coordinator = User::factory()->create(['role_id' => 2]);
        $supervisor = User::factory()->create(['role_id' => 3]);
        $student = $this->studentWithUser(['supervisor_id' => $supervisor->id]);

        $this->actingAs($student->user)->post('/my-reports', [
            'type' => 'daily',
            'period_start' => '2026-01-05',
            'period_end' => '2026-01-05',
            'content' => 'Worked on notifications today.',
        ]);

        $this->assertDatabaseHas('notifications', [
            'user_id' => $coordinator->id,
            'type' => 'report_submitted',
        ]);
        $this->assertDatabaseHas('notifications', [
            'user_id' => $supervisor->id,
            'type' => 'report_submitted',
        ]);
    }

    public function test_reviewing_a_report_notifies_the_student(): void
    {
        $coordinator = User::factory()->create(['role_id' => 2]);
        $student = $this->studentWithUser();

        $report = InternshipReport::factory()->create([
            'student_id' => $student->id,
            'status' => 'pending',
        ]);

        $this->actingAs($coordinator)->patch("/report-reviews/{$report->id}", [
            'comment' => 'Good work.',
        ]);

        $this->assertDatabaseHas('notifications', [
            'user_id' => $student->user_id,
            'type' => 'report_reviewed',
        ]);
    }

    public function test_changing_an_assignment_notifies_student_and_supervisor(): void
    {
        $coordinator = User::factory()->create(['role_id' => 2]);
        $supervisor = User::factory()->create(['role_id' => 3]);
        $company = Company::factory()->create(['slots' => 5]);
        $company->supervisors()->attach($supervisor->id);
        $student = $this->studentWithUser();

        $this->actingAs($coordinator)->patch("/internship-assignment/{$student->id}", [
            'name' => $student->user->name,
            'email' => $student->user->email,
            'student_number' => $student->student_number,
            'course' => $student->course,
            'section' => $student->section,
            'company_id' => $company->id,
            'supervisor_id' => $supervisor->id,
            'internship_status' => 'ongoing',
        ]);

        $this->assertDatabaseHas('notifications', [
            'user_id' => $student->user_id,
            'type' => 'assignment_updated',
        ]);
        $this->assertDatabaseHas('notifications', [
            'user_id' => $supervisor->id,
            'type' => 'assignment_updated',
        ]);
    }

    public function test_resaving_an_unchanged_assignment_does_not_notify_again(): void
    {
        $coordinator = User::factory()->create(['role_id' => 2]);
        $student = $this->studentWithUser(['internship_status' => 'ongoing']);

        $this->actingAs($coordinator)->patch("/internship-assignment/{$student->id}", [
            'name' => $student->user->name,
            'email' => $student->user->email,
            'student_number' => $student->student_number,
            'course' => $student->course,
            'section' => $student->section,
            'company_id' => '',
            'supervisor_id' => '',
            'internship_status' => 'ongoing',
        ]);

        $this->assertSame(
            0,
            Notification::where('user_id', $student->user_id)->where('type', 'assignment_updated')->count(),
        );
    }

    public function test_student_self_registration_notifies_admins(): void
    {
        $admin = User::factory()->create(['role_id' => 4]);

        $this->post('/register', [
            'name' => 'New Student',
            'email' => 'notif.student@example.com',
            'password' => 'password',
            'password_confirmation' => 'password',
            'student_number' => '2026-00042',
            'course' => 'BSIT',
            'section' => 'A',
        ]);

        $this->assertDatabaseHas('notifications', [
            'user_id' => $admin->id,
            'type' => 'student_registered',
        ]);
    }
}
