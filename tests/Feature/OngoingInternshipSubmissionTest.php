<?php

namespace Tests\Feature;

use App\Exceptions\AttendanceRuleException;
use App\Exceptions\ReportRuleException;
use App\Models\Attendance;
use App\Models\InternshipReport;
use App\Models\Notification;
use App\Models\Student;
use App\Models\User;
use App\Services\AttendanceService;
use App\Services\InternshipReportService;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Students submit attendance and reports only while their internship is
 * ongoing (website + the shared services). History, downloads and deleting
 * a still-pending report stay open; staff actions don't depend on it.
 */
class OngoingInternshipSubmissionTest extends TestCase
{
    use RefreshDatabase;

    private const ATTENDANCE_MESSAGE = 'You can only submit attendance while your internship is Ongoing.';

    private const REPORT_MESSAGE = 'You can only submit reports while your internship is Ongoing.';

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);
        Storage::fake('local');
    }

    public static function closedStatuses(): array
    {
        return [
            'not started' => ['not_started'],
            'completed' => ['completed'],
        ];
    }

    public static function closedStatusesAndLegs(): array
    {
        $cases = [];

        foreach (['not_started', 'completed'] as $status) {
            foreach (['time_in', 'time_out', 'emergency'] as $leg) {
                $cases["{$status} {$leg}"] = [$status, $leg];
            }
        }

        return $cases;
    }

    private function student(string $status, ?User $supervisor = null): Student
    {
        return Student::factory()->create([
            'internship_status' => $status,
            'supervisor_id' => $supervisor?->id,
        ]);
    }

    private function capture(array $overrides = []): array
    {
        return array_merge([
            'photo' => UploadedFile::fake()->image('capture.jpg', 640, 480),
            'latitude' => '10.6765432',
            'longitude' => '122.9509876',
            'accuracy' => '12.5',
        ], $overrides);
    }

    /**
     * Put today's row in the state the leg needs (so only the internship
     * status can refuse it) and return its URL + extra fields.
     *
     * @return array{0: string, 1: array}
     */
    private function prepareLeg(string $leg, Student $student): array
    {
        $today = fn (array $attributes) => Attendance::factory()->create(array_merge([
            'student_id' => $student->id,
            'date' => today()->toDateString(),
            'time_in' => '08:00:00',
            'time_out' => null,
            'time_out_status' => null,
            'rendered_hours' => null,
        ], $attributes));

        return match ($leg) {
            'time_in' => ['/my-attendance/time-in', []],
            'time_out' => tap(['/my-attendance/time-out', []], fn () => $today(['time_in_status' => 'approved'])),
            'emergency' => tap(['/my-attendance/emergency-time-out', ['note' => 'Family emergency.']], fn () => $today(['time_in_status' => 'pending'])),
        };
    }

    private function reportPayload(array $overrides = []): array
    {
        return array_merge([
            'type' => 'daily',
            'period_start' => '2026-10-02',
            'period_end' => '2026-10-02',
            'content' => 'Set up the staging server.',
        ], $overrides);
    }

    private function attendanceSnapshot(Student $student): array
    {
        return Attendance::where('student_id', $student->id)->get()->map->getAttributes()->all();
    }

    // ------------------------------------------------------- attendance

    #[DataProvider('closedStatusesAndLegs')]
    public function test_attendance_legs_are_refused_unless_ongoing(string $status, string $leg): void
    {
        $supervisor = User::factory()->create(['role_id' => 3]);
        $student = $this->student($status, $supervisor);
        [$url, $extra] = $this->prepareLeg($leg, $student);
        $before = $this->attendanceSnapshot($student);

        $this->actingAs($student->user)
            ->post($url, $this->capture($extra))
            ->assertRedirect(route('attendance.index'))
            ->assertSessionHas('error', self::ATTENDANCE_MESSAGE)
            ->assertSessionHasNoErrors();

        $this->assertSame($before, $this->attendanceSnapshot($student));
        $this->assertSame([], Storage::disk('local')->allFiles());
        $this->assertSame(0, Notification::count());
    }

    public static function legs(): array
    {
        return ['time_in' => ['time_in'], 'time_out' => ['time_out'], 'emergency' => ['emergency']];
    }

    #[DataProvider('legs')]
    public function test_attendance_legs_are_accepted_while_ongoing(string $leg): void
    {
        $student = $this->student('ongoing');
        [$url, $extra] = $this->prepareLeg($leg, $student);

        $this->actingAs($student->user)
            ->post($url, $this->capture($extra))
            ->assertRedirect(route('attendance.index'))
            ->assertSessionHas('success')
            ->assertSessionMissing('error');

        $this->assertCount(1, Storage::disk('local')->allFiles());
    }

    public function test_field_validation_still_comes_first(): void
    {
        $student = $this->student('completed');

        $this->actingAs($student->user)
            ->post('/my-attendance/time-in', [])
            ->assertSessionHasErrors(['photo', 'latitude', 'longitude']);
    }

    #[DataProvider('legs')]
    public function test_a_status_change_after_the_student_was_loaded_is_caught_under_the_lock(string $leg): void
    {
        $student = $this->student('ongoing');
        $this->prepareLeg($leg, $student);
        // Staff mark the internship completed after this request loaded it.
        Student::whereKey($student->id)->update(['internship_status' => 'completed']);
        $before = $this->attendanceSnapshot($student);

        $service = app(AttendanceService::class);
        $capture = ['latitude' => 10.5, 'longitude' => 122.9, 'accuracy' => 5, 'note' => 'Emergency.'];
        $photo = UploadedFile::fake()->image('capture.jpg', 640, 480);

        try {
            match ($leg) {
                'time_in' => $service->timeIn($student, $student->user, $photo, $capture),
                'time_out' => $service->timeOut($student, $student->user, $photo, $capture),
                'emergency' => $service->emergencyTimeOut($student, $student->user, $photo, $capture),
            };
            $this->fail('The leg was accepted.');
        } catch (AttendanceRuleException $e) {
            $this->assertSame(self::ATTENDANCE_MESSAGE, $e->getMessage());
        }

        $this->assertSame($before, $this->attendanceSnapshot($student));
        // The photo written before the locked re-check is cleaned up.
        $this->assertSame([], Storage::disk('local')->allFiles());
        $this->assertSame(0, Notification::count());
    }

    public function test_refusals_put_the_internship_status_first(): void
    {
        $closed = Student::factory()->make(['internship_status' => 'not_started']);
        $open = Student::factory()->make(['internship_status' => 'ongoing']);

        $this->assertSame([
            'time_in' => self::ATTENDANCE_MESSAGE,
            'time_out' => self::ATTENDANCE_MESSAGE,
            'emergency_time_out' => self::ATTENDANCE_MESSAGE,
        ], AttendanceService::refusals($closed, null));

        $this->assertSame([
            'time_in' => null,
            'time_out' => 'Your time-in must be approved before you can time out.',
            'emergency_time_out' => 'You must time in before using emergency time-out.',
        ], AttendanceService::refusals($open, null));
    }

    public static function allStatuses(): array
    {
        return [
            'not started' => ['not_started', false],
            'ongoing' => ['ongoing', true],
            'completed' => ['completed', false],
        ];
    }

    #[DataProvider('allStatuses')]
    public function test_attendance_page_exposes_the_submission_state(string $status, bool $canSubmit): void
    {
        $student = $this->student($status);
        Attendance::factory()->create(['student_id' => $student->id, 'date' => '2026-09-01']);

        $this->actingAs($student->user)
            ->get('/my-attendance')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Attendance/Index')
                ->has('attendances', 1)
                ->where('submission', [
                    'can_submit' => $canSubmit,
                    'internship_status' => $status,
                    'reason' => $canSubmit ? null : self::ATTENDANCE_MESSAGE,
                ]));
    }

    // ---------------------------------------------------------- reports

    #[DataProvider('closedStatuses')]
    public function test_creating_a_report_is_refused_unless_ongoing(string $status): void
    {
        $supervisor = User::factory()->create(['role_id' => 3]);
        $student = $this->student($status, $supervisor);

        $this->actingAs($student->user)
            ->post('/my-reports', $this->reportPayload([
                'attachment' => UploadedFile::fake()->create('proof.pdf', 10, 'application/pdf'),
            ]))
            ->assertRedirect(route('reports.index'))
            ->assertSessionHas('error', self::REPORT_MESSAGE)
            ->assertSessionHasNoErrors();

        $this->assertSame(0, InternshipReport::count());
        $this->assertSame([], Storage::disk('local')->allFiles());
        $this->assertSame(0, Notification::count());
    }

    public function test_creating_a_report_is_accepted_while_ongoing(): void
    {
        $student = $this->student('ongoing');

        $this->actingAs($student->user)
            ->post('/my-reports', $this->reportPayload())
            ->assertRedirect(route('reports.index'))
            ->assertSessionHas('success', 'Report submitted for review.');

        $this->assertSame(1, InternshipReport::count());
    }

    #[DataProvider('closedStatuses')]
    public function test_updating_a_report_is_refused_unless_ongoing(string $status): void
    {
        $student = $this->student($status);
        $report = InternshipReport::factory()->create(['student_id' => $student->id, 'status' => 'pending', 'content' => 'Original.']);
        $before = $report->fresh()->getAttributes();

        $this->actingAs($student->user)
            ->patch("/my-reports/{$report->id}", $this->reportPayload([
                'type' => $report->type,
                'period_start' => $report->period_start->format('Y-m-d'),
                'period_end' => $report->period_end->format('Y-m-d'),
                'content' => 'Changed.',
                'attachment' => UploadedFile::fake()->create('proof.pdf', 10, 'application/pdf'),
            ]))
            ->assertRedirect(route('reports.index'))
            ->assertSessionHas('error', self::REPORT_MESSAGE);

        $this->assertSame($before, $report->fresh()->getAttributes());
        $this->assertSame([], Storage::disk('local')->allFiles());
    }

    #[DataProvider('closedStatuses')]
    public function test_deleting_a_pending_report_and_downloading_stay_allowed(string $status): void
    {
        $student = $this->student($status);
        Storage::disk('local')->put('report-attachments/proof.pdf', '%PDF-1.4 test');
        $kept = InternshipReport::factory()->create([
            'student_id' => $student->id,
            'status' => 'reviewed',
            'attachment_path' => 'report-attachments/proof.pdf',
            'attachment_original_name' => 'proof.pdf',
        ]);
        $pending = InternshipReport::factory()->create(['student_id' => $student->id, 'status' => 'pending']);

        $this->actingAs($student->user)
            ->get("/my-reports/{$kept->id}/attachment")
            ->assertOk();

        $this->actingAs($student->user)
            ->delete("/my-reports/{$pending->id}")
            ->assertRedirect(route('reports.index'))
            ->assertSessionHas('success', 'Report deleted.');

        $this->assertModelMissing($pending);
        $this->assertModelExists($kept);
    }

    #[DataProvider('allStatuses')]
    public function test_reports_page_exposes_the_submission_state(string $status, bool $canSubmit): void
    {
        $student = $this->student($status);
        InternshipReport::factory()->create(['student_id' => $student->id]);

        $this->actingAs($student->user)
            ->get('/my-reports')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('InternshipReports/Index')
                ->has('reports', 1)
                ->where('submission', [
                    'can_submit' => $canSubmit,
                    'internship_status' => $status,
                    'reason' => $canSubmit ? null : self::REPORT_MESSAGE,
                ]));
    }

    #[DataProvider('allStatuses')]
    public function test_student_dashboard_exposes_can_submit(string $status, bool $canSubmit): void
    {
        $student = $this->student($status);

        $this->actingAs($student->user)
            ->get('/dashboard')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('student.internship_status', $status)
                ->where('student.can_submit', $canSubmit));
    }

    public function test_report_status_change_after_the_student_was_loaded_is_caught_under_the_lock(): void
    {
        $student = $this->student('ongoing');
        $report = InternshipReport::factory()->create(['student_id' => $student->id, 'status' => 'pending']);
        $student->load('internshipReports');
        $report->setRelation('student', $student);
        Student::whereKey($student->id)->update(['internship_status' => 'completed']);

        $service = app(InternshipReportService::class);

        foreach ([
            fn () => $service->create($student, $this->reportPayload(['period_start' => '2026-09-01', 'period_end' => '2026-09-01']), UploadedFile::fake()->create('a.pdf', 5, 'application/pdf')),
            fn () => $service->update($report, $this->reportPayload([
                'type' => $report->type,
                'period_start' => $report->period_start->format('Y-m-d'),
                'period_end' => $report->period_end->format('Y-m-d'),
                'content' => 'Changed.',
            ]), UploadedFile::fake()->create('b.pdf', 5, 'application/pdf')),
        ] as $action) {
            try {
                $action();
                $this->fail('The report change was accepted.');
            } catch (ReportRuleException $e) {
                $this->assertTrue($e->isNotOngoing());
                $this->assertSame(self::REPORT_MESSAGE, $e->getMessage());
            }
        }

        $this->assertSame(1, InternshipReport::count());
        $this->assertNotSame('Changed.', $report->fresh()->content);
        $this->assertSame([], Storage::disk('local')->allFiles());
        $this->assertSame(0, Notification::count());
    }

    // ------------------------------------------------- staff unaffected

    #[DataProvider('closedStatuses')]
    public function test_staff_actions_are_unaffected(string $status): void
    {
        $supervisor = User::factory()->create(['role_id' => 3]);
        $coordinator = User::factory()->create(['role_id' => 2]);
        $student = $this->student($status, $supervisor);

        $pending = Attendance::factory()->create([
            'student_id' => $student->id,
            'date' => '2026-09-10',
            'time_in' => '08:00:00',
            'time_in_status' => 'pending',
            'time_out' => '17:00:00',
            'time_out_status' => 'pending',
            'rendered_hours' => null,
        ]);

        $this->actingAs($supervisor)
            ->patch("/attendance-approvals/{$pending->id}/approve-time-in")
            ->assertSessionMissing('error');
        $this->actingAs($supervisor)
            ->patch("/attendance-approvals/{$pending->id}/reject-time-out", ['reason' => 'Wrong photo.'])
            ->assertSessionMissing('error');

        $this->assertSame('approved', $pending->fresh()->time_in_status);
        $this->assertSame('rejected', $pending->fresh()->time_out_status);

        $this->actingAs($supervisor)
            ->post("/attendance-monitoring/{$student->id}/attendances", [
                'date' => '2026-09-11',
                'time_in' => '08:00',
                'time_out' => '12:00',
            ])
            ->assertSessionHasNoErrors()
            ->assertSessionMissing('error');

        $this->assertEquals(4.0, $student->attendances()->where('date', '2026-09-11')->value('rendered_hours'));

        $report = InternshipReport::factory()->create(['student_id' => $student->id, 'status' => 'pending']);

        $this->actingAs($coordinator)
            ->patch("/report-reviews/{$report->id}", ['comment' => 'Fine.'])
            ->assertSessionHasNoErrors()
            ->assertSessionMissing('error');

        $this->assertSame('reviewed', $report->fresh()->status);
    }
}
