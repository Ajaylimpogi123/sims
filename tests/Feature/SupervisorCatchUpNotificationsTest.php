<?php

namespace Tests\Feature;

use App\Models\Attendance;
use App\Models\Company;
use App\Models\InternshipReport;
use App\Models\Notification;
use App\Models\Student;
use App\Models\User;
use App\Services\NotificationService;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * When a student gets a (new) supervisor — Company Management sets their
 * company's Company Supervisor, or Internship Assignment moves them to a
 * company with one — that supervisor is told about the student's
 * attendance and reports still awaiting review: submissions made while
 * nobody (or someone else) supervised the student only reached whoever was
 * responsible then.
 */
class SupervisorCatchUpNotificationsTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);

        $this->company = Company::factory()->create(['slots' => 10, 'status' => 'active']);
    }

    private function user(int $roleId, array $attributes = []): User
    {
        return User::factory()->create($attributes + ['role_id' => $roleId, 'status' => 'active']);
    }

    private function supervisor(array $attributes = []): User
    {
        return $this->user(User::ROLE_SUPERVISOR, $attributes);
    }

    /**
     * A student at the company; a given supervisor_id becomes the
     * company's supervisor too.
     */
    private function student(array $attributes = []): Student
    {
        $user = $this->user(User::ROLE_STUDENT, ['name' => 'Juan Dela Cruz']);

        if (isset($attributes['supervisor_id'])) {
            $this->company->update(['supervisor_id' => $attributes['supervisor_id']]);
        }

        return Student::factory()->create($attributes + [
            'user_id' => $user->id,
            'company_id' => $this->company->id,
            'internship_status' => 'ongoing',
        ]);
    }

    private function payload(Student $student, array $overrides = []): array
    {
        $student->refresh()->load('user');

        return array_merge([
            'name' => $student->user->name,
            'email' => $student->user->email,
            'student_number' => $student->student_number,
            'course' => $student->course,
            'section' => $student->section,
            'company_id' => $student->company_id,
            'internship_status' => $student->internship_status,
            'internship_schedule' => $student->internship_schedule,
        ], $overrides);
    }

    private function companyPayload(?int $supervisorId): array
    {
        $this->company->refresh();

        return [
            'company_name' => $this->company->company_name,
            'slots' => $this->company->slots,
            'supervisor_id' => $supervisorId,
        ];
    }

    /** Company Management sets the company's supervisor (website). */
    private function webAssign(?int $supervisorId): TestResponse
    {
        return $this->actingAs($this->user(User::ROLE_COORDINATOR))
            ->patch("/company-management/{$this->company->id}", $this->companyPayload($supervisorId));
    }

    /** Internship Assignment saves the student (website). */
    private function webSave(Student $student, array $overrides = []): TestResponse
    {
        return $this->actingAs($this->user(User::ROLE_COORDINATOR))
            ->patch("/internship-assignment/{$student->id}", $this->payload($student, $overrides));
    }

    /**
     * Pending time-in, pending time-out, a fully reviewed day, a pending
     * and a reviewed report.
     *
     * @return array{time_in: Attendance, time_out: Attendance, approved: Attendance, report: InternshipReport, reviewed_report: InternshipReport}
     */
    private function backlog(Student $student): array
    {
        return [
            'time_in' => Attendance::factory()->pendingTimeIn()->create([
                'student_id' => $student->id,
                'date' => '2026-10-14',
            ]),
            'time_out' => Attendance::factory()->create([
                'student_id' => $student->id,
                'date' => '2026-10-13',
                'time_in_status' => 'approved',
                'time_out_status' => 'pending',
                'rendered_hours' => null,
            ]),
            'approved' => Attendance::factory()->create([
                'student_id' => $student->id,
                'date' => '2026-10-12',
            ]),
            'report' => InternshipReport::factory()->create([
                'student_id' => $student->id,
                'type' => 'weekly',
            ]),
            'reviewed_report' => InternshipReport::factory()->reviewed()->create([
                'student_id' => $student->id,
                'type' => 'daily',
            ]),
        ];
    }

    private function notificationsOf(User $user, string $type)
    {
        return Notification::query()->where('user_id', $user->id)->where('type', $type)->orderBy('id')->get();
    }

    public function test_a_newly_assigned_supervisor_is_told_about_every_pending_item(): void
    {
        $student = $this->student();
        $items = $this->backlog($student);
        $supervisor = $this->supervisor();

        $this->webAssign($supervisor->id)->assertSessionHasNoErrors();

        $attendance = $this->notificationsOf($supervisor, 'attendance_pending');
        $this->assertSame(
            [$items['time_out']->id, $items['time_in']->id],
            $attendance->pluck('data.attendance_id')->all(),
            'One per attendance with a pending leg, oldest first; the reviewed day is skipped.',
        );
        $this->assertSame('New attendance approval request', $attendance[0]->title);
        $this->assertSame('Juan Dela Cruz submitted a time-out for October 13, 2026 awaiting your approval.', $attendance[0]->body);
        $this->assertSame('Juan Dela Cruz submitted a time-in for October 14, 2026 awaiting your approval.', $attendance[1]->body);

        $reports = $this->notificationsOf($supervisor, 'report_submitted');
        $this->assertCount(1, $reports);
        $this->assertSame(['report_id' => $items['report']->id], $reports[0]->data);
        $this->assertSame('New weekly report submitted', $reports[0]->title);
        $this->assertSame('Juan Dela Cruz submitted a weekly report for review.', $reports[0]->body);

        // The usual assignment notification still goes out, first.
        $assignment = $this->notificationsOf($supervisor, 'assignment_updated');
        $this->assertCount(1, $assignment);
        $this->assertLessThan($attendance[0]->id, $assignment[0]->id);
    }

    public function test_catch_up_notifications_open_the_same_screens_as_live_ones(): void
    {
        $student = $this->student();
        $items = $this->backlog($student);
        $supervisor = $this->supervisor();

        $this->webAssign($supervisor->id);

        $service = app(NotificationService::class);

        $attendance = $this->notificationsOf($supervisor, 'attendance_pending')->first();
        $this->assertSame(['screen' => 'approvals', 'params' => ['attendance_id' => $items['time_out']->id]], $service->targetFor($attendance, $supervisor));
        $this->assertSame(route('attendance-approvals.index', ['highlight' => $items['time_out']->id]), $service->urlFor($attendance, $supervisor));

        $report = $this->notificationsOf($supervisor, 'report_submitted')->first();
        $this->assertSame(['screen' => 'report-reviews', 'params' => ['report_id' => $items['report']->id]], $service->targetFor($report, $supervisor));
        $this->assertSame(route('report-reviews.index', ['highlight' => $items['report']->id]), $service->urlFor($report, $supervisor));

        // Both pages are within the supervisor's scope now.
        $this->actingAs($supervisor)->get($service->urlFor($attendance, $supervisor))->assertOk();
        $this->actingAs($supervisor)->get($service->urlFor($report, $supervisor))->assertOk();
    }

    public function test_the_unsupervised_time_in_case_reaches_the_supervisor_once_assigned(): void
    {
        $admin = $this->user(User::ROLE_ADMIN);
        $student = $this->student();
        $attendance = Attendance::factory()->pendingTimeIn()->create(['student_id' => $student->id, 'date' => '2026-10-15']);

        // Submitted with no supervisor: only the admin hears about it.
        app(NotificationService::class)->attendanceSubmitted($attendance->load('student.user', 'student.supervisor'), 'time_in');
        $this->assertSame([$admin->id], Notification::where('type', 'attendance_pending')->pluck('user_id')->all());

        $supervisor = $this->supervisor();
        $this->webAssign($supervisor->id)->assertSessionHasNoErrors();

        $this->assertSame([$attendance->id], $this->notificationsOf($supervisor, 'attendance_pending')->pluck('data.attendance_id')->all());
        $this->assertCount(1, $this->notificationsOf($admin, 'attendance_pending'), 'The admin is not notified again.');
    }

    public function test_an_item_with_an_unread_notification_is_not_duplicated_but_a_read_one_is_resent(): void
    {
        $student = $this->student();
        $items = $this->backlog($student);
        $supervisor = $this->supervisor();
        $service = app(NotificationService::class);

        // Unread for the time-in and the report; read for the time-out.
        $service->notifyAttendanceSubmitted($supervisor, $items['time_in']->load('student.user'), 'time_in');
        $service->notifyReportSubmitted($supervisor, $items['report']);
        $service->notifyAttendanceSubmitted($supervisor, $items['time_out']->load('student.user'), 'time_out')
            ->update(['read_at' => now()]);

        $this->webAssign($supervisor->id)->assertSessionHasNoErrors();

        $this->assertSame(
            [$items['time_in']->id, $items['time_out']->id, $items['time_out']->id],
            $this->notificationsOf($supervisor, 'attendance_pending')->pluck('data.attendance_id')->all(),
        );
        $this->assertCount(1, $this->notificationsOf($supervisor, 'report_submitted'));
    }

    public function test_switching_supervisors_catches_up_only_the_new_one(): void
    {
        $old = $this->supervisor();
        $student = $this->student(['supervisor_id' => $old->id]);
        $this->backlog($student);
        $new = $this->supervisor();

        $this->webAssign($new->id)->assertSessionHasNoErrors();

        $this->assertCount(2, $this->notificationsOf($new, 'attendance_pending'));
        $this->assertCount(1, $this->notificationsOf($new, 'report_submitted'));
        $this->assertCount(0, $this->notificationsOf($old, 'attendance_pending'));
        $this->assertCount(0, $this->notificationsOf($old, 'report_submitted'));
    }

    public function test_keeping_the_same_supervisor_sends_no_catch_up(): void
    {
        $supervisor = $this->supervisor();
        $student = $this->student(['supervisor_id' => $supervisor->id]);
        $this->backlog($student);

        // An assignment change (status) that keeps the supervisor.
        $this->webSave($student, ['internship_status' => 'completed'])->assertSessionHasNoErrors();
        // A profile-only edit.
        $this->webSave($student, ['course' => 'BSCS'])->assertSessionHasNoErrors();

        $this->assertCount(1, $this->notificationsOf($supervisor, 'assignment_updated'));
        $this->assertCount(0, $this->notificationsOf($supervisor, 'attendance_pending'));
        $this->assertCount(0, $this->notificationsOf($supervisor, 'report_submitted'));
    }

    public function test_clearing_the_supervisor_sends_no_catch_up(): void
    {
        $supervisor = $this->supervisor();
        $student = $this->student(['supervisor_id' => $supervisor->id]);
        $this->backlog($student);

        $this->webAssign(null)->assertSessionHasNoErrors();

        $this->assertSame(0, Notification::whereIn('type', ['attendance_pending', 'report_submitted'])->count());
    }

    public function test_moving_a_student_to_a_supervised_company_catches_up_only_their_items(): void
    {
        $student = $this->student();
        $items = $this->backlog($student);
        // Left behind at the unsupervised company.
        $other = $this->student();
        $this->backlog($other);

        $supervisor = $this->supervisor();
        $target = Company::factory()->create(['slots' => 10, 'status' => 'active', 'supervisor_id' => $supervisor->id]);

        $this->webSave($student, ['company_id' => $target->id])->assertSessionHasNoErrors();

        $this->assertSame($supervisor->id, $student->fresh()->supervisor_id);
        $this->assertSame(
            [$items['time_out']->id, $items['time_in']->id],
            $this->notificationsOf($supervisor, 'attendance_pending')->pluck('data.attendance_id')->all(),
        );
        $this->assertSame([$items['report']->id], $this->notificationsOf($supervisor, 'report_submitted')->pluck('data.report_id')->all());
    }

    public function test_an_inactive_or_non_supervisor_account_gets_no_catch_up(): void
    {
        $service = app(NotificationService::class);

        $inactive = $this->supervisor(['status' => 'inactive']);
        $student = $this->student(['supervisor_id' => $inactive->id]);
        $this->backlog($student);

        $this->assertSame(0, $service->supervisorCatchUp($student->fresh()));

        // Supervisor role removed after the assignment.
        $demoted = $this->supervisor();
        $demoted->update(['role_id' => User::ROLE_COORDINATOR]);
        $student->update(['supervisor_id' => $demoted->id]);

        $this->assertSame(0, $service->supervisorCatchUp($student->fresh()));
        $this->assertSame(0, Notification::whereIn('type', ['attendance_pending', 'report_submitted'])->count());
    }

    public function test_assigning_through_the_api_sends_the_same_catch_up(): void
    {
        $student = $this->student();
        $items = $this->backlog($student);
        $supervisor = $this->supervisor();
        $coordinator = $this->user(User::ROLE_COORDINATOR);

        $this->withHeaders([
            'Accept' => 'application/json',
            'Authorization' => 'Bearer '.$coordinator->createToken('test')->plainTextToken,
        ])->patchJson("/api/v1/companies/{$this->company->id}", $this->companyPayload($supervisor->id))
            ->assertOk()
            ->assertJsonPath('company.supervisor.id', $supervisor->id)
            ->assertJsonPath('students_reassigned', 1);

        $this->assertSame($supervisor->id, $student->fresh()->supervisor_id);
        $this->assertSame(
            [$items['time_out']->id, $items['time_in']->id],
            $this->notificationsOf($supervisor, 'attendance_pending')->pluck('data.attendance_id')->all(),
        );
        $this->assertSame([$items['report']->id], $this->notificationsOf($supervisor, 'report_submitted')->pluck('data.report_id')->all());
    }

    public function test_a_refused_assignment_sends_nothing(): void
    {
        $student = $this->student();
        $this->backlog($student);
        $inactive = $this->supervisor(['status' => 'inactive']);

        $this->webAssign($inactive->id)->assertSessionHasErrors('supervisor_id');

        $this->assertNull($student->fresh()->supervisor_id);
        $this->assertSame(0, Notification::where('user_id', $inactive->id)->count());
    }
}
