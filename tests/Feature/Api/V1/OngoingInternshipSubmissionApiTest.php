<?php

namespace Tests\Feature\Api\V1;

use App\Models\Attendance;
use App\Models\InternshipReport;
use App\Models\Notification;
use App\Models\Student;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * The Ongoing-only submission rule through /api/v1: 422 refusals with
 * stable codes, the `submission` / `can_submit` flags the app disables its
 * buttons with, and staff actions unaffected.
 */
class OngoingInternshipSubmissionApiTest extends TestCase
{
    use RefreshDatabase;

    private const ATTENDANCE_MESSAGE = 'You can only submit attendance while your internship is Ongoing.';

    private const REPORT_MESSAGE = 'You can only submit reports while your internship is Ongoing.';

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);
        Storage::fake('local');
        Carbon::setTestNow(Carbon::parse('2026-10-03 08:15:00', 'Asia/Manila'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public static function closedStatuses(): array
    {
        return [
            'not started' => ['not_started'],
            'completed' => ['completed'],
        ];
    }

    public static function allStatuses(): array
    {
        return [
            'not started' => ['not_started', false],
            'ongoing' => ['ongoing', true],
            'completed' => ['completed', false],
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

    private function user(int $roleId): User
    {
        return User::factory()->create(['role_id' => $roleId, 'status' => 'active']);
    }

    private function student(string $status, ?User $supervisor = null): Student
    {
        return Student::factory()->create([
            'user_id' => $this->user(User::ROLE_STUDENT)->id,
            'internship_status' => $status,
            'supervisor_id' => $supervisor?->id,
        ]);
    }

    private function api(string $method, string $uri, User $user, array $data = []): TestResponse
    {
        $this->app['auth']->forgetGuards();
        $this->defaultHeaders = [];

        $request = $this->withHeaders([
            'Accept' => 'application/json',
            'Authorization' => 'Bearer '.$user->createToken('test')->plainTextToken,
        ]);

        return match ($method) {
            'GET' => $request->get($uri),
            'DELETE' => $request->delete($uri, $data),
            default => $request->post($uri, $data),
        };
    }

    private function submission(string $status, bool $canSubmit, string $message): array
    {
        return [
            'can_submit' => $canSubmit,
            'internship_status' => $status,
            'reason' => $canSubmit ? null : $message,
        ];
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
            'time_in' => ['/api/v1/attendance/time-in', []],
            'time_out' => tap(['/api/v1/attendance/time-out', []], fn () => $today(['time_in_status' => 'approved'])),
            'emergency' => tap(['/api/v1/attendance/emergency-time-out', ['note' => 'Family emergency.']], fn () => $today(['time_in_status' => 'pending'])),
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

    private function samePeriod(InternshipReport $report, array $overrides = []): array
    {
        return $this->reportPayload([
            'type' => $report->type,
            'period_start' => $report->period_start->format('Y-m-d'),
            'period_end' => $report->period_end->format('Y-m-d'),
            ...$overrides,
        ]);
    }

    // ------------------------------------------------------- attendance

    #[DataProvider('allStatuses')]
    public function test_today_exposes_the_submission_state_and_actions(string $status, bool $canSubmit): void
    {
        $student = $this->student($status);

        $response = $this->api('GET', '/api/v1/attendance/today', $student->user)
            ->assertOk()
            ->assertJsonPath('submission', $this->submission($status, $canSubmit, self::ATTENDANCE_MESSAGE));

        if ($canSubmit) {
            $response->assertJsonPath('actions.time_in', ['allowed' => true, 'reason' => null]);
        } else {
            $refused = ['allowed' => false, 'reason' => self::ATTENDANCE_MESSAGE];
            $response->assertJsonPath('actions', [
                'time_in' => $refused,
                'time_out' => $refused,
                'emergency_time_out' => $refused,
            ]);
        }
    }

    #[DataProvider('closedStatusesAndLegs')]
    public function test_attendance_legs_are_refused_unless_ongoing(string $status, string $leg): void
    {
        $supervisor = $this->user(User::ROLE_SUPERVISOR);
        $student = $this->student($status, $supervisor);
        [$url, $extra] = $this->prepareLeg($leg, $student);
        $before = Attendance::where('student_id', $student->id)->get()->map->getAttributes()->all();

        $this->api('POST', $url, $student->user, $this->capture($extra))
            ->assertStatus(422)
            ->assertJsonPath('message', self::ATTENDANCE_MESSAGE)
            ->assertJsonPath('code', 'attendance_rule')
            ->assertJsonMissingPath('errors')
            ->assertJsonPath('today.submission', $this->submission($status, false, self::ATTENDANCE_MESSAGE))
            ->assertJsonPath('today.actions.time_in.allowed', false);

        $this->assertSame($before, Attendance::where('student_id', $student->id)->get()->map->getAttributes()->all());
        $this->assertSame([], Storage::disk('local')->allFiles());
        $this->assertSame(0, Notification::count());
    }

    public function test_time_in_is_accepted_while_ongoing_and_reports_the_state(): void
    {
        $student = $this->student('ongoing');

        $this->api('POST', '/api/v1/attendance/time-in', $student->user, $this->capture())
            ->assertOk()
            ->assertJsonPath('submission', $this->submission('ongoing', true, self::ATTENDANCE_MESSAGE))
            ->assertJsonPath('record.time_in_status', 'pending');
    }

    public function test_attendance_history_stays_readable(): void
    {
        $student = $this->student('completed');
        Attendance::factory()->create(['student_id' => $student->id, 'date' => '2026-09-01']);

        $this->api('GET', '/api/v1/attendance', $student->user)
            ->assertOk()
            ->assertJsonCount(1, 'data');
    }

    // ---------------------------------------------------------- reports

    #[DataProvider('allStatuses')]
    public function test_report_list_me_and_dashboard_expose_the_flags(string $status, bool $canSubmit): void
    {
        $student = $this->student($status);
        $pending = InternshipReport::factory()->create(['student_id' => $student->id, 'status' => 'pending']);

        $this->api('GET', '/api/v1/reports', $student->user)
            ->assertOk()
            ->assertJsonPath('meta.submission', $this->submission($status, $canSubmit, self::REPORT_MESSAGE))
            ->assertJsonPath('data.0.can_edit', $canSubmit)
            ->assertJsonPath('data.0.can_delete', true);

        $this->api('GET', "/api/v1/reports/{$pending->id}", $student->user)
            ->assertOk()
            ->assertJsonPath('report.can_edit', $canSubmit)
            ->assertJsonPath('report.can_delete', true);

        $this->api('GET', '/api/v1/me', $student->user)
            ->assertOk()
            ->assertJsonPath('student.internship_status', $status)
            ->assertJsonPath('student.can_submit', $canSubmit);

        $this->api('GET', '/api/v1/dashboard', $student->user)
            ->assertOk()
            ->assertJsonPath('student.internship.status', $status)
            ->assertJsonPath('student.internship.can_submit', $canSubmit);
    }

    #[DataProvider('closedStatuses')]
    public function test_creating_a_report_is_refused_unless_ongoing(string $status): void
    {
        $student = $this->student($status, $this->user(User::ROLE_SUPERVISOR));

        $this->api('POST', '/api/v1/reports', $student->user, $this->reportPayload([
            'attachment' => UploadedFile::fake()->create('proof.pdf', 10, 'application/pdf'),
        ]))
            ->assertStatus(422)
            ->assertExactJson([
                'message' => self::REPORT_MESSAGE,
                'code' => 'report_rule',
                'submission' => $this->submission($status, false, self::REPORT_MESSAGE),
            ]);

        $this->assertSame(0, InternshipReport::count());
        $this->assertSame([], Storage::disk('local')->allFiles());
        $this->assertSame(0, Notification::count());
    }

    public function test_creating_a_report_is_accepted_while_ongoing(): void
    {
        $student = $this->student('ongoing');

        $this->api('POST', '/api/v1/reports', $student->user, $this->reportPayload())
            ->assertCreated()
            ->assertJsonPath('report.can_edit', true);
    }

    public function test_field_validation_still_comes_first(): void
    {
        $student = $this->student('not_started');

        $this->api('POST', '/api/v1/reports', $student->user, [])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['type', 'period_start', 'period_end', 'content'])
            ->assertJsonMissingPath('code');
    }

    #[DataProvider('closedStatuses')]
    public function test_updating_a_report_is_refused_unless_ongoing(string $status): void
    {
        $student = $this->student($status);
        $report = InternshipReport::factory()->create(['student_id' => $student->id, 'status' => 'pending', 'content' => 'Original.']);
        $before = $report->fresh()->getAttributes();

        $this->api('POST', "/api/v1/reports/{$report->id}", $student->user, [
            '_method' => 'PATCH',
            ...$this->samePeriod($report, [
                'content' => 'Changed.',
                'attachment' => UploadedFile::fake()->create('proof.pdf', 10, 'application/pdf'),
            ]),
        ])
            ->assertStatus(422)
            ->assertJsonPath('message', self::REPORT_MESSAGE)
            ->assertJsonPath('code', 'report_rule')
            ->assertJsonPath('submission', $this->submission($status, false, self::REPORT_MESSAGE))
            ->assertJsonPath('report.id', $report->id)
            ->assertJsonPath('report.content', 'Original.')
            ->assertJsonPath('report.can_edit', false)
            ->assertJsonPath('report.can_delete', true);

        $this->assertSame($before, $report->fresh()->getAttributes());
        $this->assertSame([], Storage::disk('local')->allFiles());
    }

    public function test_a_reviewed_report_is_still_reported_as_not_pending(): void
    {
        $student = $this->student('completed');
        $report = InternshipReport::factory()->create(['student_id' => $student->id, 'status' => 'reviewed']);

        $this->api('POST', "/api/v1/reports/{$report->id}", $student->user, [
            '_method' => 'PATCH',
            ...$this->samePeriod($report),
        ])
            ->assertStatus(422)
            ->assertJsonPath('code', 'report_not_pending');
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

        $this->api('GET', "/api/v1/reports/{$kept->id}/attachment", $student->user)->assertOk();

        $this->api('DELETE', "/api/v1/reports/{$pending->id}", $student->user)
            ->assertOk()
            ->assertJsonPath('message', 'Report deleted.');

        $this->assertModelMissing($pending);
        $this->assertModelExists($kept);
    }

    // ------------------------------------------------- staff unaffected

    #[DataProvider('closedStatuses')]
    public function test_staff_actions_are_unaffected(string $status): void
    {
        $supervisor = $this->user(User::ROLE_SUPERVISOR);
        $coordinator = $this->user(User::ROLE_COORDINATOR);
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

        $this->api('POST', "/api/v1/attendance/{$pending->id}/time-in/approve", $supervisor)->assertOk();
        $this->api('POST', "/api/v1/attendance/{$pending->id}/time-out/reject", $supervisor, ['reason' => 'Wrong photo.'])->assertOk();

        $this->assertSame('approved', $pending->fresh()->time_in_status);
        $this->assertSame('rejected', $pending->fresh()->time_out_status);

        $this->api('POST', "/api/v1/monitoring/students/{$student->id}/attendance", $supervisor, [
            'date' => '2026-09-11',
            'time_in' => '08:00',
            'time_out' => '12:00',
        ])->assertSuccessful();

        $this->assertEquals(4.0, $student->attendances()->where('date', '2026-09-11')->value('rendered_hours'));

        $report = InternshipReport::factory()->create(['student_id' => $student->id, 'status' => 'pending']);

        $this->api('POST', "/api/v1/report-reviews/{$report->id}/review", $coordinator, ['comment' => 'Fine.'])
            ->assertOk();

        $this->assertSame('reviewed', $report->fresh()->status);
    }
}
