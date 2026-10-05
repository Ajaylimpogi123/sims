<?php

namespace Tests\Feature\Api\V1;

use App\Models\Company;
use App\Models\InternshipReport;
use App\Models\Notification;
use App\Models\Student;
use App\Models\User;
use App\Services\InternshipReportService;
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
 * Module 11: Report Reviews through /api/v1. Coordinator / Admin see every
 * report and review; Supervisor sees own students' reports only and never
 * reviews. Shares the website's list scope (InternshipReport::visibleTo),
 * InternshipReportPolicy and InternshipReportService::review.
 */
class ReportReviewApiTest extends TestCase
{
    use RefreshDatabase;

    private const ALREADY_REVIEWED = 'This report has already been reviewed.';

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

    // ---------------------------------------------------------- helpers

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
     * Pending daily report, each call on an earlier day.
     */
    private function report(Student $student, array $attributes = []): InternshipReport
    {
        $date = today()->subDays($this->day++)->toDateString();

        return InternshipReport::factory()->create(array_merge([
            'student_id' => $student->id,
            'type' => 'daily',
            'period_start' => $date,
            'period_end' => $date,
            'status' => 'pending',
        ], $attributes));
    }

    private function reviewed(Student $student, User $reviewer, array $attributes = []): InternshipReport
    {
        return $this->report($student, array_merge([
            'status' => 'reviewed',
            'reviewer_comment' => 'Fine.',
            'reviewed_by' => $reviewer->id,
            'reviewed_at' => now(),
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
            : $this->withHeaders($headers)->postJson($uri, $data);
    }

    private function rawJson(string $uri, User $user, string $body): TestResponse
    {
        $this->app['auth']->forgetGuards();

        return $this->call('POST', $uri, [], [], [], $this->transformHeadersToServerVars([
            'Accept' => 'application/json',
            'Content-Type' => 'application/json',
            'Authorization' => 'Bearer '.$user->createToken('test')->plainTextToken,
        ]), $body);
    }

    private function reviewUrl(InternshipReport $report): string
    {
        return "/api/v1/report-reviews/{$report->id}/review";
    }

    /**
     * @return list<int>
     */
    private function listIds(User $user, array $query = []): array
    {
        $ids = [];
        $cursor = null;

        do {
            $response = $this->api('GET', '/api/v1/report-reviews', $user, array_filter([
                ...$query, 'per_page' => 2, 'cursor' => $cursor,
            ], fn ($value) => $value !== null))->assertOk();

            $ids = [...$ids, ...array_column($response->json('data'), 'id')];
            $cursor = $response->json('meta.next_cursor');
        } while ($response->json('meta.has_more'));

        return $ids;
    }

    private function minimalPdf(): string
    {
        return "%PDF-1.4\n1 0 obj<</Type/Catalog/Pages 2 0 R>>endobj\n2 0 obj<</Type/Pages/Kids[]/Count 0>>endobj\ntrailer<</Root 1 0 R>>\n%%EOF\n";
    }

    // ---------------------------------------------------------- access

    public function test_every_endpoint_requires_a_token(): void
    {
        $report = $this->report($this->student(null));

        $this->api('GET', '/api/v1/report-reviews', null)->assertUnauthorized();
        $this->api('GET', "/api/v1/report-reviews/{$report->id}", null)->assertUnauthorized();
        $this->api('POST', $this->reviewUrl($report), null)->assertUnauthorized();
    }

    public function test_students_get_403_everywhere(): void
    {
        $student = $this->student(null);
        $report = $this->report($student);
        $owner = $student->user;

        $this->api('GET', '/api/v1/report-reviews', $owner)->assertForbidden();
        $this->api('GET', "/api/v1/report-reviews/{$report->id}", $owner)->assertForbidden();
        $this->api('POST', $this->reviewUrl($report), $owner)->assertForbidden();

        $this->assertSame('pending', $report->fresh()->status);
    }

    public function test_a_supervisor_sees_own_students_only_and_can_never_review(): void
    {
        $supervisor = $this->user(3);
        $other = $this->user(3);
        $own = $this->report($this->student($supervisor));
        $foreign = $this->report($this->student($other));
        $unassigned = $this->report($this->student(null));

        $this->assertSame([$own->id], $this->listIds($supervisor));
        $this->assertSame([$foreign->id], $this->listIds($other));

        $this->api('GET', "/api/v1/report-reviews/{$own->id}", $supervisor)
            ->assertOk()
            ->assertJsonPath('report.id', $own->id)
            ->assertJsonPath('report.can_review', false);
        $this->api('GET', "/api/v1/report-reviews/{$foreign->id}", $supervisor)->assertNotFound();
        $this->api('GET', "/api/v1/report-reviews/{$unassigned->id}", $supervisor)->assertNotFound();

        // View only: 403 even on their own student's pending report.
        $this->api('POST', $this->reviewUrl($own), $supervisor, ['comment' => 'Mine'])
            ->assertForbidden()->assertJsonPath('message', 'Unauthorized access');
        $this->api('POST', $this->reviewUrl($foreign), $supervisor)->assertForbidden();

        $this->assertSame('pending', $own->fresh()->status);
        $this->assertSame(0, Notification::where('type', 'report_reviewed')->count());
    }

    public function test_a_student_id_filter_never_widens_a_supervisors_scope(): void
    {
        $supervisor = $this->user(3);
        $this->report($this->student($supervisor));
        $foreignStudent = $this->student($this->user(3));
        $this->report($foreignStudent);

        $this->api('GET', '/api/v1/report-reviews', $supervisor, ['student_id' => $foreignStudent->id])
            ->assertOk()->assertJsonCount(0, 'data');
    }

    #[DataProvider('staffRoles')]
    public function test_coordinators_and_admins_see_every_report_and_can_review_pending_ones(int $roleId): void
    {
        $staff = $this->user($roleId);
        $a = $this->report($this->student($this->user(3)));
        $b = $this->report($this->student(null));
        $done = $this->reviewed($this->student(null), $this->user(2));

        $this->assertEqualsCanonicalizing([$a->id, $b->id, $done->id], $this->listIds($staff));

        $this->api('GET', "/api/v1/report-reviews/{$a->id}", $staff)
            ->assertOk()->assertJsonPath('report.can_review', true);
        $this->api('GET', "/api/v1/report-reviews/{$done->id}", $staff)
            ->assertOk()->assertJsonPath('report.can_review', false);

        $this->api('POST', $this->reviewUrl($b), $staff, ['comment' => 'Well done'])
            ->assertOk()
            ->assertJsonPath('message', 'Report reviewed.')
            ->assertJsonPath('report.status', 'reviewed')
            ->assertJsonPath('report.reviewer_comment', 'Well done')
            ->assertJsonPath('report.reviewer.id', $staff->id)
            ->assertJsonPath('report.can_review', false);
    }

    public static function staffRoles(): array
    {
        return ['coordinator' => [2], 'admin' => [4]];
    }

    public function test_unknown_malformed_and_oversized_ids_are_404(): void
    {
        $admin = $this->user(4);

        foreach (['999999', '0', 'abc', '1e3', '99999999999999999999'] as $id) {
            $this->api('GET', "/api/v1/report-reviews/{$id}", $admin)->assertNotFound();
            $this->api('POST', "/api/v1/report-reviews/{$id}/review", $admin)->assertNotFound();
        }
    }

    // ---------------------------------------------------------- list

    public function test_items_carry_the_report_object_student_and_flags(): void
    {
        $coordinator = $this->user(2);
        $company = Company::factory()->create(['company_name' => 'Acme Corp']);
        $studentUser = User::factory()->create(['role_id' => 1, 'status' => 'active', 'name' => 'Juan Dela Cruz']);
        $student = Student::factory()->create([
            'user_id' => $studentUser->id,
            'student_number' => '2023-0001',
            'course' => 'BSIT',
            'section' => 'A',
            'company_id' => $company->id,
        ]);
        Storage::disk('local')->put('report-attachments/a.pdf', $this->minimalPdf());
        $report = $this->report($student, [
            'attachment_path' => 'report-attachments/a.pdf',
            'attachment_original_name' => 'Proof.pdf',
            'content' => 'Did things.',
        ]);

        $item = $this->api('GET', '/api/v1/report-reviews', $coordinator)
            ->assertOk()
            ->assertJsonStructure(['data', 'meta' => ['per_page', 'next_cursor', 'has_more']])
            ->json('data.0');

        $this->assertSame([
            'id', 'type', 'period_start', 'period_end', 'content', 'status', 'reviewer_comment', 'reviewer',
            'reviewed_at', 'attachment', 'created_at', 'updated_at', 'can_edit', 'can_delete', 'student', 'can_review',
        ], array_keys($item));
        $this->assertSame($report->id, $item['id']);
        $this->assertSame('Did things.', $item['content']);
        $this->assertFalse($item['can_edit']);
        $this->assertFalse($item['can_delete']);
        $this->assertTrue($item['can_review']);
        $this->assertSame([
            'id' => $student->id,
            'user_id' => $studentUser->id,
            'name' => 'Juan Dela Cruz',
            'student_number' => '2023-0001',
            'course' => 'BSIT',
            'section' => 'A',
            'company' => ['id' => $company->id, 'name' => 'Acme Corp'],
        ], $item['student']);
        $this->assertSame('Proof.pdf', $item['attachment']['name']);
        $this->assertStringContainsString("/api/v1/reports/{$report->id}/attachment?v=", $item['attachment']['url']);
    }

    public function test_list_is_pending_first_then_newest_period_and_paginates_without_repeats(): void
    {
        $admin = $this->user(4);
        $student = $this->student(null);
        $reviewer = $this->user(2);

        $newestReviewed = $this->reviewed($student, $reviewer);   // today
        $newestPending = $this->report($student);                // -1 day
        $olderReviewed = $this->reviewed($student, $reviewer);    // -2
        $olderPending = $this->report($student);                 // -3
        $oldestPending = $this->report($student);                // -4

        $expected = [$newestPending->id, $olderPending->id, $oldestPending->id, $newestReviewed->id, $olderReviewed->id];

        $this->assertSame($expected, $this->listIds($admin));

        // Same order as the website's list.
        $this->actingAs($admin)->get('/report-reviews')->assertInertia(fn (Assert $page) => $page
            ->where('reports', fn ($reports) => collect($reports)->pluck('id')->all() === $expected));
    }

    public function test_list_matches_the_website_list_for_every_reviewer_role(): void
    {
        $supervisor = $this->user(3);
        $other = $this->user(3);
        $this->report($this->student($supervisor));
        $this->reviewed($this->student($supervisor), $this->user(2));
        $this->report($this->student($other));
        $this->report($this->student(null));

        foreach ([$supervisor, $other, $this->user(2), $this->user(4)] as $user) {
            $webIds = [];
            $this->actingAs($user)->get('/report-reviews')->assertInertia(function (Assert $page) use (&$webIds) {
                $webIds = collect($page->toArray()['props']['reports'])->pluck('id')->all();
            });

            $this->assertSame($webIds, $this->listIds($user));
        }
    }

    public function test_list_filters_by_status_type_student_and_search(): void
    {
        $admin = $this->user(4);
        $juan = $this->student(null, [
            'user_id' => User::factory()->create(['role_id' => 1, 'name' => 'Juan Dela Cruz'])->id,
            'student_number' => '2023-0001',
        ]);
        $maria = $this->student(null, [
            'user_id' => User::factory()->create(['role_id' => 1, 'name' => 'Maria 100%_Santos'])->id,
            'student_number' => '2024-7777',
        ]);
        $juanDaily = $this->report($juan);
        $juanWeekly = $this->report($juan, ['type' => 'weekly']);
        $mariaReviewed = $this->reviewed($maria, $this->user(2));

        $this->assertSame([$juanWeekly->id], $this->listIds($admin, ['type' => 'weekly']));
        $this->assertSame([$mariaReviewed->id], $this->listIds($admin, ['status' => 'reviewed']));
        $this->assertSame([$juanDaily->id, $juanWeekly->id], $this->listIds($admin, ['student_id' => $juan->id]));
        $this->assertSame([$juanDaily->id, $juanWeekly->id], $this->listIds($admin, ['search' => 'dela cruz']));
        $this->assertSame([$mariaReviewed->id], $this->listIds($admin, ['search' => '7777']));
        $this->assertSame([$mariaReviewed->id], $this->listIds($admin, ['search' => '100%_']));
        // % and _ are literal, not wildcards.
        $this->assertSame([$mariaReviewed->id], $this->listIds($admin, ['search' => '%']));
        $this->assertSame([], $this->listIds($admin, ['search' => 'J_an']));
        $this->assertSame([], $this->listIds($admin, ['status' => 'pending', 'type' => 'weekly', 'student_id' => $maria->id]));
        $this->assertCount(3, $this->listIds($admin, ['search' => '   ', 'type' => '', 'status' => '']));
    }

    public static function badQueries(): array
    {
        return [
            'status' => [['status' => 'approved'], 'status'],
            'type' => [['type' => 'monthly'], 'type'],
            'student_id text' => [['student_id' => 'abc'], 'student_id'],
            'student_id zero' => [['student_id' => '0'], 'student_id'],
            'search too long' => [['search' => str_repeat('a', 256)], 'search'],
            'search array' => [['search' => ['a']], 'search'],
            'per_page' => [['per_page' => '51'], 'per_page'],
            'cursor junk' => [['cursor' => 'not-a-cursor'], 'cursor'],
            'cursor of /reports' => [['cursor' => 'eyJwZXJpb2Rfc3RhcnQiOiIyMDI2LTEwLTAxIDAwOjAwOjAwIiwiaWQiOjEsIl9wb2ludHNUb05leHRJdGVtcyI6dHJ1ZX0'], 'cursor'],
            'cursor bad status' => [['cursor' => rtrim(strtr(base64_encode('{"status":"x","period_start":"2026-10-01 00:00:00","id":1,"_pointsToNextItems":true}'), '+/', '-_'), '=')], 'cursor'],
            'invalid UTF-8 search' => [['search' => "bad \xB1\x31"], 'search'],
        ];
    }

    #[DataProvider('badQueries')]
    public function test_bad_list_queries_are_422(array $query, string $key): void
    {
        $this->api('GET', '/api/v1/report-reviews', $this->user(2), $query)
            ->assertStatus(422)->assertJsonValidationErrors($key);
    }

    // ---------------------------------------------------------- review

    public function test_a_review_notifies_the_student_like_the_website(): void
    {
        $coordinator = $this->user(2);
        $student = $this->student($this->user(3));
        $report = $this->report($student);

        $this->api('POST', $this->reviewUrl($report), $coordinator, ['comment' => "Good detail.\nKeep going."])
            ->assertOk();

        $fresh = $report->fresh();
        $this->assertSame('reviewed', $fresh->status);
        $this->assertSame("Good detail.\nKeep going.", $fresh->reviewer_comment);
        $this->assertSame($coordinator->id, $fresh->reviewed_by);
        $this->assertNotNull($fresh->reviewed_at);

        $notification = Notification::where('type', 'report_reviewed')->sole();
        $this->assertSame($student->user_id, $notification->user_id);
        $this->assertSame($report->id, $notification->data['report_id']);

        $this->api('GET', '/api/v1/notifications', $student->user)
            ->assertJsonPath('data.0.type', 'report_reviewed')
            ->assertJsonPath('data.0.target', ['screen' => 'my-reports', 'params' => ['report_id' => $report->id]]);
    }

    public function test_the_comment_is_optional_and_empty_means_none(): void
    {
        $admin = $this->user(4);
        $a = $this->report($this->student(null));
        $b = $this->report($this->student(null));
        $c = $this->report($this->student(null));

        $this->api('POST', $this->reviewUrl($a), $admin)->assertOk()->assertJsonPath('report.reviewer_comment', null);
        $this->api('POST', $this->reviewUrl($b), $admin, ['comment' => ''])->assertOk()->assertJsonPath('report.reviewer_comment', null);
        $this->api('POST', $this->reviewUrl($c), $admin, ['comment' => null])->assertOk()->assertJsonPath('report.reviewer_comment', null);

        $this->rawJson($this->reviewUrl($this->report($this->student(null))), $admin, '')->assertOk();
    }

    public function test_a_2000_character_multibyte_comment_is_stored_intact(): void
    {
        $comment = str_repeat('é', 1000).str_repeat('🙂', 1000);
        $report = $this->report($this->student(null));

        $this->api('POST', $this->reviewUrl($report), $this->user(2), ['comment' => $comment])->assertOk();

        $this->assertSame($comment, $report->fresh()->reviewer_comment);
    }

    public static function badComments(): array
    {
        return [
            'too long' => [str_repeat('a', 2001)],
            'array' => [['a']],
            'number' => [12],
        ];
    }

    /**
     * Sent as a form body: a JSON encoder can't carry invalid UTF-8.
     */
    public function test_an_invalid_utf8_comment_is_422_and_changes_nothing(): void
    {
        $report = $this->report($this->student(null));
        $this->app['auth']->forgetGuards();

        $this->withHeaders([
            'Accept' => 'application/json',
            'Authorization' => 'Bearer '.$this->user(2)->createToken('test')->plainTextToken,
        ])->post($this->reviewUrl($report), ['comment' => "bad \xB1\x31 text"])
            ->assertStatus(422)->assertJsonValidationErrors('comment');

        $this->assertSame('pending', $report->fresh()->status);
        $this->assertSame(0, Notification::count());
    }

    #[DataProvider('badComments')]
    public function test_bad_comments_are_422_and_change_nothing(mixed $comment): void
    {
        $report = $this->report($this->student(null));

        $this->api('POST', $this->reviewUrl($report), $this->user(2), ['comment' => $comment])
            ->assertStatus(422)->assertJsonValidationErrors('comment');

        $this->assertSame('pending', $report->fresh()->status);
        $this->assertSame(0, Notification::where('type', 'report_reviewed')->count());
    }

    public static function undecodableBodies(): array
    {
        return [
            'invalid UTF-8 inside JSON' => ["{\"comment\":\"bad\xC3\x28\"}"],
            'truncated JSON' => ['{"comment":'],
            'not JSON at all' => ['comment=hello'],
        ];
    }

    /**
     * A body that doesn't decode must not be read as "no comment" and still
     * store the (irreversible) review.
     */
    #[DataProvider('undecodableBodies')]
    public function test_an_undecodable_json_body_is_422_and_changes_nothing(string $body): void
    {
        $report = $this->report($this->student(null));

        $this->rawJson($this->reviewUrl($report), $this->user(4), $body)
            ->assertStatus(422)
            ->assertExactJson([
                'message' => 'The request body is not valid JSON.',
                'errors' => ['input' => ['The request body is not valid JSON.']],
            ]);

        $this->assertSame('pending', $report->fresh()->status);
        $this->assertSame(0, Notification::count());
    }

    public function test_an_already_reviewed_report_is_a_422_with_the_fresh_report(): void
    {
        $first = $this->user(4);
        $report = $this->reviewed($this->student(null), $first, ['reviewer_comment' => 'First']);

        $this->api('POST', $this->reviewUrl($report), $this->user(2), ['comment' => 'Second'])
            ->assertStatus(422)
            ->assertJsonMissingPath('errors')
            ->assertJsonPath('message', self::ALREADY_REVIEWED)
            ->assertJsonPath('code', 'report_not_pending')
            ->assertJsonPath('report.id', $report->id)
            ->assertJsonPath('report.status', 'reviewed')
            ->assertJsonPath('report.reviewer_comment', 'First')
            ->assertJsonPath('report.reviewer.id', $first->id)
            ->assertJsonPath('report.can_review', false)
            ->assertJsonPath('report.student.id', $report->student_id);

        $this->assertSame('First', $report->fresh()->reviewer_comment);
        $this->assertSame(0, Notification::count());
    }

    public function test_a_double_tap_reviews_once_and_notifies_once(): void
    {
        $admin = $this->user(4);
        $report = $this->report($this->student(null));

        $this->api('POST', $this->reviewUrl($report), $admin, ['comment' => 'One'])->assertOk();
        $this->api('POST', $this->reviewUrl($report), $admin, ['comment' => 'Two'])
            ->assertStatus(422)->assertJsonPath('code', 'report_not_pending');

        $this->assertSame('One', $report->fresh()->reviewer_comment);
        $this->assertSame(1, Notification::where('type', 'report_reviewed')->count());
    }

    public function test_two_reviewers_racing_exactly_one_wins_and_one_notification_is_sent(): void
    {
        $admin = $this->user(4);
        $coordinator = $this->user(2);
        $report = $this->report($this->student(null));

        // The admin's review commits between this request's read of the
        // report and the service's locked re-read.
        $landed = false;
        DB::listen(function ($query) use (&$landed, $report, $admin) {
            if ($landed || ! str_contains($query->sql, 'from `internship_reports`')) {
                return;
            }

            $landed = true;
            app(InternshipReportService::class)->review(InternshipReport::find($report->id), $admin, 'Admin first');
        });

        $this->api('POST', $this->reviewUrl($report), $coordinator, ['comment' => 'Coordinator second'])
            ->assertStatus(422)
            ->assertJsonPath('code', 'report_not_pending')
            ->assertJsonPath('report.reviewer_comment', 'Admin first')
            ->assertJsonPath('report.reviewer.id', $admin->id);

        $this->assertTrue($landed);
        $this->assertSame('Admin first', $report->fresh()->reviewer_comment);
        $this->assertSame(1, Notification::where('type', 'report_reviewed')->count());
    }

    public function test_a_report_deleted_by_the_student_meanwhile_is_a_404(): void
    {
        $report = $this->report($this->student(null));

        $landed = false;
        DB::listen(function ($query) use (&$landed, $report) {
            if ($landed || ! str_contains($query->sql, 'from `internship_reports`')) {
                return;
            }

            $landed = true;
            DB::table('internship_reports')->where('id', $report->id)->delete();
        });

        $this->api('POST', $this->reviewUrl($report), $this->user(2), ['comment' => 'Too late'])
            ->assertNotFound();

        $this->assertTrue($landed);
        $this->assertSame(0, Notification::count());
    }

    // ---------------------------------------------------------- web parity

    public function test_api_and_website_reviews_store_identical_rows_and_notifications(): void
    {
        $coordinator = $this->user(2);
        $webReport = $this->report($this->student(null));
        $apiReport = $this->report($this->student(null));

        $this->actingAs($coordinator)->patch("/report-reviews/{$webReport->id}", ['comment' => 'Same words'])
            ->assertRedirect(route('report-reviews.index', absolute: false))
            ->assertSessionHas('success', 'Report reviewed.');
        $this->app['auth']->forgetGuards();

        $this->api('POST', $this->reviewUrl($apiReport), $coordinator, ['comment' => 'Same words'])->assertOk();

        $columns = ['status', 'reviewer_comment', 'reviewed_by', 'reviewed_at'];
        $this->assertSame(
            array_intersect_key($webReport->fresh()->getAttributes(), array_flip($columns)),
            array_intersect_key($apiReport->fresh()->getAttributes(), array_flip($columns)),
        );

        $web = Notification::where('data->report_id', $webReport->id)->sole();
        $api = Notification::where('data->report_id', $apiReport->id)->sole();
        $this->assertSame([$web->type, $web->title, $web->message], [$api->type, $api->title, $api->message]);
        $this->assertSame($webReport->student->user_id, $web->user_id);
        $this->assertSame($apiReport->student->user_id, $api->user_id);
    }

    public function test_api_and_website_reject_the_same_comment_and_the_same_second_review(): void
    {
        $coordinator = $this->user(2);
        $report = $this->report($this->student(null));
        $long = str_repeat('a', 2001);

        $this->actingAs($coordinator)->patch("/report-reviews/{$report->id}", ['comment' => $long])
            ->assertSessionHasErrors('comment');
        $this->app['auth']->forgetGuards();
        $this->api('POST', $this->reviewUrl($report), $coordinator, ['comment' => $long])
            ->assertStatus(422)->assertJsonValidationErrors('comment');

        $this->api('POST', $this->reviewUrl($report), $coordinator)->assertOk();

        $this->actingAs($coordinator)->patch("/report-reviews/{$report->id}")
            ->assertSessionHas('error', self::ALREADY_REVIEWED);
        $this->app['auth']->forgetGuards();
        $this->api('POST', $this->reviewUrl($report), $coordinator)
            ->assertStatus(422)->assertJsonPath('message', self::ALREADY_REVIEWED);
    }

    public function test_a_review_from_the_app_shows_in_the_students_my_reports(): void
    {
        $student = $this->student(null);
        $report = $this->report($student);

        $this->api('POST', $this->reviewUrl($report), $this->user(4), ['comment' => 'Seen'])->assertOk();

        $this->api('GET', "/api/v1/reports/{$report->id}", $student->user)
            ->assertOk()
            ->assertJsonPath('report.status', 'reviewed')
            ->assertJsonPath('report.reviewer_comment', 'Seen')
            ->assertJsonPath('report.can_edit', false);
    }

    // ---------------------------------------------------------- attachment

    public function test_attachment_access_per_role(): void
    {
        $supervisor = $this->user(3);
        $student = $this->student($supervisor);
        Storage::disk('local')->put('report-attachments/a.pdf', $this->minimalPdf());
        $report = $this->report($student, [
            'attachment_path' => 'report-attachments/a.pdf',
            'attachment_original_name' => 'a.pdf',
        ]);

        $url = $this->api('GET', "/api/v1/report-reviews/{$report->id}", $this->user(2))->json('report.attachment.url');
        $path = parse_url($url, PHP_URL_PATH).'?'.parse_url($url, PHP_URL_QUERY);

        foreach ([$supervisor, $this->user(2), $this->user(4), $student->user] as $allowed) {
            $this->api('GET', $path, $allowed)->assertOk()->assertHeader('Content-Type', 'application/pdf');
        }

        foreach ([$this->user(3), $this->user(1)] as $denied) {
            $this->api('GET', $path, $denied)->assertNotFound();
        }
    }
}
