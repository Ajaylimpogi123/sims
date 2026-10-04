<?php

namespace Tests\Feature\Api\V1;

use App\Models\Attendance;
use App\Models\Company;
use App\Models\Notification;
use App\Models\Student;
use App\Models\User;
use App\Services\AttendanceService;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Module 9: Pending Approvals through /api/v1 for Supervisors (own
 * students) and Administrators (all), sharing the website's list query,
 * AttendancePolicy::review and AttendanceService decisions.
 */
class ApprovalApiTest extends TestCase
{
    use RefreshDatabase;

    private const ACTIONS = [
        'time_in approve' => ['time-in', 'approve', 'time_in', 'approved', 'Time-in approved.'],
        'time_in reject' => ['time-in', 'reject', 'time_in', 'rejected', 'Time-in rejected.'],
        'time_out approve' => ['time-out', 'approve', 'time_out', 'approved', 'Time-out approved.'],
        'time_out reject' => ['time-out', 'reject', 'time_out', 'rejected', 'Time-out rejected.'],
    ];

    private int $day = 0;

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

    private function user(int $roleId): User
    {
        return User::factory()->create(['role_id' => $roleId, 'status' => 'active']);
    }

    private function student(?User $supervisor, array $overrides = []): Student
    {
        return Student::factory()->create(array_merge([
            'user_id' => $this->user(1)->id,
            'supervisor_id' => $supervisor?->id,
        ], $overrides));
    }

    /**
     * Both legs pending by default, each call on an earlier day.
     */
    private function record(Student $student, array $attributes = []): Attendance
    {
        return Attendance::factory()->create(array_merge([
            'student_id' => $student->id,
            'date' => today()->subDays($this->day++)->toDateString(),
            'time_in' => '08:00:00',
            'time_in_status' => 'pending',
            'time_out' => '17:00:00',
            'time_out_status' => 'pending',
            'rendered_hours' => null,
        ], $attributes));
    }

    private function api(string $method, string $uri, ?User $user, array $data = []): TestResponse
    {
        $this->app['auth']->forgetGuards();
        $this->defaultHeaders = [];

        $headers = ['Accept' => 'application/json'];

        if ($user !== null) {
            $headers['Authorization'] = 'Bearer '.$user->createToken('test')->plainTextToken;
        }

        return $method === 'GET'
            ? $this->withHeaders($headers)->get($uri.($data ? '?'.http_build_query($data) : ''))
            : $this->withHeaders($headers)->post($uri, $data);
    }

    private function decisionUrl(Attendance $record, string $leg, string $action): string
    {
        return "/api/v1/attendance/{$record->id}/{$leg}/{$action}";
    }

    /**
     * @return list<string>
     */
    private function allUrls(Attendance $record): array
    {
        return [
            'GET /api/v1/approvals',
            'GET /api/v1/approvals/count',
            "GET /api/v1/approvals/{$record->id}",
            'POST '.$this->decisionUrl($record, 'time-in', 'approve'),
            'POST '.$this->decisionUrl($record, 'time-in', 'reject'),
            'POST '.$this->decisionUrl($record, 'time-out', 'approve'),
            'POST '.$this->decisionUrl($record, 'time-out', 'reject'),
        ];
    }

    // ---------------------------------------------------------- access

    public function test_every_endpoint_requires_a_token(): void
    {
        $record = $this->record($this->student($this->user(3)));

        foreach ($this->allUrls($record) as $line) {
            [$method, $uri] = explode(' ', $line);
            $this->api($method, $uri, null)->assertUnauthorized();
        }
    }

    public static function forbiddenRoles(): array
    {
        return ['student' => [1], 'coordinator' => [2]];
    }

    #[DataProvider('forbiddenRoles')]
    public function test_students_and_coordinators_get_403_everywhere(int $roleId): void
    {
        $student = $this->student($this->user(3));
        $record = $this->record($student);
        $caller = $roleId === 1 ? $student->user : $this->user($roleId);

        foreach ($this->allUrls($record) as $line) {
            [$method, $uri] = explode(' ', $line);
            $this->api($method, $uri, $caller)
                ->assertForbidden()
                ->assertExactJson(['message' => 'Unauthorized access']);
        }

        $this->assertSame('pending', $record->fresh()->time_in_status);
        $this->assertSame('pending', $record->fresh()->time_out_status);
        $this->assertSame(0, Notification::count());
    }

    // ------------------------------------------------------------ list

    public function test_two_supervisors_never_see_or_decide_each_others_students(): void
    {
        $alice = $this->user(3);
        $bob = $this->user(3);
        $aliceRecord = $this->record($this->student($alice));
        $bobRecord = $this->record($this->student($bob));
        $this->record($this->student(null));

        $this->api('GET', '/api/v1/approvals', $alice)->assertOk()
            ->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $aliceRecord->id);
        $this->api('GET', '/api/v1/approvals/count', $alice)->assertExactJson(['count' => 1]);
        $this->api('GET', '/api/v1/approvals', $bob)->assertOk()
            ->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $bobRecord->id);

        $this->api('GET', "/api/v1/approvals/{$bobRecord->id}", $alice)
            ->assertNotFound()->assertExactJson(['message' => 'Not found.']);

        foreach (self::ACTIONS as [$leg, $action]) {
            $this->api('POST', $this->decisionUrl($bobRecord, $leg, $action), $alice)
                ->assertNotFound()->assertExactJson(['message' => 'Not found.']);
        }

        $this->assertSame('pending', $bobRecord->fresh()->time_in_status);
        $this->assertSame('pending', $bobRecord->fresh()->time_out_status);
        $this->assertSame(0, Notification::count());
    }

    public function test_admin_sees_every_pending_record_and_nothing_decided(): void
    {
        $admin = $this->user(4);
        $a = $this->record($this->student($this->user(3)));
        $b = $this->record($this->student(null), ['time_out' => null, 'time_out_status' => null]);
        $c = $this->record($this->student($this->user(3)), ['time_in_status' => 'approved']);
        $this->record($this->student($this->user(3)), ['time_in_status' => 'approved', 'time_out_status' => 'approved']);
        $this->record($this->student($this->user(3)), ['time_in_status' => 'rejected', 'time_out_status' => 'rejected']);

        $ids = collect($this->api('GET', '/api/v1/approvals', $admin)->assertOk()->json('data'))->pluck('id')->all();

        // Newest date first: record() goes one day back each call.
        $this->assertSame([$a->id, $b->id, $c->id], $ids);
        $this->api('GET', '/api/v1/approvals/count', $admin)->assertExactJson(['count' => 3]);
    }

    public function test_list_matches_the_website_list_for_each_reviewer(): void
    {
        $alice = $this->user(3);
        $admin = $this->user(4);
        $this->record($this->student($alice));
        $this->record($this->student($alice), ['time_in_status' => 'approved']);
        $this->record($this->student($this->user(3)), ['time_out' => null, 'time_out_status' => null]);
        $this->record($this->student($alice), ['time_in_status' => 'approved', 'time_out_status' => 'approved']);

        foreach ([$alice, $admin] as $reviewer) {
            $this->app['auth']->forgetGuards();
            $web = $this->actingAs($reviewer)->get('/attendance-approvals')->assertOk()
                ->viewData('page')['props']['attendances'];
            $api = $this->api('GET', '/api/v1/approvals', $reviewer)->assertOk()->json('data');

            $this->assertEqualsCanonicalizing(array_column($web, 'id'), array_column($api, 'id'));
            $this->assertCount(count($web), $api);
        }
    }

    public function test_items_carry_student_legs_evidence_and_review_flags(): void
    {
        $supervisor = $this->user(3);
        $company = Company::factory()->create(['company_name' => 'Acme Corp']);
        $student = $this->student($supervisor, ['company_id' => $company->id, 'student_number' => '2023-0001']);
        $path = "attendance-photos/{$student->id}/in.jpg";
        Storage::disk('local')->put($path, 'jpeg');
        $record = $this->record($student, [
            'time_in_status' => 'pending',
            'time_in_latitude' => 10.6765432,
            'time_in_longitude' => 122.9509876,
            'time_in_accuracy' => 12.5,
            'time_in_mocked' => true,
            'time_in_photo_path' => $path,
            'time_out' => '15:00:00',
            'time_out_status' => 'pending',
            'time_out_mocked' => false,
            'is_emergency' => true,
            'note' => 'Family emergency',
        ]);

        $item = $this->api('GET', '/api/v1/approvals', $supervisor)->assertOk()->json('data.0');

        $this->assertSame($record->id, $item['id']);
        $this->assertSame('2026-10-03', $item['date']);
        $this->assertSame('2026-10-03T08:00:00+08:00', $item['time_in']);
        $this->assertSame('2026-10-03T15:00:00+08:00', $item['time_out']);
        $this->assertSame(10.6765432, $item['time_in_latitude']);
        $this->assertSame(122.9509876, $item['time_in_longitude']);
        $this->assertSame(12.5, $item['time_in_accuracy']);
        $this->assertTrue($item['time_in_mocked']);
        $this->assertFalse($item['time_out_mocked']);
        $this->assertTrue($item['is_emergency']);
        $this->assertSame('Family emergency', $item['note']);
        $this->assertNull($item['time_out_photo_url']);
        $this->assertSame([
            'id' => $student->id,
            'name' => $student->user->name,
            'student_number' => '2023-0001',
            'company' => ['id' => $company->id, 'name' => 'Acme Corp'],
        ], $item['student']);
        $this->assertSame(['time_in', 'time_out'], $item['pending_legs']);
        $this->assertSame([
            'time_in' => ['can_approve' => true, 'can_reject' => true],
            'time_out' => ['can_approve' => true, 'can_reject' => true],
        ], $item['review']);

        // The photo URL works for the reviewer.
        $this->api('GET', $item['time_in_photo_url'], $supervisor)->assertOk();
    }

    public function test_only_pending_legs_are_listed_and_reviewable(): void
    {
        $supervisor = $this->user(3);
        $student = $this->student($supervisor, ['company_id' => null]);
        $this->record($student, ['time_in_status' => 'approved']);

        $item = $this->api('GET', '/api/v1/approvals', $supervisor)->json('data.0');

        $this->assertSame(['time_out'], $item['pending_legs']);
        $this->assertNull($item['student']['company']);
        $this->assertSame([
            'time_in' => ['can_approve' => false, 'can_reject' => false],
            'time_out' => ['can_approve' => true, 'can_reject' => true],
        ], $item['review']);
    }

    public function test_list_is_cursor_paginated(): void
    {
        $admin = $this->user(4);
        $student = $this->student(null);
        $ids = collect(range(1, 5))->map(fn () => $this->record($student)->id)->all();

        $first = $this->api('GET', '/api/v1/approvals', $admin, ['per_page' => 2])->assertOk()
            ->assertJsonPath('meta.per_page', 2)->assertJsonPath('meta.has_more', true);
        $seen = array_column($first->json('data'), 'id');
        $cursor = $first->json('meta.next_cursor');

        while ($cursor !== null) {
            $page = $this->api('GET', '/api/v1/approvals', $admin, ['per_page' => 2, 'cursor' => $cursor])->assertOk();
            $seen = [...$seen, ...array_column($page->json('data'), 'id')];
            $cursor = $page->json('meta.next_cursor');
        }

        $this->assertSame($ids, $seen);
    }

    public static function badQueries(): array
    {
        return [
            'per_page 0' => [['per_page' => 0], 'per_page'],
            'per_page 51' => [['per_page' => 51], 'per_page'],
            'tampered cursor' => [['cursor' => 'abc'], 'cursor'],
        ];
    }

    #[DataProvider('badQueries')]
    public function test_bad_list_queries_are_422(array $query, string $key): void
    {
        $this->api('GET', '/api/v1/approvals', $this->user(4), $query)
            ->assertStatus(422)->assertJsonValidationErrors($key);
    }

    public function test_show_returns_a_reviewable_record_even_after_a_decision(): void
    {
        $supervisor = $this->user(3);
        $record = $this->record($this->student($supervisor), ['time_in_status' => 'approved', 'time_out_status' => 'approved']);

        $this->api('GET', "/api/v1/approvals/{$record->id}", $supervisor)->assertOk()
            ->assertJsonPath('data.id', $record->id)
            ->assertJsonPath('data.pending_legs', [])
            ->assertJsonPath('data.review.time_in.can_approve', false);

        $this->api('GET', '/api/v1/approvals/999999', $supervisor)->assertNotFound();
    }

    // ------------------------------------------------------- decisions

    public static function actions(): array
    {
        return self::ACTIONS;
    }

    #[DataProvider('actions')]
    public function test_each_decision_updates_the_leg_and_notifies_the_student(string $leg, string $action, string $column, string $status, string $message): void
    {
        foreach ([3, 4] as $roleId) {
            $reviewer = $this->user($roleId);
            $student = $this->student($roleId === 3 ? $reviewer : $this->user(3));
            $record = $this->record($student);
            $data = $action === 'reject' ? ['reason' => 'Blurry photo'] : [];

            $response = $this->api('POST', $this->decisionUrl($record, $leg, $action), $reviewer, $data)
                ->assertOk()
                ->assertJsonPath('message', $message)
                ->assertJsonPath('attendance.id', $record->id)
                ->assertJsonPath("attendance.{$column}_status", $status)
                ->assertJsonPath("attendance.{$column}_rejection_reason", $action === 'reject' ? 'Blurry photo' : null)
                ->assertJsonPath("attendance.review.{$column}.can_approve", false);

            $other = $column === 'time_in' ? 'time_out' : 'time_in';
            $this->assertSame([$other], $response->json('attendance.pending_legs'));

            $fresh = $record->fresh();
            $this->assertSame($status, $fresh->{"{$column}_status"});
            $this->assertSame('pending', $fresh->{"{$other}_status"});

            $notification = Notification::where('user_id', $student->user_id)->sole();
            $this->assertSame('attendance_reviewed', $notification->type);
            $this->assertSame(['attendance_id' => $record->id], $notification->data);
            $this->assertSame((substr($message, 0, 7) === 'Time-in' ? 'Time-in ' : 'Time-out ').$status, $notification->title);
            if ($action === 'reject') {
                $this->assertStringContainsString('Reason: Blurry photo', $notification->body);
            }
        }
    }

    public function test_approving_both_legs_credits_hours(): void
    {
        $supervisor = $this->user(3);
        $record = $this->record($this->student($supervisor));

        $this->api('POST', $this->decisionUrl($record, 'time-out', 'approve'), $supervisor)
            ->assertOk()->assertJsonPath('attendance.rendered_hours', null);
        $this->api('POST', $this->decisionUrl($record, 'time-in', 'approve'), $supervisor)
            ->assertOk()->assertJsonPath('attendance.rendered_hours', 9)
            ->assertJsonPath('attendance.pending_legs', []);
    }

    public static function notPendingStates(): array
    {
        $cases = [];

        foreach (self::ACTIONS as $name => [$leg, $action, $column]) {
            foreach (['approved', 'rejected', null] as $state) {
                $cases["{$name} when ".($state ?? 'not submitted')] = [$leg, $action, $column, $state];
            }
        }

        return $cases;
    }

    #[DataProvider('notPendingStates')]
    public function test_a_leg_that_is_not_pending_is_a_rule_422(string $leg, string $action, string $column, ?string $state): void
    {
        $supervisor = $this->user(3);
        $attributes = ["{$column}_status" => $state];
        if ($state === null) {
            $attributes[$column] = null;
        }
        $record = $this->record($this->student($supervisor), $attributes);
        $label = $column === 'time_in' ? 'time-in' : 'time-out';

        $this->api('POST', $this->decisionUrl($record, $leg, $action), $supervisor, ['reason' => 'x'])
            ->assertStatus(422)
            ->assertJsonMissingPath('errors')
            ->assertJsonPath('message', "This {$label} is not pending review.")
            ->assertJsonPath('code', 'attendance_rule')
            ->assertJsonPath('attendance.id', $record->id)
            ->assertJsonPath("attendance.{$column}_status", $state);

        $this->assertSame($state, $record->fresh()->{"{$column}_status"});
        $this->assertSame(0, Notification::count());
    }

    public function test_a_pending_status_without_a_time_is_not_reviewable(): void
    {
        $supervisor = $this->user(3);
        $record = $this->record($this->student($supervisor), ['time_out' => null, 'time_out_status' => 'pending']);

        $this->api('GET', "/api/v1/approvals/{$record->id}", $supervisor)
            ->assertJsonPath('data.review.time_out.can_approve', false);
        $this->api('POST', $this->decisionUrl($record, 'time-out', 'approve'), $supervisor)
            ->assertStatus(422)->assertJsonPath('code', 'attendance_rule');
    }

    #[DataProvider('actions')]
    public function test_two_reviewers_racing_on_one_leg_exactly_one_wins(string $leg, string $action, string $column): void
    {
        $supervisor = $this->user(3);
        $record = $this->record($this->student($supervisor));
        $method = ($action === 'approve' ? 'approve' : 'reject').($column === 'time_in' ? 'TimeIn' : 'TimeOut');

        // The admin's decision commits between this request's route-binding
        // read and the service's locked re-read.
        $landed = false;
        DB::listen(function ($query) use (&$landed, $record, $method) {
            if ($landed || ! str_contains($query->sql, 'from `attendances`')) {
                return;
            }

            $landed = true;
            $service = app(AttendanceService::class);
            $method === 'approveTimeIn' || $method === 'approveTimeOut'
                ? $service->{$method}(Attendance::find($record->id))
                : $service->{$method}(Attendance::find($record->id), 'Admin first');
        });

        $label = $column === 'time_in' ? 'time-in' : 'time-out';
        $this->api('POST', $this->decisionUrl($record, $leg, $action), $supervisor, ['reason' => 'Supervisor second'])
            ->assertStatus(422)
            ->assertJsonPath('message', "This {$label} is not pending review.")
            ->assertJsonPath('code', 'attendance_rule');

        $this->assertTrue($landed);
        $this->assertSame(1, Notification::count());
        $expectedReason = $action === 'reject' ? 'Admin first' : null;
        $this->assertSame($expectedReason, $record->fresh()->{"{$column}_rejection_reason"});
    }

    // --------------------------------------------------- reject reason

    public function test_reject_reason_is_optional_and_empty_means_none(): void
    {
        $supervisor = $this->user(3);
        $a = $this->record($this->student($supervisor));
        $b = $this->record($this->student($supervisor));

        $this->api('POST', $this->decisionUrl($a, 'time-in', 'reject'), $supervisor)
            ->assertOk()->assertJsonPath('attendance.time_in_rejection_reason', null);
        $this->api('POST', $this->decisionUrl($b, 'time-out', 'reject'), $supervisor, ['reason' => ''])
            ->assertOk()->assertJsonPath('attendance.time_out_rejection_reason', null);
    }

    public static function badReasons(): array
    {
        return [
            'too long' => [str_repeat('a', 501)],
            'array' => [['a']],
            'invalid UTF-8' => ["bad \xB1\x31 text"],
        ];
    }

    #[DataProvider('badReasons')]
    public function test_bad_reject_reasons_are_422_and_change_nothing(mixed $reason): void
    {
        $supervisor = $this->user(3);
        $record = $this->record($this->student($supervisor));

        foreach (['time-in', 'time-out'] as $leg) {
            $this->api('POST', $this->decisionUrl($record, $leg, 'reject'), $supervisor, ['reason' => $reason])
                ->assertStatus(422)->assertJsonValidationErrors('reason');
        }

        $this->assertSame('pending', $record->fresh()->time_in_status);
        $this->assertSame('pending', $record->fresh()->time_out_status);
        $this->assertSame(0, Notification::count());
    }

    public function test_a_500_character_reason_is_accepted(): void
    {
        $supervisor = $this->user(3);
        $record = $this->record($this->student($supervisor));
        $reason = str_repeat('é', 500);

        $this->api('POST', $this->decisionUrl($record, 'time-in', 'reject'), $supervisor, ['reason' => $reason])
            ->assertOk()->assertJsonPath('attendance.time_in_rejection_reason', $reason);
    }

    // ---------------------------------------------------------- parity

    #[DataProvider('actions')]
    public function test_api_and_website_decisions_store_identical_rows_and_notifications(string $leg, string $action, string $column): void
    {
        $supervisor = $this->user(3);
        $webRecord = $this->record($this->student($supervisor));
        $apiRecord = $this->record($this->student($supervisor), ['date' => $webRecord->date->format('Y-m-d')]);
        $data = $action === 'reject' ? ['reason' => 'Wrong place'] : [];
        $webMethod = $action.'-'.$leg;

        $this->app['auth']->forgetGuards();
        $this->actingAs($supervisor)->patch("/attendance-approvals/{$webRecord->id}/{$webMethod}", $data)
            ->assertRedirect(route('attendance-approvals.index'));
        $this->api('POST', $this->decisionUrl($apiRecord, $leg, $action), $supervisor, $data)->assertOk();

        $strip = fn (Attendance $a) => collect($a->fresh()->getAttributes())
            ->except(['id', 'student_id', 'created_at', 'updated_at'])->all();
        $this->assertSame($strip($webRecord), $strip($apiRecord));

        $notifications = Notification::orderBy('id')->get();
        $this->assertCount(2, $notifications);
        $this->assertSame($notifications[0]->title, $notifications[1]->title);
        $this->assertSame($notifications[0]->body, $notifications[1]->body);
        $this->assertSame(['attendance_id' => $apiRecord->id], $notifications[1]->data);
    }

    // ----------------------------------------------------------- photos

    public function test_photo_access_per_role(): void
    {
        $supervisor = $this->user(3);
        $student = $this->student($supervisor);
        $path = "attendance-photos/{$student->id}/out.jpg";
        Storage::disk('local')->put($path, 'jpeg');
        $record = $this->record($student, ['time_out_photo_path' => $path]);

        $url = $this->api('GET', "/api/v1/approvals/{$record->id}", $supervisor)->json('data.time_out_photo_url');
        $this->assertNotNull($url);

        $this->api('GET', $url, $supervisor)->assertOk();
        $this->api('GET', $url, $this->user(4))->assertOk();
        $this->api('GET', $url, $this->user(3))->assertNotFound();
        $this->api('GET', $url, $this->user(1))->assertNotFound();
        $this->api('GET', $url, null)->assertUnauthorized();
    }
}
