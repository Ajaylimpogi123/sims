<?php

namespace Tests\Feature\Api\V1;

use App\Models\Attendance;
use App\Models\Notification;
use App\Models\Student;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Module 6: the student's own attendance through /api/v1, sharing every
 * rule, evidence path and notification with the website through
 * AttendanceService.
 */
class AttendanceApiTest extends TestCase
{
    use RefreshDatabase;

    private const URLS = [
        'time_in' => '/api/v1/attendance/time-in',
        'time_out' => '/api/v1/attendance/time-out',
        'emergency' => '/api/v1/attendance/emergency-time-out',
    ];

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

    private function supervisor(): User
    {
        return User::factory()->create(['role_id' => 3, 'status' => 'active']);
    }

    /**
     * @return array{0: User, 1: string, 2: Student}
     */
    private function student(?User $supervisor = null, array $overrides = []): array
    {
        $user = User::factory()->create(['role_id' => 1, 'status' => 'active']);
        $student = Student::factory()->create(array_merge([
            'user_id' => $user->id,
            'supervisor_id' => $supervisor?->id,
        ], $overrides));

        return [$user, $user->createToken('test')->plainTextToken, $student];
    }

    private function tokenFor(int $roleId): string
    {
        return User::factory()->create(['role_id' => $roleId, 'status' => 'active'])
            ->createToken('test')->plainTextToken;
    }

    private function api(string $method, string $uri, ?string $token, array $data = []): TestResponse
    {
        $this->app['auth']->forgetGuards();

        $headers = ['Accept' => 'application/json'];

        if ($token !== null) {
            $headers['Authorization'] = 'Bearer '.$token;
        }

        return $method === 'GET'
            ? $this->withHeaders($headers)->get($uri.($data ? '?'.http_build_query($data) : ''))
            : $this->withHeaders($headers)->post($uri, $data);
    }

    private function payload(array $overrides = []): array
    {
        return array_merge([
            'photo' => UploadedFile::fake()->image('capture.jpg', 640, 480),
            'latitude' => '10.6765432',
            'longitude' => '122.9509876',
            'accuracy' => '12.5',
        ], $overrides);
    }

    private function today(Student $student, array $attributes): Attendance
    {
        return Attendance::factory()->create(array_merge([
            'student_id' => $student->id,
            'date' => today()->toDateString(),
            'time_in' => '08:00:00',
            'time_in_status' => 'approved',
            'time_out' => null,
            'time_out_status' => null,
            'rendered_hours' => null,
        ], $attributes));
    }

    // ---------------------------------------------------------- access

    public function test_every_endpoint_requires_a_token(): void
    {
        [, , $student] = $this->student();
        $attendance = $this->today($student, []);

        $this->api('GET', '/api/v1/attendance/today', null)->assertUnauthorized();
        $this->api('GET', '/api/v1/attendance', null)->assertUnauthorized();

        foreach (self::URLS as $url) {
            $this->api('POST', $url, null, $this->payload(['note' => 'x']))->assertUnauthorized();
        }

        $this->api('GET', "/api/v1/attendance/{$attendance->id}/photo/time_in", null)
            ->assertUnauthorized()
            ->assertExactJson(['message' => 'Unauthenticated.']);
    }

    public static function staffRoles(): array
    {
        return ['coordinator' => [2], 'supervisor' => [3], 'admin' => [4]];
    }

    #[DataProvider('staffRoles')]
    public function test_only_students_can_use_the_attendance_endpoints(int $roleId): void
    {
        $token = $this->tokenFor($roleId);

        $this->api('GET', '/api/v1/attendance/today', $token)->assertForbidden()
            ->assertExactJson(['message' => 'Unauthorized access']);
        $this->api('GET', '/api/v1/attendance', $token)->assertForbidden();

        foreach (self::URLS as $url) {
            $this->api('POST', $url, $token, $this->payload(['note' => 'x']))->assertForbidden();
        }

        $this->assertSame(0, Attendance::count());
        $this->assertSame([], Storage::disk('local')->allFiles());
    }

    public function test_student_without_a_profile_gets_409_with_a_code(): void
    {
        $token = $this->tokenFor(1);
        $expected = [
            'message' => 'No student profile is linked to your account yet. Please contact your coordinator.',
            'code' => 'no_student_profile',
        ];

        $this->api('GET', '/api/v1/attendance/today', $token)->assertStatus(409)->assertExactJson($expected);
        $this->api('GET', '/api/v1/attendance', $token)->assertStatus(409)->assertExactJson($expected);

        foreach (self::URLS as $url) {
            $this->api('POST', $url, $token, $this->payload(['note' => 'x']))
                ->assertStatus(409)->assertExactJson($expected);
        }

        $this->assertSame([], Storage::disk('local')->allFiles());
    }

    // ----------------------------------------------------------- today

    public function test_today_without_a_record_allows_only_time_in(): void
    {
        [, $token, $student] = $this->student();
        // Older rows count toward hours but are not "today".
        Attendance::factory()->create([
            'student_id' => $student->id, 'date' => '2026-10-01', 'rendered_hours' => 8.5,
        ]);

        $this->api('GET', '/api/v1/attendance/today', $token)
            ->assertOk()
            ->assertExactJson([
                'date' => '2026-10-03',
                'record' => null,
                'actions' => [
                    'time_in' => ['allowed' => true, 'reason' => null],
                    'time_out' => ['allowed' => false, 'reason' => 'Your time-in must be approved before you can time out.'],
                    'emergency_time_out' => ['allowed' => false, 'reason' => 'You must time in before using emergency time-out.'],
                ],
                'hours' => ['rendered' => 8.5, 'required' => 486, 'remaining' => 477.5],
            ]);
    }

    public function test_today_hours_without_required_hours(): void
    {
        [, $token] = $this->student(overrides: ['required_hours' => null]);

        $this->api('GET', '/api/v1/attendance/today', $token)
            ->assertOk()
            ->assertJsonPath('hours', ['rendered' => 0, 'required' => null, 'remaining' => null]);
    }

    public function test_today_uses_the_manila_date(): void
    {
        // 23:30 UTC on Oct 2 is already Oct 3 07:30 in Manila.
        Carbon::setTestNow(Carbon::parse('2026-10-02 23:30:00', 'UTC'));
        [, $token, $student] = $this->student();
        $this->today($student, ['time_in_status' => 'pending']);

        $this->api('GET', '/api/v1/attendance/today', $token)
            ->assertOk()
            ->assertJsonPath('date', '2026-10-03')
            ->assertJsonPath('record.time_in', '2026-10-03T08:00:00+08:00');
    }

    /**
     * Today's row state => which of time_in / time_out / emergency are allowed.
     * Mirrors the website's buttons (Attendance/Index.jsx) and the service.
     */
    public static function states(): array
    {
        return [
            'no record' => [null, [
                'time_in' => null,
                'time_out' => 'Your time-in must be approved before you can time out.',
                'emergency' => 'You must time in before using emergency time-out.',
            ]],
            'time-in pending' => [['time_in_status' => 'pending'], [
                'time_in' => 'You already have a time-in request for today.',
                'time_out' => 'Your time-in must be approved before you can time out.',
                'emergency' => null,
            ]],
            'time-in approved' => [['time_in_status' => 'approved'], [
                'time_in' => 'You already have a time-in request for today.',
                'time_out' => null,
                'emergency' => null,
            ]],
            'time-in rejected' => [['time_in_status' => 'rejected', 'time_in_rejection_reason' => 'Blurry'], [
                'time_in' => null,
                'time_out' => 'Your time-in must be approved before you can time out.',
                'emergency' => 'Your time-in was rejected. Emergency time-out requires a non-rejected time-in.',
            ]],
            'time-out pending' => [['time_out' => '17:00:00', 'time_out_status' => 'pending'], [
                'time_in' => 'You already have a time-in request for today.',
                'time_out' => 'You already have a time-out request for today.',
                'emergency' => 'You already have a time-out request for today.',
            ]],
            'time-out rejected' => [['time_out' => '17:00:00', 'time_out_status' => 'rejected'], [
                'time_in' => 'You already have a time-in request for today.',
                'time_out' => null,
                'emergency' => null,
            ]],
            'both approved' => [['time_out' => '17:00:00', 'time_out_status' => 'approved', 'rendered_hours' => 9], [
                'time_in' => 'You already have a time-in request for today.',
                'time_out' => 'You already have a time-out request for today.',
                'emergency' => 'You already have a time-out request for today.',
            ]],
            'emergency pending on pending time-in' => [[
                'time_in_status' => 'pending', 'time_out' => '12:00:00', 'time_out_status' => 'pending',
                'is_emergency' => true, 'note' => 'Sick',
            ], [
                'time_in' => 'You already have a time-in request for today.',
                'time_out' => 'Your time-in must be approved before you can time out.',
                'emergency' => 'You already have a time-out request for today.',
            ]],
            'staff row without a time-in' => [['time_in' => null, 'time_in_status' => null], [
                'time_in' => null,
                'time_out' => 'Your time-in must be approved before you can time out.',
                'emergency' => 'You must time in before using emergency time-out.',
            ]],
        ];
    }

    #[DataProvider('states')]
    public function test_today_actions_match_the_state_rules(?array $state, array $expected): void
    {
        [, $token, $student] = $this->student();

        if ($state !== null) {
            $this->today($student, $state);
        }

        $response = $this->api('GET', '/api/v1/attendance/today', $token)->assertOk();

        foreach (['time_in' => 'time_in', 'time_out' => 'time_out', 'emergency' => 'emergency_time_out'] as $key => $action) {
            $response->assertJsonPath("actions.{$action}", [
                'allowed' => $expected[$key] === null,
                'reason' => $expected[$key],
            ]);
        }
    }

    /**
     * The advertised actions are exactly what the POST endpoints accept:
     * allowed => 200, refused => 422 with the same reason, nothing stored.
     */
    #[DataProvider('states')]
    public function test_each_post_succeeds_exactly_when_today_allows_it(?array $state, array $expected): void
    {
        foreach (self::URLS as $key => $url) {
            [, $token, $student] = $this->student();

            if ($state !== null) {
                $this->today($student, $state);
            }

            $before = Attendance::where('student_id', $student->id)->first()?->getAttributes();
            $response = $this->api('POST', $url, $token, $this->payload(['note' => 'Family emergency.']));

            if ($expected[$key] === null) {
                $response->assertOk();
                $this->assertNotEmpty(Storage::disk('local')->files("attendance-photos/{$student->id}"), "{$key} stored no photo");
            } else {
                $response->assertStatus(422)
                    ->assertJsonPath('message', $expected[$key])
                    ->assertJsonPath('code', 'attendance_rule')
                    ->assertJsonMissingPath('errors')
                    ->assertJsonPath('today.date', '2026-10-03');
                $this->assertSame([], Storage::disk('local')->files("attendance-photos/{$student->id}"));
                $this->assertSame($before, Attendance::where('student_id', $student->id)->first()?->getAttributes());
            }
        }
    }

    // ---------------------------------------------------------- submit

    public function test_time_in_stores_evidence_and_returns_the_fresh_today_state(): void
    {
        $supervisor = $this->supervisor();
        [$user, $token, $student] = $this->student($supervisor);

        $response = $this->api('POST', self::URLS['time_in'], $token, $this->payload(['mocked' => '1']))
            ->assertOk()
            ->assertJsonPath('message', 'Time-in submitted for approval.')
            ->assertJsonPath('date', '2026-10-03')
            ->assertJsonPath('actions.time_in', ['allowed' => false, 'reason' => 'You already have a time-in request for today.'])
            ->assertJsonPath('actions.emergency_time_out.allowed', true);

        $record = Attendance::sole();

        $this->assertSame($student->id, $record->student_id);
        $this->assertSame('08:15:00', $record->time_in);
        $this->assertSame('pending', $record->time_in_status);
        $this->assertSame($user->id, $record->recorded_by);
        $this->assertSame('10.6765432', $record->time_in_latitude);
        $this->assertSame('122.9509876', $record->time_in_longitude);
        $this->assertSame('12.50', $record->time_in_accuracy);
        $this->assertTrue($record->time_in_mocked);
        $this->assertNull($record->time_out_mocked);
        $this->assertStringStartsWith("attendance-photos/{$student->id}/", $record->time_in_photo_path);
        Storage::disk('local')->assertExists($record->time_in_photo_path);

        $response->assertJsonPath('record', [
            'id' => $record->id,
            'date' => '2026-10-03',
            'time_in' => '2026-10-03T08:15:00+08:00',
            'time_in_status' => 'pending',
            'time_in_rejection_reason' => null,
            'time_in_latitude' => 10.6765432,
            'time_in_longitude' => 122.9509876,
            'time_in_accuracy' => 12.5,
            'time_in_mocked' => true,
            'time_in_photo_url' => route('api.v1.attendance.photo', [
                'attendance' => $record->id, 'leg' => 'time_in', 'v' => substr(sha1($record->time_in_photo_path), 0, 12),
            ]),
            'time_out' => null,
            'time_out_status' => null,
            'time_out_rejection_reason' => null,
            'time_out_latitude' => null,
            'time_out_longitude' => null,
            'time_out_accuracy' => null,
            'time_out_mocked' => null,
            'time_out_photo_url' => null,
            'rendered_hours' => null,
            'is_emergency' => false,
            'note' => null,
        ]);
    }

    public function test_submissions_notify_the_supervisor_and_admins_like_the_website(): void
    {
        $supervisor = $this->supervisor();
        $admin = User::factory()->create(['role_id' => 4]);
        $coordinator = User::factory()->create(['role_id' => 2]);
        [$user, $token, $student] = $this->student($supervisor);

        $this->api('POST', self::URLS['time_in'], $token, $this->payload())->assertOk();
        Attendance::sole()->update(['time_in_status' => 'approved']);
        $this->api('POST', self::URLS['time_out'], $token, $this->payload())->assertOk();

        $record = Attendance::sole();

        foreach ([$supervisor, $admin] as $recipient) {
            $notes = Notification::where('user_id', $recipient->id)->orderBy('id')->get();
            $this->assertCount(2, $notes);
            $this->assertSame(['attendance_pending', 'attendance_pending'], $notes->pluck('type')->all());
            $this->assertSame(
                "{$user->name} submitted a time-in for October 3, 2026 awaiting your approval.",
                $notes[0]->body,
            );
            $this->assertStringContainsString('submitted a time-out', $notes[1]->body);
            $this->assertSame($record->id, $notes[0]->data['attendance_id'] ?? null);
        }

        $this->assertSame(0, Notification::where('user_id', $coordinator->id)->count());
        $this->assertSame(0, Notification::where('user_id', $user->id)->count());
    }

    public function test_time_out_and_emergency_store_on_the_time_out_leg(): void
    {
        [, $token, $student] = $this->student();
        $record = $this->today($student, ['time_in_status' => 'approved', 'time_in_mocked' => false]);

        $this->api('POST', self::URLS['time_out'], $token, $this->payload(['mocked' => 'false']))
            ->assertOk()
            ->assertJsonPath('message', 'Time-out submitted for approval.')
            ->assertJsonPath('record.time_out', '2026-10-03T08:15:00+08:00')
            ->assertJsonPath('record.time_out_status', 'pending')
            ->assertJsonPath('record.time_out_mocked', false)
            ->assertJsonPath('record.time_in_mocked', false)
            ->assertJsonPath('record.is_emergency', false);

        $record->refresh();
        $this->assertFalse($record->time_out_mocked);
        Storage::disk('local')->assertExists($record->time_out_photo_path);

        // Emergency after a rejected time-out (same rules as the website).
        $record->update(['time_out_status' => 'rejected']);

        $this->api('POST', self::URLS['emergency'], $token, $this->payload(['note' => 'Family emergency.', 'mocked' => 'true']))
            ->assertOk()
            ->assertJsonPath('message', 'Emergency time-out submitted for approval.')
            ->assertJsonPath('record.is_emergency', true)
            ->assertJsonPath('record.note', 'Family emergency.')
            ->assertJsonPath('record.time_out_mocked', true);
    }

    public static function mockedValues(): array
    {
        return [
            '"1"' => ['1', true],
            '"true"' => ['true', true],
            '"0"' => ['0', false],
            '"false"' => ['false', false],
            'empty' => ['', null],
            'omitted' => [null, null],
        ];
    }

    #[DataProvider('mockedValues')]
    public function test_mocked_flag_is_stored_as_sent(?string $sent, ?bool $stored): void
    {
        [, $token] = $this->student();
        $payload = $this->payload();

        if ($sent !== null) {
            $payload['mocked'] = $sent;
        }

        $this->api('POST', self::URLS['time_in'], $token, $payload)
            ->assertOk()
            ->assertJsonPath('record.time_in_mocked', $stored);

        $this->assertSame($stored, Attendance::sole()->time_in_mocked);
    }

    public static function invalidInput(): array
    {
        $cases = [];

        foreach (['time_in', 'time_out', 'emergency'] as $action) {
            $cases["{$action}: missing photo"] = [$action, ['photo' => null], 'photo'];
            $cases["{$action}: non-image photo"] = [$action, ['photo' => UploadedFile::fake()->create('doc.pdf', 10, 'application/pdf')], 'photo'];
            $cases["{$action}: gif photo"] = [$action, ['photo' => UploadedFile::fake()->image('a.gif')], 'photo'];
            $cases["{$action}: oversized photo"] = [$action, ['photo' => UploadedFile::fake()->image('big.jpg')->size(5121)], 'photo'];
            $cases["{$action}: missing latitude"] = [$action, ['latitude' => null], 'latitude'];
            $cases["{$action}: latitude out of range"] = [$action, ['latitude' => '90.1'], 'latitude'];
            $cases["{$action}: latitude not numeric"] = [$action, ['latitude' => 'north'], 'latitude'];
            $cases["{$action}: longitude out of range"] = [$action, ['longitude' => '-180.5'], 'longitude'];
            $cases["{$action}: missing longitude"] = [$action, ['longitude' => null], 'longitude'];
            $cases["{$action}: negative accuracy"] = [$action, ['accuracy' => '-1'], 'accuracy'];
            $cases["{$action}: mocked not a boolean"] = [$action, ['mocked' => 'yes'], 'mocked'];
            $cases["{$action}: mocked array"] = [$action, ['mocked' => ['1']], 'mocked'];
        }

        $cases['emergency: missing note'] = ['emergency', ['note' => null], 'note'];
        $cases['emergency: note too long'] = ['emergency', ['note' => str_repeat('a', 1001)], 'note'];
        $cases['emergency: invalid UTF-8 note'] = ['emergency', ['note' => "bad \xB1\x31"], 'note'];

        return $cases;
    }

    #[DataProvider('invalidInput')]
    public function test_invalid_input_is_a_422_and_stores_nothing(string $action, array $override, string $errorKey): void
    {
        [, $token, $student] = $this->student();

        if ($action !== 'time_in') {
            $this->today($student, ['time_in_status' => 'approved']);
        }

        $payload = array_filter(
            $this->payload(array_merge($action === 'emergency' ? ['note' => 'Sick.'] : [], $override)),
            fn ($value) => $value !== null,
        );

        $this->api('POST', self::URLS[$action], $token, $payload)
            ->assertStatus(422)
            ->assertJsonValidationErrors($errorKey);

        $this->assertSame([], Storage::disk('local')->allFiles());
        $this->assertSame(0, Notification::count());
    }

    public function test_accuracy_is_optional_and_clamped_like_the_website(): void
    {
        [, $token, $student] = $this->student();
        $payload = $this->payload(['accuracy' => '1234567.89']);

        $this->api('POST', self::URLS['time_in'], $token, $payload)
            ->assertOk()
            ->assertJsonPath('record.time_in_accuracy', 999999.99);

        [, $token2] = $this->student();
        $payload = $this->payload();
        unset($payload['accuracy']);

        $this->api('POST', self::URLS['time_in'], $token2, $payload)
            ->assertOk()
            ->assertJsonPath('record.time_in_accuracy', null);
    }

    public function test_resubmitting_a_rejected_time_in_replaces_the_photo_and_resets_the_flag(): void
    {
        [, $token, $student] = $this->student();

        $this->api('POST', self::URLS['time_in'], $token, $this->payload(['mocked' => '1']))->assertOk();
        $record = Attendance::sole();
        $oldPath = $record->time_in_photo_path;
        $record->update(['time_in_status' => 'rejected', 'time_in_rejection_reason' => 'Blurry']);

        $this->api('POST', self::URLS['time_in'], $token, $this->payload())
            ->assertOk()
            ->assertJsonPath('record.time_in_status', 'pending')
            ->assertJsonPath('record.time_in_rejection_reason', null)
            ->assertJsonPath('record.time_in_mocked', null);

        $record->refresh();
        $this->assertNotSame($oldPath, $record->time_in_photo_path);
        Storage::disk('local')->assertMissing($oldPath);
        Storage::disk('local')->assertExists($record->time_in_photo_path);
    }

    public function test_racing_time_ins_get_a_rule_422_not_a_500(): void
    {
        [, $token, $student] = $this->student();

        // Another request creates today's row between the lookup and the
        // insert, so this one hits the (student_id, date) unique index.
        $raced = false;
        Attendance::creating(function () use (&$raced, $student) {
            if ($raced) {
                return;
            }

            $raced = true;
            DB::table('attendances')->insert([
                'student_id' => $student->id,
                'date' => today()->toDateString(),
                'time_in' => '08:14:00',
                'time_in_status' => 'pending',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        });

        $this->api('POST', self::URLS['time_in'], $token, $this->payload())
            ->assertStatus(422)
            ->assertJsonPath('message', 'You already have a time-in request for today.')
            ->assertJsonPath('code', 'attendance_rule')
            ->assertJsonPath('today.record.time_in', '2026-10-03T08:14:00+08:00');

        $this->assertSame([], Storage::disk('local')->allFiles());
    }

    /**
     * Same inputs through the website and the API produce the same row; the
     * only difference is the fake-GPS flag, which the website can't know.
     */
    public function test_api_and_website_store_identical_records_for_the_same_input(): void
    {
        $supervisor = $this->supervisor();
        [$webUser, , $webStudent] = $this->student($supervisor);
        [, $token, $apiStudent] = $this->student($supervisor);

        $this->actingAs($webUser)->post('/my-attendance/time-in', $this->payload())
            ->assertRedirect(route('attendance.index', absolute: false));
        $this->api('POST', self::URLS['time_in'], $token, $this->payload(['mocked' => '0']))->assertOk();

        $comparable = function (Student $student): array {
            $attributes = Attendance::where('student_id', $student->id)->sole()->getAttributes();
            unset($attributes['id'], $attributes['student_id'], $attributes['recorded_by'],
                $attributes['created_at'], $attributes['updated_at'], $attributes['time_in_photo_path'], $attributes['time_in_mocked']);

            return $attributes;
        };

        $this->assertSame($comparable($webStudent), $comparable($apiStudent));
        $this->assertNull(Attendance::where('student_id', $webStudent->id)->sole()->time_in_mocked);
        $this->assertFalse(Attendance::where('student_id', $apiStudent->id)->sole()->time_in_mocked);
        $this->assertSame(2, Notification::where('user_id', $supervisor->id)->count());
    }

    // --------------------------------------------------------- history

    public function test_history_is_own_records_newest_first_with_cursor_pages(): void
    {
        [, $token, $student] = $this->student();
        [, , $other] = $this->student();

        foreach (['2026-09-29', '2026-10-01', '2026-09-30', '2026-10-02', '2026-10-03'] as $date) {
            Attendance::factory()->create(['student_id' => $student->id, 'date' => $date]);
        }
        Attendance::factory()->create(['student_id' => $other->id, 'date' => '2026-10-02']);

        $first = $this->api('GET', '/api/v1/attendance', $token, ['per_page' => 2])
            ->assertOk()
            ->assertJsonPath('meta.per_page', 2)
            ->assertJsonPath('meta.has_more', true);
        $this->assertSame(['2026-10-03', '2026-10-02'], array_column($first->json('data'), 'date'));

        $second = $this->api('GET', '/api/v1/attendance', $token, ['per_page' => 2, 'cursor' => $first->json('meta.next_cursor')])
            ->assertOk();
        $this->assertSame(['2026-10-01', '2026-09-30'], array_column($second->json('data'), 'date'));

        $third = $this->api('GET', '/api/v1/attendance', $token, ['per_page' => 2, 'cursor' => $second->json('meta.next_cursor')])
            ->assertOk()
            ->assertJsonPath('meta.has_more', false)
            ->assertJsonPath('meta.next_cursor', null);
        $this->assertSame(['2026-09-29'], array_column($third->json('data'), 'date'));

        $this->api('GET', '/api/v1/attendance', $token)
            ->assertOk()
            ->assertJsonCount(5, 'data')
            ->assertJsonPath('meta.per_page', 20);
    }

    public function test_history_items_carry_statuses_reasons_hours_and_evidence(): void
    {
        [, $token, $student] = $this->student();
        Storage::disk('local')->put("attendance-photos/{$student->id}/out.jpg", 'jpeg');

        $record = Attendance::factory()->create([
            'student_id' => $student->id,
            'date' => '2026-10-01',
            'time_in' => '08:00:00',
            'time_in_status' => 'approved',
            'time_out' => '17:30:00',
            'time_out_status' => 'rejected',
            'time_out_rejection_reason' => 'Wrong place',
            'time_out_latitude' => '10.1234567',
            'time_out_longitude' => '122.7654321',
            'time_out_accuracy' => '30.00',
            'time_out_mocked' => true,
            'time_out_photo_path' => "attendance-photos/{$student->id}/out.jpg",
            'rendered_hours' => null,
            'is_emergency' => true,
            'note' => 'Sick',
        ]);

        $item = $this->api('GET', '/api/v1/attendance', $token)->assertOk()->json('data.0');

        $this->assertSame($record->id, $item['id']);
        $this->assertSame('2026-10-01T08:00:00+08:00', $item['time_in']);
        $this->assertSame('2026-10-01T17:30:00+08:00', $item['time_out']);
        $this->assertSame('rejected', $item['time_out_status']);
        $this->assertSame('Wrong place', $item['time_out_rejection_reason']);
        $this->assertSame(10.1234567, $item['time_out_latitude']);
        $this->assertSame(30.0, (float) $item['time_out_accuracy']);
        $this->assertTrue($item['time_out_mocked']);
        $this->assertNull($item['time_in_mocked']);
        $this->assertNull($item['time_in_photo_url']);
        $this->assertNull($item['time_in_latitude']);
        $this->assertTrue($item['is_emergency']);
        $this->assertSame('Sick', $item['note']);
        $this->assertNull($item['rendered_hours']);

        // The photo URL works with the same token.
        $this->api('GET', $item['time_out_photo_url'], $token)->assertOk();
    }

    public function test_history_rendered_hours_are_numbers(): void
    {
        [, $token, $student] = $this->student();
        Attendance::factory()->create(['student_id' => $student->id, 'date' => '2026-10-01', 'rendered_hours' => 8.25]);

        $this->api('GET', '/api/v1/attendance', $token)->assertOk()->assertJsonPath('data.0.rendered_hours', 8.25);
    }

    public static function badListQueries(): array
    {
        $encode = fn (array $payload) => rtrim(strtr(base64_encode(json_encode($payload)), '+/', '-_'), '=');

        return [
            'per_page 0' => [['per_page' => 0], 'per_page'],
            'per_page 51' => [['per_page' => 51], 'per_page'],
            'per_page text' => [['per_page' => 'all'], 'per_page'],
            'garbage cursor' => [['cursor' => 'not-a-cursor'], 'cursor'],
            'notification cursor' => [['cursor' => $encode(['created_at' => '2026-10-01 08:00:00', 'id' => 5, '_pointsToNextItems' => true])], 'cursor'],
            'injected date' => [['cursor' => $encode(['date' => "2026-10-01' OR 1=1", 'id' => 5, '_pointsToNextItems' => true])], 'cursor'],
            'impossible date' => [['cursor' => $encode(['date' => '2026-02-30 00:00:00', 'id' => 5, '_pointsToNextItems' => true])], 'cursor'],
            'string id' => [['cursor' => $encode(['date' => '2026-10-01 00:00:00', 'id' => '5', '_pointsToNextItems' => true])], 'cursor'],
            'previous-page cursor' => [['cursor' => $encode(['date' => '2026-10-01 00:00:00', 'id' => 5, '_pointsToNextItems' => false])], 'cursor'],
            'array cursor' => [['cursor' => ['a']], 'cursor'],
        ];
    }

    #[DataProvider('badListQueries')]
    public function test_bad_list_queries_are_422(array $query, string $key): void
    {
        [, $token] = $this->student();

        $this->api('GET', '/api/v1/attendance', $token, $query)
            ->assertStatus(422)
            ->assertJsonValidationErrors($key);
    }

    // ----------------------------------------------------------- photo

    /**
     * @return array{0: Attendance, 1: string, 2: User} record, owner token, supervisor
     */
    private function recordWithPhoto(): array
    {
        $supervisor = $this->supervisor();
        [, $token, $student] = $this->student($supervisor);
        $path = "attendance-photos/{$student->id}/in.jpg";
        Storage::disk('local')->put($path, 'jpeg-bytes');

        return [$this->today($student, ['time_in_photo_path' => $path]), $token, $supervisor];
    }

    public function test_owner_can_download_their_photo(): void
    {
        [$record, $token] = $this->recordWithPhoto();

        $response = $this->api('GET', "/api/v1/attendance/{$record->id}/photo/time_in?v=abc", $token)->assertOk();

        $this->assertSame('jpeg-bytes', $response->streamedContent());
        $this->assertStringContainsString('private', $response->headers->get('Cache-Control'));
    }

    public function test_photo_is_404_for_anyone_who_cannot_view_the_record(): void
    {
        [$record] = $this->recordWithPhoto();
        [, $otherStudentToken] = $this->student();

        foreach ([$otherStudentToken, $this->tokenFor(1), $this->supervisor()->createToken('t')->plainTextToken] as $token) {
            $this->api('GET', "/api/v1/attendance/{$record->id}/photo/time_in", $token)
                ->assertNotFound()
                ->assertExactJson(['message' => 'Not found.']);
        }
    }

    public function test_photo_is_viewable_by_the_assigned_supervisor_coordinator_and_admin(): void
    {
        [$record, , $supervisor] = $this->recordWithPhoto();

        foreach ([$supervisor->createToken('t')->plainTextToken, $this->tokenFor(2), $this->tokenFor(4)] as $token) {
            $this->api('GET', "/api/v1/attendance/{$record->id}/photo/time_in", $token)->assertOk();
        }
    }

    public function test_photo_404s_for_missing_legs_files_ids_and_bad_legs(): void
    {
        [$record, $token] = $this->recordWithPhoto();

        $this->api('GET', "/api/v1/attendance/{$record->id}/photo/time_out", $token)->assertNotFound();
        $this->api('GET', "/api/v1/attendance/{$record->id}/photo/note", $token)->assertNotFound();
        $this->api('GET', "/api/v1/attendance/{$record->id}/photo/time_in_photo_path", $token)->assertNotFound();
        $this->api('GET', '/api/v1/attendance/999999/photo/time_in', $token)->assertNotFound()
            ->assertExactJson(['message' => 'Not found.']);
        $this->api('GET', '/api/v1/attendance/abc/photo/time_in', $token)->assertNotFound();

        Storage::disk('local')->delete($record->time_in_photo_path);
        $this->api('GET', "/api/v1/attendance/{$record->id}/photo/time_in", $token)->assertNotFound();
    }
}
