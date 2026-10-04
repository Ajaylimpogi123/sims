<?php

namespace Tests\Feature\Api\V1;

use App\Models\Attendance;
use App\Models\Company;
use App\Models\Notification;
use App\Models\Student;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Module 10: Attendance & Progress Monitoring through /api/v1. Coordinator
 * view-only, Supervisor own students, Administrator everyone; reads share
 * the website's Student::monitoredBy() query, writes the website's
 * validation, policies and AttendanceService.
 */
class MonitoringApiTest extends TestCase
{
    use RefreshDatabase;

    private int $day = 1;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);
        Storage::fake('local');
        Carbon::setTestNow(Carbon::parse('2026-10-03 18:00:00', 'Asia/Manila'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    private function user(int $roleId, array $overrides = []): User
    {
        return User::factory()->create(array_merge(['role_id' => $roleId, 'status' => 'active'], $overrides));
    }

    private function student(?User $supervisor, array $overrides = [], array $userOverrides = []): Student
    {
        return Student::factory()->create(array_merge([
            'user_id' => $this->user(1, $userOverrides)->id,
            'supervisor_id' => $supervisor?->id,
        ], $overrides));
    }

    /**
     * An approved, credited day; each call one day further back.
     */
    private function record(Student $student, array $attributes = []): Attendance
    {
        return Attendance::factory()->create(array_merge([
            'student_id' => $student->id,
            'date' => today()->subDays($this->day++)->toDateString(),
        ], $attributes));
    }

    private function api(string $method, string $uri, ?User $user, array $data = [], bool $json = true): TestResponse
    {
        $this->app['auth']->forgetGuards();
        $this->defaultHeaders = [];

        $headers = ['Accept' => 'application/json'];

        if ($user !== null) {
            $headers['Authorization'] = 'Bearer '.$user->createToken('test')->plainTextToken;
        }

        if ($method === 'GET') {
            return $this->withHeaders($headers)->get($uri.($data ? '?'.http_build_query($data) : ''));
        }

        return $json
            ? $this->withHeaders($headers)->json($method, $uri, $data)
            : $this->withHeaders($headers)->call($method, $uri, $data, [], [], $this->transformHeadersToServerVars($headers));
    }

    private function web(string $method, string $uri, User $user, array $data = []): TestResponse
    {
        $this->app['auth']->forgetGuards();
        $this->defaultHeaders = [];

        return $this->actingAs($user)->call($method, $uri, $data);
    }

    /**
     * @return list<array{0: string, 1: string, 2: array}>
     */
    private function readUrls(Student $student): array
    {
        return [
            ['GET', '/api/v1/monitoring/students', []],
            ['GET', "/api/v1/monitoring/students/{$student->id}", []],
            ['GET', "/api/v1/monitoring/students/{$student->id}/attendance", []],
            ['GET', '/api/v1/progress', []],
        ];
    }

    /**
     * @return list<array{0: string, 1: string, 2: array}>
     */
    private function writeUrls(Student $student, Attendance $record): array
    {
        return [
            ['POST', "/api/v1/monitoring/students/{$student->id}/attendance", ['date' => '2026-09-01', 'time_in' => '08:00', 'time_out' => '17:00']],
            ['PATCH', "/api/v1/monitoring/attendance/{$record->id}", ['date' => $record->date->format('Y-m-d'), 'time_in' => '09:00', 'time_out' => null]],
            ['DELETE', "/api/v1/monitoring/attendance/{$record->id}", []],
            ['PATCH', "/api/v1/monitoring/students/{$student->id}/required-hours", ['required_hours' => 100]],
        ];
    }

    private function assertUntouched(Student $student, Attendance $record): void
    {
        $this->assertSame(486, $student->fresh()->required_hours);
        $this->assertSame(1, Attendance::where('student_id', $student->id)->count());
        $fresh = $record->fresh();
        $this->assertNotNull($fresh);
        $this->assertSame('08:00:00', $fresh->time_in);
        $this->assertSame('17:00:00', $fresh->time_out);
    }

    // ---------------------------------------------------------- access

    public function test_every_endpoint_requires_a_token(): void
    {
        $student = $this->student($this->user(3));
        $record = $this->record($student);

        foreach ([...$this->readUrls($student), ...$this->writeUrls($student, $record)] as [$method, $uri, $data]) {
            $this->api($method, $uri, null, $data)->assertUnauthorized();
        }

        $this->assertUntouched($student, $record);
    }

    public function test_students_get_403_everywhere_even_for_themselves(): void
    {
        $student = $this->student($this->user(3));
        $record = $this->record($student);

        foreach ([...$this->readUrls($student), ...$this->writeUrls($student, $record)] as [$method, $uri, $data]) {
            $this->api($method, $uri, $student->user, $data)
                ->assertForbidden()
                ->assertExactJson(['message' => 'Unauthorized access']);
        }

        $this->assertUntouched($student, $record);
    }

    public function test_coordinator_reads_everything_but_every_write_is_403(): void
    {
        $coordinator = $this->user(2);
        $student = $this->student($this->user(3));
        $record = $this->record($student);

        foreach ($this->readUrls($student) as [$method, $uri]) {
            $this->api($method, $uri, $coordinator)->assertOk();
        }

        $this->api('GET', '/api/v1/monitoring/students', $coordinator)
            ->assertJsonPath('data.0.can_edit_attendance', false)
            ->assertJsonPath('data.0.can_edit_required_hours', false);
        $this->api('GET', "/api/v1/monitoring/students/{$student->id}/attendance", $coordinator)
            ->assertJsonPath('data.0.can_edit', false)
            ->assertJsonPath('data.0.can_delete', false);

        foreach ($this->writeUrls($student, $record) as [$method, $uri, $data]) {
            $this->api($method, $uri, $coordinator, $data)
                ->assertForbidden()
                ->assertExactJson(['message' => 'Unauthorized access']);
        }

        // An unknown id is a plain 404 (route binding runs first).
        $this->api('PATCH', '/api/v1/monitoring/attendance/999999', $coordinator, ['date' => '2026-09-01'])->assertNotFound();

        $this->assertUntouched($student, $record);
        $this->assertSame(0, Notification::count());
    }

    public function test_two_supervisors_never_see_or_change_each_others_students(): void
    {
        $alice = $this->user(3);
        $bob = $this->user(3);
        $aliceStudent = $this->student($alice);
        $bobStudent = $this->student($bob);
        $this->student(null);
        $bobRecord = $this->record($bobStudent);

        foreach (['/api/v1/monitoring/students', '/api/v1/progress'] as $uri) {
            $this->api('GET', $uri, $alice)->assertOk()
                ->assertJsonCount(1, 'data')
                ->assertJsonPath('data.0.id', $aliceStudent->id)
                ->assertJsonPath('meta.total', 1);
        }

        foreach ($this->readUrls($bobStudent) as [$method, $uri]) {
            if (str_contains($uri, (string) $bobStudent->id)) {
                $this->api($method, $uri, $alice)->assertNotFound()->assertExactJson(['message' => 'Not found.']);
            }
        }

        foreach ($this->writeUrls($bobStudent, $bobRecord) as [$method, $uri, $data]) {
            $this->api($method, $uri, $alice, $data)->assertNotFound()->assertExactJson(['message' => 'Not found.']);
        }

        $this->assertUntouched($bobStudent, $bobRecord);
    }

    public function test_unknown_ids_are_404_for_supervisor_and_admin(): void
    {
        foreach ([$this->user(3), $this->user(4)] as $user) {
            foreach ([
                ['GET', '/api/v1/monitoring/students/999999', []],
                ['GET', '/api/v1/monitoring/students/999999/attendance', []],
                ['POST', '/api/v1/monitoring/students/999999/attendance', ['date' => '2026-09-01']],
                ['PATCH', '/api/v1/monitoring/students/999999/required-hours', ['required_hours' => 5]],
                ['PATCH', '/api/v1/monitoring/attendance/999999', ['date' => '2026-09-01']],
                ['DELETE', '/api/v1/monitoring/attendance/999999', []],
                ['GET', '/api/v1/monitoring/students/abc', []],
                ['GET', '/api/v1/monitoring/students/0', []],
            ] as [$method, $uri, $data]) {
                $this->api($method, $uri, $user, $data)->assertNotFound();
            }
        }
    }

    public function test_admin_sees_and_manages_every_student_including_unsupervised(): void
    {
        $admin = $this->user(4);
        $unsupervised = $this->student(null);
        $this->student($this->user(3));

        $this->api('GET', '/api/v1/monitoring/students', $admin)->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.can_edit_attendance', true)
            ->assertJsonPath('data.1.can_edit_required_hours', true);

        $this->api('POST', "/api/v1/monitoring/students/{$unsupervised->id}/attendance", $admin, ['date' => '2026-09-01', 'time_in' => '08:00', 'time_out' => '12:30'])
            ->assertCreated()
            ->assertJsonPath('attendance.rendered_hours', 4.5)
            ->assertJsonPath('student.rendered_hours', 4.5);
    }

    // ------------------------------------------------------------ lists

    public function test_student_item_shape_progress_and_flags(): void
    {
        $supervisor = $this->user(3, ['name' => 'Sup Ervisor']);
        $company = Company::factory()->create(['company_name' => 'Acme Corp']);
        $student = $this->student($supervisor, [
            'company_id' => $company->id,
            'student_number' => '2023-0001',
            'course' => 'BSIT',
            'section' => 'A',
            'internship_status' => 'ongoing',
            'required_hours' => 200,
        ], ['name' => 'Juan Dela Cruz']);
        $this->record($student, ['rendered_hours' => 8]);
        $this->record($student, ['rendered_hours' => 4.25]);
        $this->record($student, ['rendered_hours' => null, 'time_out' => null, 'time_out_status' => null]);

        $this->api('GET', "/api/v1/monitoring/students/{$student->id}", $supervisor)->assertOk()
            ->assertExactJson(['data' => [
                'id' => $student->id,
                'user_id' => $student->user_id,
                'name' => 'Juan Dela Cruz',
                'student_number' => '2023-0001',
                'course' => 'BSIT',
                'section' => 'A',
                'internship_status' => 'ongoing',
                'company' => ['id' => $company->id, 'name' => 'Acme Corp'],
                'supervisor' => ['id' => $supervisor->id, 'name' => 'Sup Ervisor'],
                'rendered_hours' => 12.25,
                'required_hours' => 200,
                'remaining_hours' => 187.75,
                'progress_percent' => 6,
                'can_edit_attendance' => true,
                'can_edit_required_hours' => true,
            ]]);
    }

    public static function percentCases(): array
    {
        return [
            'not set' => [null, 10, null, null],
            'zero required' => [0, 10, 0, 0],
            'capped at 100' => [8, 16, 100, 0],
            'rounded' => [3, 1, 33, 2],
            'no hours yet' => [486, null, 0, 486],
        ];
    }

    #[DataProvider('percentCases')]
    public function test_progress_matches_the_website_progress_bar(?int $required, ?float $hours, ?int $percent, int|float|null $remaining): void
    {
        $admin = $this->user(4);
        $student = $this->student(null, ['required_hours' => $required]);

        if ($hours !== null) {
            $this->record($student, ['rendered_hours' => $hours]);
        }

        $this->api('GET', '/api/v1/progress', $admin)->assertOk()
            ->assertJsonPath('data.0.progress_percent', $percent)
            ->assertJsonPath('data.0.remaining_hours', $remaining)
            ->assertJsonPath('data.0.rendered_hours', fn ($value) => (float) $value === ($hours ?? 0.0));
    }

    public function test_lists_match_the_website_lists_for_each_role(): void
    {
        $supervisor = $this->user(3);
        $students = collect();

        foreach (range(1, 4) as $i) {
            $students->push($this->student($i % 2 ? $supervisor : null, ['created_at' => now()->subMinutes(10 - $i)]));
        }

        foreach ([$supervisor, $this->user(2), $this->user(4)] as $user) {
            $webIds = [];
            $this->web('GET', '/attendance-monitoring', $user)->assertInertia(function (Assert $page) use (&$webIds) {
                $webIds = collect($page->toArray()['props']['students'])->pluck('id')->all();
            });
            $webProgressIds = [];
            $this->web('GET', '/progress-monitoring', $user)->assertInertia(function (Assert $page) use (&$webProgressIds) {
                $webProgressIds = collect($page->toArray()['props']['students'])->pluck('id')->all();
            });

            $apiIds = collect($this->api('GET', '/api/v1/monitoring/students', $user)->assertOk()->json('data'))->pluck('id')->all();
            $apiProgressIds = collect($this->api('GET', '/api/v1/progress', $user)->assertOk()->json('data'))->pluck('id')->all();

            $this->assertNotEmpty($apiIds);
            $this->assertSame($webIds, $apiIds);
            $this->assertSame($webProgressIds, $apiProgressIds);
        }
    }

    public function test_search_filters_by_name_and_treats_wildcards_literally(): void
    {
        $admin = $this->user(4);
        $maria = $this->student(null, [], ['name' => 'Maria Santos']);
        $this->student(null, [], ['name' => 'Jose Rizal']);
        $percent = $this->student(null, [], ['name' => 'Ana 100% Cruz']);

        $this->api('GET', '/api/v1/monitoring/students', $admin, ['search' => 'maria'])
            ->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $maria->id);
        $this->api('GET', '/api/v1/progress', $admin, ['search' => '  SANTOS '])
            ->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $maria->id);
        $this->api('GET', '/api/v1/monitoring/students', $admin, ['search' => '%'])
            ->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $percent->id);
        $this->api('GET', '/api/v1/monitoring/students', $admin, ['search' => '_'])
            ->assertJsonCount(0, 'data');
        $this->api('GET', '/api/v1/monitoring/students', $admin, ['search' => ''])
            ->assertJsonCount(3, 'data');
    }

    public function test_lists_are_page_paginated(): void
    {
        $admin = $this->user(4);

        foreach (range(1, 5) as $i) {
            $this->student(null, ['created_at' => now()->subMinutes($i)]);
        }

        $first = $this->api('GET', '/api/v1/monitoring/students', $admin, ['per_page' => 2])->assertOk()
            ->assertJsonPath('meta', ['current_page' => 1, 'last_page' => 3, 'per_page' => 2, 'total' => 5, 'has_more' => true]);
        $last = $this->api('GET', '/api/v1/monitoring/students', $admin, ['per_page' => 2, 'page' => 3])->assertOk()
            ->assertJsonPath('meta.has_more', false)
            ->assertJsonCount(1, 'data');
        $this->api('GET', '/api/v1/monitoring/students', $admin, ['per_page' => 2, 'page' => 9])->assertOk()
            ->assertJsonCount(0, 'data');

        $this->assertNotSame($first->json('data.0.id'), $last->json('data.0.id'));
    }

    public static function badListQueries(): array
    {
        return [
            'per_page 0' => [['per_page' => 0], 'per_page'],
            'per_page 51' => [['per_page' => 51], 'per_page'],
            'per_page text' => [['per_page' => 'abc'], 'per_page'],
            'page 0' => [['page' => 0], 'page'],
            'page text' => [['page' => 'x'], 'page'],
            'search array' => [['search' => ['a']], 'search'],
            'search too long' => [['search' => str_repeat('a', 256)], 'search'],
        ];
    }

    #[DataProvider('badListQueries')]
    public function test_bad_list_queries_are_422(array $query, string $key): void
    {
        $admin = $this->user(4);

        foreach (['/api/v1/monitoring/students', '/api/v1/progress'] as $uri) {
            $this->api('GET', $uri, $admin, $query)->assertStatus(422)->assertJsonValidationErrors($key);
        }
    }

    // -------------------------------------------------------------- log

    public function test_log_shows_every_status_evidence_and_markers_newest_first(): void
    {
        $supervisor = $this->user(3);
        $student = $this->student($supervisor);
        $selfRow = $this->record($student, [
            'recorded_by' => $student->user_id,
            'time_in_status' => 'approved',
            'time_in_photo_path' => "attendance-photos/{$student->id}/in.jpg",
            'time_in_latitude' => 10.6765,
            'time_in_longitude' => 122.9509,
            'time_in_accuracy' => 12.5,
            'time_in_mocked' => true,
            'time_out_status' => 'rejected',
            'time_out_rejection_reason' => 'Blurry',
            'rendered_hours' => null,
        ]);
        $staffRow = $this->record($student, ['recorded_by' => $supervisor->id]);
        $pending = $this->record($student, ['recorded_by' => $student->user_id, 'time_in_status' => 'pending', 'time_out_status' => 'pending', 'is_emergency' => true, 'note' => 'Sick']);
        $legacy = $this->record($student, ['recorded_by' => null]);

        $data = $this->api('GET', "/api/v1/monitoring/students/{$student->id}/attendance", $supervisor)->assertOk()->json('data');

        $this->assertSame([$selfRow->id, $staffRow->id, $pending->id, $legacy->id], array_column($data, 'id'));
        $this->assertSame(['self', 'staff', 'self', 'staff'], array_column($data, 'recorded_by'));
        $this->assertSame([true, true, true, true], array_column($data, 'can_edit'));
        $this->assertSame([true, true, true, true], array_column($data, 'can_delete'));

        $this->assertSame('rejected', $data[0]['time_out_status']);
        $this->assertSame('Blurry', $data[0]['time_out_rejection_reason']);
        $this->assertTrue($data[0]['time_in_mocked']);
        $this->assertSame(12.5, $data[0]['time_in_accuracy']);
        $this->assertStringContainsString("/api/v1/attendance/{$selfRow->id}/photo/time_in?v=", $data[0]['time_in_photo_url']);
        $this->assertNull($data[0]['time_out_photo_url']);
        $this->assertSame('pending', $data[2]['time_in_status']);
        $this->assertTrue($data[2]['is_emergency']);
        $this->assertSame('Sick', $data[2]['note']);

        // The photo URL works for the supervisor and for a coordinator.
        $this->api('GET', $data[0]['time_in_photo_url'], $supervisor)->assertNotFound(); // no file stored
        Storage::disk('local')->put("attendance-photos/{$student->id}/in.jpg", 'x');
        $this->api('GET', $data[0]['time_in_photo_url'], $supervisor)->assertOk();
        $this->api('GET', $data[0]['time_in_photo_url'], $this->user(2))->assertOk();
    }

    public function test_log_is_cursor_paginated_and_rejects_bad_cursors(): void
    {
        $admin = $this->user(4);
        $student = $this->student(null);
        $ids = collect(range(1, 3))->map(fn () => $this->record($student)->id)->all();

        $first = $this->api('GET', "/api/v1/monitoring/students/{$student->id}/attendance", $admin, ['per_page' => 2])->assertOk()
            ->assertJsonPath('meta.has_more', true);
        $second = $this->api('GET', "/api/v1/monitoring/students/{$student->id}/attendance", $admin, ['per_page' => 2, 'cursor' => $first->json('meta.next_cursor')])->assertOk()
            ->assertJsonPath('meta.has_more', false);

        $this->assertSame($ids, [...array_column($first->json('data'), 'id'), ...array_column($second->json('data'), 'id')]);

        $this->api('GET', "/api/v1/monitoring/students/{$student->id}/attendance", $admin, ['cursor' => 'garbage'])
            ->assertStatus(422)->assertJsonValidationErrors('cursor');
        $this->api('GET', "/api/v1/monitoring/students/{$student->id}/attendance", $admin, ['per_page' => 51])
            ->assertStatus(422)->assertJsonValidationErrors('per_page');
    }

    // ---------------------------------------------------------- create

    public function test_supervisor_adds_an_entry_for_their_student(): void
    {
        $supervisor = $this->user(3);
        $student = $this->student($supervisor, ['required_hours' => 100]);

        $response = $this->api('POST', "/api/v1/monitoring/students/{$student->id}/attendance", $supervisor, [
            'date' => '2026-09-01', 'time_in' => '08:00', 'time_out' => '17:30',
        ])->assertCreated()
            ->assertJsonPath('message', 'Attendance entry added.')
            ->assertJsonPath('attendance.date', '2026-09-01')
            ->assertJsonPath('attendance.time_in', '2026-09-01T08:00:00+08:00')
            ->assertJsonPath('attendance.time_out', '2026-09-01T17:30:00+08:00')
            ->assertJsonPath('attendance.time_in_status', 'approved')
            ->assertJsonPath('attendance.time_out_status', 'approved')
            ->assertJsonPath('attendance.rendered_hours', 9.5)
            ->assertJsonPath('attendance.recorded_by', 'staff')
            ->assertJsonPath('attendance.can_edit', true)
            ->assertJsonPath('student.rendered_hours', 9.5)
            ->assertJsonPath('student.remaining_hours', 90.5)
            ->assertJsonPath('student.progress_percent', 10);

        $row = Attendance::findOrFail($response->json('attendance.id'));
        $this->assertSame($supervisor->id, (int) $row->recorded_by);
        $this->assertSame(0, Notification::count());
    }

    public function test_an_entry_may_have_one_leg_or_none(): void
    {
        $admin = $this->user(4);
        $student = $this->student(null);
        $uri = "/api/v1/monitoring/students/{$student->id}/attendance";

        $this->api('POST', $uri, $admin, ['date' => '2026-09-01', 'time_in' => '08:00'])->assertCreated()
            ->assertJsonPath('attendance.time_out', null)
            ->assertJsonPath('attendance.time_out_status', null)
            ->assertJsonPath('attendance.rendered_hours', null);
        $this->api('POST', $uri, $admin, ['date' => '2026-09-02', 'time_in' => '', 'time_out' => '17:00'])->assertCreated()
            ->assertJsonPath('attendance.time_in', null)
            ->assertJsonPath('attendance.time_out_status', 'approved');
        $this->api('POST', $uri, $admin, ['date' => '2026-09-03'])->assertCreated()
            ->assertJsonPath('attendance.time_in_status', null);
    }

    public static function badEntries(): array
    {
        return [
            'no date' => [['time_in' => '08:00'], 'date'],
            'impossible date' => [['date' => '2026-02-30'], 'date'],
            'relative date' => [['date' => 'next monday'], 'date'],
            'other date format' => [['date' => '09/01/2026'], 'date'],
            'date with time' => [['date' => '2026-09-01 08:00'], 'date'],
            'year 0999' => [['date' => '0999-12-31', 'time_in' => '08:00', 'time_out' => '09:00'], 'date'],
            'year 0000' => [['date' => '0000-01-01'], 'date'],
            'before 2000' => [['date' => '1999-12-31'], 'date'],
            'year 9999' => [['date' => '9999-12-31'], 'date'],
            'after 2099' => [['date' => '2100-01-01'], 'date'],
            'date array' => [['date' => ['2026-09-01']], 'date'],
            'date number' => [['date' => 20260901], 'date'],
            'time_in seconds' => [['date' => '2026-09-01', 'time_in' => '08:00:00'], 'time_in'],
            'time_in 25h' => [['date' => '2026-09-01', 'time_in' => '25:00'], 'time_in'],
            'time_in text' => [['date' => '2026-09-01', 'time_in' => 'eight'], 'time_in'],
            'time_in bool' => [['date' => '2026-09-01', 'time_in' => true], 'time_in'],
            'time_out before time_in' => [['date' => '2026-09-01', 'time_in' => '17:00', 'time_out' => '08:00'], 'time_out'],
            'time_out equal time_in' => [['date' => '2026-09-01', 'time_in' => '08:00', 'time_out' => '08:00'], 'time_out'],
            'time_out array' => [['date' => '2026-09-01', 'time_out' => ['17:00']], 'time_out'],
        ];
    }

    #[DataProvider('badEntries')]
    public function test_bad_entries_are_422_and_store_nothing(array $body, string $key): void
    {
        $supervisor = $this->user(3);
        $student = $this->student($supervisor);
        $record = $this->record($student);

        $this->api('POST', "/api/v1/monitoring/students/{$student->id}/attendance", $supervisor, $body)
            ->assertStatus(422)->assertJsonValidationErrors($key);
        $this->api('PATCH', "/api/v1/monitoring/attendance/{$record->id}", $supervisor, $body)
            ->assertStatus(422)->assertJsonValidationErrors($key);

        $this->assertUntouched($student, $record);
    }

    public function test_invalid_utf8_is_422_on_every_write(): void
    {
        $supervisor = $this->user(3);
        $student = $this->student($supervisor);
        $record = $this->record($student);

        foreach ([
            ['POST', "/api/v1/monitoring/students/{$student->id}/attendance", 'date'],
            ['PATCH', "/api/v1/monitoring/attendance/{$record->id}", 'time_in'],
            ['PATCH', "/api/v1/monitoring/students/{$student->id}/required-hours", 'required_hours'],
        ] as [$method, $uri, $field]) {
            $this->api($method, $uri, $supervisor, ['date' => '2026-09-01', $field => "\xB1\x31"], json: false)
                ->assertStatus(422)->assertJsonValidationErrors($field);
        }

        $this->api('GET', '/api/v1/monitoring/students', $supervisor, ['search' => "\xB1"])
            ->assertStatus(422)->assertJsonValidationErrors('search');

        $this->assertUntouched($student, $record);
    }

    public function test_the_date_window_edges_are_accepted(): void
    {
        $supervisor = $this->user(3);
        $student = $this->student($supervisor);

        foreach (['2000-01-01', '2099-12-31'] as $date) {
            $this->api('POST', "/api/v1/monitoring/students/{$student->id}/attendance", $supervisor, ['date' => $date, 'time_in' => '08:00', 'time_out' => '09:00'])
                ->assertCreated()
                ->assertJsonPath('attendance.time_in', "{$date}T08:00:00+08:00");
        }
    }

    public function test_a_duplicate_date_is_422(): void
    {
        $supervisor = $this->user(3);
        $student = $this->student($supervisor);
        $record = $this->record($student);
        $other = $this->record($student);

        $this->api('POST', "/api/v1/monitoring/students/{$student->id}/attendance", $supervisor, ['date' => $record->date->format('Y-m-d')])
            ->assertStatus(422)->assertJsonPath('errors.date.0', 'The date has already been taken.');
        $this->api('PATCH', "/api/v1/monitoring/attendance/{$other->id}", $supervisor, ['date' => $record->date->format('Y-m-d')])
            ->assertStatus(422)->assertJsonPath('errors.date.0', 'The date has already been taken.');

        // Another student's same date is fine.
        $this->api('POST', "/api/v1/monitoring/students/{$this->student($supervisor)->id}/attendance", $supervisor, ['date' => $record->date->format('Y-m-d')])
            ->assertCreated();
    }

    public function test_a_racing_duplicate_create_is_a_date_422_not_a_500(): void
    {
        $supervisor = $this->user(3);
        $student = $this->student($supervisor);

        // The other request's insert lands right after this one's unique check.
        $landed = false;
        DB::listen(function ($query) use (&$landed, $student) {
            if ($landed || ! str_contains($query->sql, 'from `attendances`')) {
                return;
            }

            $landed = true;
            Attendance::factory()->create(['student_id' => $student->id, 'date' => '2026-09-01']);
        });

        $this->api('POST', "/api/v1/monitoring/students/{$student->id}/attendance", $supervisor, ['date' => '2026-09-01', 'time_in' => '08:00'])
            ->assertStatus(422)
            ->assertJsonPath('errors.date.0', 'The date has already been taken.');

        $this->assertTrue($landed);
        $this->assertSame(1, Attendance::count());
    }

    // ---------------------------------------------------------- update

    public function test_editing_times_recomputes_hours_and_keeps_evidence(): void
    {
        $supervisor = $this->user(3);
        $student = $this->student($supervisor);
        $in = "attendance-photos/{$student->id}/in.jpg";
        $out = "attendance-photos/{$student->id}/out.jpg";
        Storage::disk('local')->put($in, 'x');
        Storage::disk('local')->put($out, 'y');
        $record = $this->record($student, [
            'recorded_by' => $student->user_id,
            'time_in_status' => 'pending',
            'time_in_photo_path' => $in,
            'time_in_latitude' => 10.5,
            'time_out_photo_path' => $out,
            'time_out_mocked' => true,
        ]);

        $this->api('PATCH', "/api/v1/monitoring/attendance/{$record->id}", $supervisor, [
            'date' => $record->date->format('Y-m-d'), 'time_in' => '07:30', 'time_out' => '16:00',
        ])->assertOk()
            ->assertJsonPath('message', 'Attendance entry updated.')
            ->assertJsonPath('attendance.rendered_hours', 8.5)
            ->assertJsonPath('attendance.time_in_status', 'approved')
            ->assertJsonPath('attendance.recorded_by', 'staff')
            ->assertJsonPath('attendance.time_in_latitude', 10.5)
            ->assertJsonPath('attendance.time_out_mocked', true)
            ->assertJsonPath('student.rendered_hours', 8.5);

        Storage::disk('local')->assertExists([$in, $out]);
    }

    public function test_clearing_a_leg_deletes_its_evidence_and_hours(): void
    {
        $supervisor = $this->user(3);
        $student = $this->student($supervisor);
        $in = "attendance-photos/{$student->id}/in.jpg";
        $out = "attendance-photos/{$student->id}/out.jpg";
        Storage::disk('local')->put($in, 'x');
        Storage::disk('local')->put($out, 'y');
        $record = $this->record($student, [
            'time_in_photo_path' => $in,
            'time_out_photo_path' => $out,
            'time_out_latitude' => 10.5,
            'time_out_longitude' => 122.5,
            'time_out_accuracy' => 5,
            'time_out_mocked' => false,
        ]);

        $this->api('PATCH', "/api/v1/monitoring/attendance/{$record->id}", $supervisor, [
            'date' => $record->date->format('Y-m-d'), 'time_in' => '08:00', 'time_out' => null,
        ])->assertOk()
            ->assertJsonPath('attendance.time_out', null)
            ->assertJsonPath('attendance.time_out_status', null)
            ->assertJsonPath('attendance.time_out_photo_url', null)
            ->assertJsonPath('attendance.time_out_latitude', null)
            ->assertJsonPath('attendance.time_out_mocked', null)
            ->assertJsonPath('attendance.rendered_hours', null)
            ->assertJsonPath('student.rendered_hours', 0);

        Storage::disk('local')->assertMissing($out);
        Storage::disk('local')->assertExists($in);
    }

    public function test_an_edit_racing_a_student_submission_leaves_no_orphan_photo(): void
    {
        $supervisor = $this->user(3);
        $student = $this->student($supervisor);
        $record = $this->record($student, ['time_out' => null, 'time_out_status' => null, 'rendered_hours' => null]);
        $late = "attendance-photos/{$student->id}/late.jpg";

        // The student's time-out (with its photo) commits after this request
        // bound the row but before the service's locked re-read.
        $landed = false;
        DB::listen(function ($query) use (&$landed, $record, $late) {
            if ($landed || ! str_contains($query->sql, 'from `attendances`')) {
                return;
            }

            $landed = true;
            Storage::disk('local')->put($late, 'x');
            DB::table('attendances')->where('id', $record->id)->update([
                'time_out' => '17:00:00', 'time_out_status' => 'pending', 'time_out_photo_path' => $late,
            ]);
        });

        $this->api('PATCH', "/api/v1/monitoring/attendance/{$record->id}", $supervisor, [
            'date' => $record->date->format('Y-m-d'), 'time_in' => '08:00', 'time_out' => '',
        ])->assertOk();

        $this->assertTrue($landed);
        $this->assertNull($record->fresh()->time_out_photo_path);
        Storage::disk('local')->assertMissing($late);
    }

    public static function writeMethods(): array
    {
        return ['edit' => ['PATCH'], 'delete' => ['DELETE']];
    }

    #[DataProvider('writeMethods')]
    public function test_an_edit_or_delete_racing_a_delete_is_404_and_recreates_nothing(string $method): void
    {
        $supervisor = $this->user(3);
        $student = $this->student($supervisor);
        $record = $this->record($student);

        // Another request deletes the entry right after this one bound it.
        $landed = false;
        DB::listen(function ($query) use (&$landed, $record) {
            if ($landed || ! str_contains($query->sql, 'from `attendances`')) {
                return;
            }

            $landed = true;
            DB::table('attendances')->where('id', $record->id)->delete();
        });

        $this->api($method, "/api/v1/monitoring/attendance/{$record->id}", $supervisor, [
            'date' => $record->date->format('Y-m-d'), 'time_in' => '09:00',
        ])->assertNotFound();

        $this->assertTrue($landed);
        $this->assertSame(0, Attendance::count());
    }

    // ---------------------------------------------------------- delete

    public function test_deleting_an_entry_removes_it_and_its_photos(): void
    {
        $supervisor = $this->user(3);
        $student = $this->student($supervisor);
        $in = "attendance-photos/{$student->id}/in.jpg";
        Storage::disk('local')->put($in, 'x');
        $record = $this->record($student, ['time_in_photo_path' => $in, 'rendered_hours' => 8]);
        $keep = $this->record($student, ['rendered_hours' => 3]);

        $this->api('DELETE', "/api/v1/monitoring/attendance/{$record->id}", $supervisor)->assertOk()
            ->assertExactJson([
                'message' => 'Attendance entry deleted.',
                'student' => $this->api('GET', "/api/v1/monitoring/students/{$student->id}", $supervisor)->json('data'),
            ])
            ->assertJsonPath('student.rendered_hours', 3);

        $this->assertNull($record->fresh());
        $this->assertNotNull($keep->fresh());
        Storage::disk('local')->assertMissing($in);

        $this->api('DELETE', "/api/v1/monitoring/attendance/{$record->id}", $supervisor)->assertNotFound();
    }

    // -------------------------------------------------- required hours

    public static function requiredHoursCases(): array
    {
        return [
            'number' => [300, 300],
            'numeric string' => ['250', 250],
            'zero means not set' => [0, null],
            'zero string' => ['0', null],
            'empty' => ['', null],
            'null' => [null, null],
            'column max' => [4294967295, 4294967295],
        ];
    }

    #[DataProvider('requiredHoursCases')]
    public function test_required_hours_follow_the_website_rules(mixed $value, ?int $stored): void
    {
        $supervisor = $this->user(3);
        $student = $this->student($supervisor);

        $this->api('PATCH', "/api/v1/monitoring/students/{$student->id}/required-hours", $supervisor, ['required_hours' => $value])
            ->assertOk()
            ->assertJsonPath('message', 'Required hours updated.')
            ->assertJsonPath('student.required_hours', $stored);

        $this->assertSame($stored, $student->fresh()->required_hours);
    }

    public static function badRequiredHours(): array
    {
        return [
            'negative' => [-1],
            'fraction' => [1.5],
            'text' => ['abc'],
            'bool' => [true],
            'array' => [[100]],
            'over the column' => [4294967296],
            'huge' => ['99999999999999999999'],
        ];
    }

    #[DataProvider('badRequiredHours')]
    public function test_bad_required_hours_are_422(mixed $value): void
    {
        $supervisor = $this->user(3);
        $student = $this->student($supervisor);

        $this->api('PATCH', "/api/v1/monitoring/students/{$student->id}/required-hours", $supervisor, ['required_hours' => $value])
            ->assertStatus(422)->assertJsonValidationErrors('required_hours');

        $this->assertSame(486, $student->fresh()->required_hours);
    }

    // ------------------------------------------------- website parity

    public function test_api_and_website_writes_store_identical_rows(): void
    {
        $supervisor = $this->user(3);
        $webStudent = $this->student($supervisor);
        $apiStudent = $this->student($supervisor);

        $comparable = fn (Student $student) => Attendance::where('student_id', $student->id)->get()
            ->map(fn (Attendance $a) => collect($a->getAttributes())->except(['id', 'student_id', 'created_at', 'updated_at'])->all())
            ->all();

        $this->web('POST', "/attendance-monitoring/{$webStudent->id}/attendances", $supervisor, ['date' => '2026-09-01', 'time_in' => '08:00', 'time_out' => '17:15'])
            ->assertRedirect()->assertSessionHasNoErrors();
        $this->api('POST', "/api/v1/monitoring/students/{$apiStudent->id}/attendance", $supervisor, ['date' => '2026-09-01', 'time_in' => '08:00', 'time_out' => '17:15'])
            ->assertCreated();
        $this->assertSame($comparable($webStudent), $comparable($apiStudent));

        $webRow = Attendance::where('student_id', $webStudent->id)->first();
        $apiRow = Attendance::where('student_id', $apiStudent->id)->first();
        $this->web('PATCH', "/attendance-monitoring/attendances/{$webRow->id}", $supervisor, ['date' => '2026-09-02', 'time_in' => '09:00', 'time_out' => ''])
            ->assertRedirect()->assertSessionHasNoErrors();
        $this->api('PATCH', "/api/v1/monitoring/attendance/{$apiRow->id}", $supervisor, ['date' => '2026-09-02', 'time_in' => '09:00', 'time_out' => ''])
            ->assertOk();
        $this->assertSame($comparable($webStudent), $comparable($apiStudent));

        $this->web('PATCH', "/attendance-monitoring/{$webStudent->id}/required-hours", $supervisor, ['required_hours' => '0'])->assertRedirect();
        $this->api('PATCH', "/api/v1/monitoring/students/{$apiStudent->id}/required-hours", $supervisor, ['required_hours' => '0'])->assertOk();
        $this->assertNull($webStudent->fresh()->required_hours);
        $this->assertNull($apiStudent->fresh()->required_hours);

        // The shared rules refuse the same input on the website.
        $this->web('POST', "/attendance-monitoring/{$webStudent->id}/attendances", $supervisor, ['date' => 'next monday'])
            ->assertSessionHasErrors('date');
        $this->web('POST', "/attendance-monitoring/{$webStudent->id}/attendances", $supervisor, ['date' => '0999-12-31', 'time_in' => '08:00', 'time_out' => '09:00'])
            ->assertSessionHasErrors('date');
        $this->web('PATCH', "/attendance-monitoring/{$webStudent->id}/required-hours", $supervisor, ['required_hours' => '4294967296'])
            ->assertSessionHasErrors('required_hours');

        $this->assertSame(0, Notification::count());
    }
}
