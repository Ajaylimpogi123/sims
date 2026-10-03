<?php

namespace Tests\Feature\Api\V1;

use App\Models\InternshipReport;
use App\Models\Notification;
use App\Models\Student;
use App\Models\User;
use App\Services\InternshipReportService;
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
 * Module 7: the student's own internship reports through /api/v1, sharing
 * validation, the duplicate-period rule, attachment storage and
 * notifications with the website through InternshipReportService.
 */
class ReportApiTest extends TestCase
{
    use RefreshDatabase;

    private const NOT_PENDING = 'This report has already been reviewed and can no longer be changed.';

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

    // ---------------------------------------------------------- helpers

    private function user(int $roleId): User
    {
        return User::factory()->create(['role_id' => $roleId, 'status' => 'active']);
    }

    /**
     * @return array{0: User, 1: string, 2: Student}
     */
    private function student(?User $supervisor = null): array
    {
        $user = $this->user(1);
        $student = Student::factory()->create([
            'user_id' => $user->id,
            'supervisor_id' => $supervisor?->id,
        ]);

        return [$user, $user->createToken('test')->plainTextToken, $student];
    }

    private function token(User $user): string
    {
        return $user->createToken('test')->plainTextToken;
    }

    private function api(string $method, string $uri, ?string $token, array $data = [], bool $json = false): TestResponse
    {
        $this->app['auth']->forgetGuards();

        $headers = ['Accept' => 'application/json'];

        if ($token !== null) {
            $headers['Authorization'] = 'Bearer '.$token;
        }

        $this->defaultHeaders = [];
        $request = $this->withHeaders($headers);

        return match (true) {
            $method === 'GET' => $request->get($uri.($data ? '?'.http_build_query($data) : '')),
            $json => $request->json($method, $uri, $data),
            $method === 'DELETE' => $request->delete($uri, $data),
            default => $request->post($uri, $data),
        };
    }

    private function payload(array $overrides = []): array
    {
        return array_merge([
            'type' => 'daily',
            'period_start' => '2026-10-02',
            'period_end' => '2026-10-02',
            'content' => 'Set up the staging server and wrote the deployment notes.',
        ], $overrides);
    }

    private function report(Student $student, array $attributes = []): InternshipReport
    {
        return InternshipReport::factory()->create(array_merge(['student_id' => $student->id], $attributes));
    }

    private function withAttachment(Student $student, array $attributes = [], string $name = 'proof.pdf'): InternshipReport
    {
        $path = 'report-attachments/'.uniqid('', true).'.pdf';
        Storage::disk('local')->put($path, $this->minimalPdf());

        return $this->report($student, array_merge([
            'attachment_path' => $path,
            'attachment_original_name' => $name,
        ], $attributes));
    }

    /**
     * A real file whose bytes decide its detected type (not the fake's
     * declared MIME type).
     */
    private function realFile(string $clientName, string $bytes): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'rpt');
        file_put_contents($path, $bytes);

        return new UploadedFile($path, $clientName, null, null, true);
    }

    private function minimalPdf(): string
    {
        return "%PDF-1.4\n1 0 obj<</Type/Catalog/Pages 2 0 R>>endobj\n2 0 obj<</Type/Pages/Kids[]/Count 0>>endobj\ntrailer<</Root 1 0 R>>\n%%EOF\n";
    }

    private function update(InternshipReport $report, string $token, array $data): TestResponse
    {
        return $this->api('POST', "/api/v1/reports/{$report->id}", $token, ['_method' => 'PATCH', ...$data]);
    }

    // ---------------------------------------------------------- access

    public function test_every_endpoint_requires_a_token(): void
    {
        [, , $student] = $this->student();
        $report = $this->withAttachment($student);

        $this->api('GET', '/api/v1/reports', null)->assertUnauthorized();
        $this->api('GET', "/api/v1/reports/{$report->id}", null)->assertUnauthorized();
        $this->api('POST', '/api/v1/reports', null, $this->payload())->assertUnauthorized();
        $this->update($report, 'nope', $this->payload())->assertUnauthorized();
        $this->api('DELETE', "/api/v1/reports/{$report->id}", null)->assertUnauthorized();
        $this->api('GET', "/api/v1/reports/{$report->id}/attachment", null)->assertUnauthorized();
    }

    #[DataProvider('staffRoles')]
    public function test_staff_roles_get_403_on_the_student_endpoints(int $roleId): void
    {
        [, , $student] = $this->student();
        $report = $this->report($student);
        $token = $this->token($this->user($roleId));

        $this->api('GET', '/api/v1/reports', $token)->assertForbidden()->assertJsonPath('message', 'Unauthorized access');
        $this->api('GET', "/api/v1/reports/{$report->id}", $token)->assertForbidden();
        $this->api('POST', '/api/v1/reports', $token, $this->payload())->assertForbidden();
        $this->update($report, $token, $this->payload())->assertForbidden();
        $this->api('DELETE', "/api/v1/reports/{$report->id}", $token)->assertForbidden();

        $this->assertSame(1, InternshipReport::count());
        $this->assertSame(0, Notification::count());
    }

    public static function staffRoles(): array
    {
        return ['coordinator' => [2], 'supervisor' => [3], 'admin' => [4]];
    }

    public function test_a_student_without_a_profile_gets_409(): void
    {
        $token = $this->token($this->user(1));
        [, , $other] = $this->student();
        $report = $this->report($other);

        foreach ([
            $this->api('GET', '/api/v1/reports', $token),
            $this->api('GET', "/api/v1/reports/{$report->id}", $token),
            $this->api('POST', '/api/v1/reports', $token, $this->payload()),
            $this->update($report, $token, $this->payload()),
            $this->api('DELETE', "/api/v1/reports/{$report->id}", $token),
        ] as $response) {
            $response->assertStatus(409)->assertJsonPath('code', 'no_student_profile');
        }

        $this->assertSame(1, InternshipReport::count());
    }

    public function test_another_students_report_is_404_everywhere(): void
    {
        [, $token] = $this->student();
        [, , $other] = $this->student();
        $report = $this->withAttachment($other);

        $this->api('GET', "/api/v1/reports/{$report->id}", $token)->assertNotFound()->assertJsonPath('message', 'Not found.');
        $this->update($report, $token, $this->payload())->assertNotFound();
        $this->api('DELETE', "/api/v1/reports/{$report->id}", $token)->assertNotFound();
        $this->api('GET', "/api/v1/reports/{$report->id}/attachment", $token)->assertNotFound();

        $this->assertSame($report->content, $report->fresh()->content);
        Storage::disk('local')->assertExists($report->attachment_path);
    }

    public function test_unknown_malformed_and_oversized_ids_are_404(): void
    {
        [, $token] = $this->student();

        foreach (['999999', 'abc', '0', '-1', '99999999999999999999999'] as $id) {
            $this->api('GET', "/api/v1/reports/{$id}", $token)->assertNotFound();
            $this->api('DELETE', "/api/v1/reports/{$id}", $token)->assertNotFound();
            $this->api('GET', "/api/v1/reports/{$id}/attachment", $token)->assertNotFound();
        }
    }

    // ---------------------------------------------------------- list / show

    public function test_list_returns_only_own_reports_newest_period_first_with_the_full_shape(): void
    {
        $coordinator = $this->user(2);
        $coordinator->update(['name' => 'Rita Reviewer']);
        [, $token, $student] = $this->student();
        [, , $other] = $this->student();

        $older = $this->report($student, ['type' => 'weekly', 'period_start' => '2026-09-21', 'period_end' => '2026-09-25']);
        $reviewed = $this->report($student, [
            'type' => 'daily', 'period_start' => '2026-09-30', 'period_end' => '2026-09-30',
            'status' => 'reviewed', 'reviewer_comment' => 'Good work.',
            'reviewed_by' => $coordinator->id, 'reviewed_at' => '2026-10-01 09:12:00',
        ]);
        $newest = $this->withAttachment($student, ['period_start' => '2026-10-02', 'period_end' => '2026-10-02']);
        $this->report($other);

        $response = $this->api('GET', '/api/v1/reports', $token)->assertOk();

        $this->assertSame([$newest->id, $reviewed->id, $older->id], array_column($response->json('data'), 'id'));
        $response->assertJsonPath('meta', ['per_page' => 20, 'next_cursor' => null, 'has_more' => false]);

        $response->assertJsonPath('data.1', [
            'id' => $reviewed->id,
            'type' => 'daily',
            'period_start' => '2026-09-30',
            'period_end' => '2026-09-30',
            'content' => $reviewed->content,
            'status' => 'reviewed',
            'reviewer_comment' => 'Good work.',
            'reviewer' => ['id' => $coordinator->id, 'name' => 'Rita Reviewer'],
            'reviewed_at' => '2026-10-01T09:12:00+08:00',
            'attachment' => null,
            'created_at' => '2026-10-03T08:15:00+08:00',
            'updated_at' => '2026-10-03T08:15:00+08:00',
            'can_edit' => false,
            'can_delete' => false,
        ]);

        $fingerprint = substr(sha1($newest->attachment_path), 0, 12);
        $response->assertJsonPath('data.0.can_edit', true)
            ->assertJsonPath('data.0.can_delete', true)
            ->assertJsonPath('data.0.reviewer', null)
            ->assertJsonPath('data.0.attachment.name', 'proof.pdf')
            ->assertJsonPath('data.0.attachment.mime', 'application/pdf')
            ->assertJsonPath('data.0.attachment.kind', 'pdf')
            ->assertJsonPath('data.0.attachment.size', Storage::disk('local')->size($newest->attachment_path))
            ->assertJsonPath('data.0.attachment.url', "http://localhost/api/v1/reports/{$newest->id}/attachment?v={$fingerprint}");
    }

    public function test_a_reviewed_report_whose_reviewer_was_deleted_has_a_null_reviewer(): void
    {
        $coordinator = $this->user(2);
        [, $token, $student] = $this->student();
        $report = $this->report($student, ['status' => 'reviewed', 'reviewed_by' => $coordinator->id, 'reviewed_at' => now()]);

        $coordinator->delete();

        $this->api('GET', "/api/v1/reports/{$report->id}", $token)
            ->assertOk()
            ->assertJsonPath('report.reviewer', null)
            ->assertJsonPath('report.status', 'reviewed');
    }

    public function test_an_attachment_missing_from_disk_is_reported_as_null(): void
    {
        [, $token, $student] = $this->student();
        $report = $this->report($student, ['attachment_path' => 'report-attachments/gone.pdf', 'attachment_original_name' => 'gone.pdf']);

        $this->api('GET', "/api/v1/reports/{$report->id}", $token)->assertOk()->assertJsonPath('report.attachment', null);
    }

    public function test_list_filters_by_type_and_status(): void
    {
        [, $token, $student] = $this->student();
        $dailyPending = $this->report($student, ['type' => 'daily', 'status' => 'pending']);
        $dailyReviewed = $this->report($student, ['type' => 'daily', 'status' => 'reviewed']);
        $weeklyPending = $this->report($student, ['type' => 'weekly', 'status' => 'pending']);

        $ids = fn (array $query) => collect($this->api('GET', '/api/v1/reports', $token, $query)->assertOk()->json('data'))->pluck('id')->sort()->values()->all();

        $this->assertSame(collect([$dailyPending->id, $dailyReviewed->id])->sort()->values()->all(), $ids(['type' => 'daily']));
        $this->assertSame([$weeklyPending->id], $ids(['type' => 'weekly']));
        $this->assertSame(collect([$dailyPending->id, $weeklyPending->id])->sort()->values()->all(), $ids(['status' => 'pending']));
        $this->assertSame([$dailyReviewed->id], $ids(['type' => 'daily', 'status' => 'reviewed']));
        $this->assertCount(3, $ids(['type' => '', 'status' => '']));
    }

    public function test_list_paginates_with_a_cursor_without_repeats(): void
    {
        [, $token, $student] = $this->student();

        // Two reports share each period_start, so the id tiebreak matters.
        foreach (range(1, 5) as $day) {
            $this->report($student, ['type' => 'daily', 'period_start' => "2026-09-0{$day}", 'period_end' => "2026-09-0{$day}"]);
            $this->report($student, ['type' => 'weekly', 'period_start' => "2026-09-0{$day}", 'period_end' => "2026-09-0{$day}"]);
        }

        $seen = [];
        $cursor = null;
        $pages = 0;

        do {
            $response = $this->api('GET', '/api/v1/reports', $token, array_filter(['per_page' => 3, 'cursor' => $cursor]))->assertOk();
            $seen = [...$seen, ...array_column($response->json('data'), 'id')];
            $cursor = $response->json('meta.next_cursor');
            $pages++;
        } while ($response->json('meta.has_more'));

        $this->assertSame(4, $pages);
        $this->assertNull($cursor);
        $this->assertSame(
            InternshipReport::orderByDesc('period_start')->orderByDesc('id')->pluck('id')->all(),
            $seen,
        );
    }

    public function test_list_rejects_bad_filters_page_sizes_and_cursors(): void
    {
        [, $token] = $this->student();

        $foreign = rtrim(strtr(base64_encode(json_encode(['created_at' => '2026-10-01 00:00:00', 'id' => 1, '_pointsToNextItems' => true])), '+/', '-_'), '=');
        $badDate = rtrim(strtr(base64_encode(json_encode(['period_start' => '2026-02-30 00:00:00', 'id' => 1, '_pointsToNextItems' => true])), '+/', '-_'), '=');

        foreach ([
            ['type' => 'monthly'],
            ['status' => 'approved'],
            ['per_page' => 0],
            ['per_page' => 51],
            ['cursor' => 'garbage'],
            ['cursor' => $foreign],
            ['cursor' => $badDate],
        ] as $query) {
            $this->api('GET', '/api/v1/reports', $token, $query)
                ->assertStatus(422)
                ->assertJsonValidationErrors(array_keys($query));
        }
    }

    public function test_show_returns_the_report(): void
    {
        [, $token, $student] = $this->student();
        $report = $this->report($student);

        $this->api('GET', "/api/v1/reports/{$report->id}", $token)
            ->assertOk()
            ->assertJsonPath('report.id', $report->id)
            ->assertJsonPath('report.can_edit', true);
    }

    // ---------------------------------------------------------- create

    public function test_create_stores_the_report_and_its_attachment_and_notifies_staff_and_the_supervisor(): void
    {
        $supervisor = $this->user(3);
        $otherSupervisor = $this->user(3);
        $coordinator = $this->user(2);
        $admin = $this->user(4);
        [$user, $token, $student] = $this->student($supervisor);

        $response = $this->api('POST', '/api/v1/reports', $token, $this->payload([
            'attachment' => $this->realFile('Proof Of Work.pdf', $this->minimalPdf()),
        ]));

        $response->assertCreated()
            ->assertJsonPath('message', 'Report submitted for review.')
            ->assertJsonPath('report.type', 'daily')
            ->assertJsonPath('report.period_start', '2026-10-02')
            ->assertJsonPath('report.status', 'pending')
            ->assertJsonPath('report.can_edit', true)
            ->assertJsonPath('report.attachment.name', 'Proof Of Work.pdf')
            ->assertJsonPath('report.attachment.kind', 'pdf');

        $report = InternshipReport::findOrFail($response->json('report.id'));
        $this->assertSame($student->id, $report->student_id);
        $this->assertStringStartsWith('report-attachments/', $report->attachment_path);
        $this->assertStringEndsWith('.pdf', $report->attachment_path);
        Storage::disk('local')->assertExists($report->attachment_path);

        foreach ([$supervisor, $coordinator, $admin] as $recipient) {
            $this->assertSame(1, Notification::where('user_id', $recipient->id)->where('type', 'report_submitted')->count());
        }
        $this->assertSame(0, Notification::where('user_id', $otherSupervisor->id)->count());
        $this->assertSame(0, Notification::where('user_id', $user->id)->count());
    }

    public function test_create_accepts_png_and_jpeg_images(): void
    {
        [, $token] = $this->student();

        $this->api('POST', '/api/v1/reports', $token, $this->payload([
            'attachment' => UploadedFile::fake()->image('site.png', 1200, 800),
        ]))->assertCreated()->assertJsonPath('report.attachment.kind', 'image')->assertJsonPath('report.attachment.mime', 'image/png');

        $this->api('POST', '/api/v1/reports', $token, $this->payload([
            'type' => 'weekly', 'period_start' => '2026-09-28', 'period_end' => '2026-10-02',
            'attachment' => UploadedFile::fake()->image('photo.jpeg', 640, 480),
        ]))->assertCreated()->assertJsonPath('report.attachment.mime', 'image/jpeg')->assertJsonPath('report.attachment.name', 'photo.jpeg');
    }

    public static function invalidPayloads(): array
    {
        return [
            'missing everything' => [['type' => null, 'period_start' => null, 'period_end' => null, 'content' => null], ['type', 'period_start', 'period_end', 'content']],
            'unknown type' => [['type' => 'monthly'], ['type']],
            'array type' => [['type' => ['daily']], ['type']],
            'array period start' => [['period_start' => ['2026-10-02']], ['period_start']],
            'array period end' => [['period_end' => ['2026-10-02']], ['period_end']],
            'array period start and end' => [['type' => 'weekly', 'period_start' => ['2026-10-02'], 'period_end' => ['2026-10-02']], ['period_start', 'period_end']],
            'array content' => [['content' => ['x']], ['content']],
            'non ISO date' => [['period_start' => '10/02/2026', 'period_end' => '10/02/2026'], ['period_start', 'period_end']],
            'date with time' => [['period_start' => '2026-10-02 13:00', 'period_end' => '2026-10-02 13:00'], ['period_start', 'period_end']],
            'impossible date' => [['period_start' => '2026-02-30', 'period_end' => '2026-02-30'], ['period_start', 'period_end']],
            'end before start' => [['type' => 'weekly', 'period_start' => '2026-10-02', 'period_end' => '2026-09-28'], ['period_end']],
            'daily spanning days' => [['period_start' => '2026-09-28', 'period_end' => '2026-10-02'], ['period_end']],
            'empty content' => [['content' => ''], ['content']],
            'content too long' => [['content' => str_repeat('a', InternshipReportService::MAX_CONTENT_LENGTH + 1)], ['content']],
            'attachment not a file' => [['attachment' => 'not-a-file'], ['attachment']],
        ];
    }

    #[DataProvider('invalidPayloads')]
    public function test_create_validates_fields_and_stores_nothing(array $overrides, array $errorKeys): void
    {
        [, $token] = $this->student($this->user(3));

        $this->api('POST', '/api/v1/reports', $token, $this->payload($overrides))
            ->assertStatus(422)
            ->assertJsonValidationErrors($errorKeys);

        $this->assertSame(0, InternshipReport::count());
        $this->assertSame(0, Notification::count());
    }

    public function test_array_dates_are_a_422_not_a_500_on_json_create_and_multipart_update(): void
    {
        [, $token, $student] = $this->student();
        $report = $this->report($student);

        $this->api('POST', '/api/v1/reports', $token, $this->payload(['period_start' => ['2026-10-02']]), json: true)
            ->assertStatus(422)
            ->assertJsonValidationErrors(['period_start']);

        $this->update($report, $token, $this->payload(['period_start' => ['2026-10-02']]))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['period_start']);

        $this->update($report, $token, $this->payload(['period_end' => ['2026-10-02']]))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['period_end']);

        $this->assertSame(1, InternshipReport::count());
    }

    public function test_content_at_the_limit_with_multibyte_characters_is_stored_intact(): void
    {
        [, $token] = $this->student();
        $content = str_repeat('😀', InternshipReportService::MAX_CONTENT_LENGTH);

        $response = $this->api('POST', '/api/v1/reports', $token, $this->payload(['content' => $content]))->assertCreated();

        $this->assertSame($content, InternshipReport::findOrFail($response->json('report.id'))->content);
    }

    public static function abusiveUploads(): array
    {
        return [
            'svg image' => ['logo.svg', '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>'],
            'svg renamed to png' => ['logo.png', '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>'],
            'html renamed to pdf' => ['report.pdf', '<!DOCTYPE html><html><body><script>alert(1)</script></body></html>'],
            'html renamed to jpg' => ['photo.jpg', '<html><script>alert(1)</script></html>'],
            'php script renamed to jpg' => ['shell.jpg', '<?php echo shell_exec($_GET["c"]); ?>'],
            'plain text' => ['notes.txt', 'just some notes'],
            'zip archive' => ['bundle.pdf', "PK\x03\x04".str_repeat("\0", 60)],
        ];
    }

    #[DataProvider('abusiveUploads')]
    public function test_disguised_or_active_content_is_rejected(string $name, string $bytes): void
    {
        [, $token] = $this->student();

        $this->api('POST', '/api/v1/reports', $token, $this->payload(['attachment' => $this->realFile($name, $bytes)]))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['attachment']);

        $this->assertSame([], Storage::disk('local')->allFiles());
        $this->assertSame(0, InternshipReport::count());
    }

    public function test_oversized_attachments_are_rejected(): void
    {
        [, $token] = $this->student();

        $this->api('POST', '/api/v1/reports', $token, $this->payload([
            'attachment' => UploadedFile::fake()->create('big.pdf', InternshipReportService::MAX_ATTACHMENT_SIZE + 1, 'application/pdf'),
        ]))->assertStatus(422)->assertJsonValidationErrors(['attachment']);

        $this->api('POST', '/api/v1/reports', $token, $this->payload([
            'attachment' => UploadedFile::fake()->image('wide.png', InternshipReportService::MAX_IMAGE_DIMENSION + 1, 1),
        ]))->assertStatus(422)->assertJsonPath('errors.attachment.0', 'The attachment may not be larger than 8000 x 8000 pixels.');

        $this->api('POST', '/api/v1/reports', $token, $this->payload([
            'attachment' => UploadedFile::fake()->create('broken.jpg', 10, 'image/jpeg'),
        ]))->assertStatus(422)->assertJsonPath('errors.attachment.0', 'The attachment must be a valid image.');

        // At the limits it is accepted.
        $this->api('POST', '/api/v1/reports', $token, $this->payload([
            'attachment' => UploadedFile::fake()->image('tall.png', 1, InternshipReportService::MAX_IMAGE_DIMENSION),
        ]))->assertCreated();

        $this->assertSame(1, InternshipReport::count());
        $this->assertCount(1, Storage::disk('local')->allFiles());
    }

    public function test_stored_file_names_are_sanitised(): void
    {
        [, $token] = $this->student();

        $cases = [
            ["evil\r\nSet-Cookie: x.pdf", 'evilSet-Cookie: x.pdf'],
            ['notes.html', 'notes.pdf'],
            ['scan', 'scan.pdf'],
            ['.pdf', 'attachment.pdf'],
            ['Report.PDF', 'Report.pdf'],
            [str_repeat('é', 300).'.pdf', str_repeat('é', 251).'.pdf'],
        ];

        foreach ($cases as $i => [$clientName, $expected]) {
            $response = $this->api('POST', '/api/v1/reports', $token, $this->payload([
                'period_start' => '2026-09-0'.($i + 1), 'period_end' => '2026-09-0'.($i + 1),
                'attachment' => $this->realFile($clientName, $this->minimalPdf()),
            ]))->assertCreated();

            $this->assertSame($expected, $response->json('report.attachment.name'), $clientName);
        }
    }

    public function test_invalid_utf8_is_a_422(): void
    {
        [, $token] = $this->student();

        $this->api('POST', '/api/v1/reports', $token, $this->payload(['content' => "bad \xC3\x28 bytes"]))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['content']);

        $this->assertSame(0, InternshipReport::count());
    }

    public function test_a_duplicate_type_and_period_is_a_422_on_period_start(): void
    {
        [, $token, $student] = $this->student();
        $this->report($student, ['type' => 'daily', 'period_start' => '2026-10-02', 'period_end' => '2026-10-02']);

        $this->api('POST', '/api/v1/reports', $token, $this->payload([
            'attachment' => $this->realFile('proof.pdf', $this->minimalPdf()),
        ]))->assertStatus(422)
            ->assertJsonPath('errors.period_start.0', "You've already submitted a daily report for this period.");

        // Another type for the same date is fine.
        $this->api('POST', '/api/v1/reports', $token, $this->payload([
            'type' => 'weekly', 'period_end' => '2026-10-06',
        ]))->assertCreated();

        $this->assertSame([], Storage::disk('local')->allFiles());
        $this->assertSame(2, InternshipReport::count());
    }

    public function test_racing_creates_hit_the_unique_index_as_a_clean_422(): void
    {
        $supervisor = $this->user(3);
        [, $token, $student] = $this->student($supervisor);

        // Another request inserts the same report between the duplicate
        // check and this insert.
        $raced = false;
        InternshipReport::creating(function () use (&$raced, $student) {
            if ($raced) {
                return;
            }

            $raced = true;
            DB::table('internship_reports')->insert([
                'student_id' => $student->id,
                'type' => 'daily',
                'period_start' => '2026-10-02',
                'period_end' => '2026-10-02',
                'content' => 'First',
                'status' => 'pending',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        });

        $this->api('POST', '/api/v1/reports', $token, $this->payload([
            'attachment' => $this->realFile('proof.pdf', $this->minimalPdf()),
        ]))->assertStatus(422)
            ->assertJsonPath('errors.period_start.0', "You've already submitted a daily report for this period.");

        $this->assertTrue($raced);
        $this->assertSame(['First'], InternshipReport::pluck('content')->all());
        $this->assertSame([], Storage::disk('local')->allFiles());
        $this->assertSame(0, Notification::count());
    }

    // ---------------------------------------------------------- update

    public function test_update_via_method_spoofed_post_replaces_fields_and_attachment(): void
    {
        [, $token, $student] = $this->student($this->user(3));
        $report = $this->withAttachment($student, ['type' => 'daily', 'period_start' => '2026-10-01', 'period_end' => '2026-10-01']);
        $oldPath = $report->attachment_path;
        $oldUrl = $this->api('GET', "/api/v1/reports/{$report->id}", $token)->json('report.attachment.url');
        Notification::query()->delete();

        $response = $this->update($report, $token, $this->payload([
            'type' => 'weekly', 'period_start' => '2026-09-28', 'period_end' => '2026-10-02',
            'content' => 'Updated week.',
            'attachment' => UploadedFile::fake()->image('new.png', 100, 100),
        ]))->assertOk()
            ->assertJsonPath('message', 'Report updated.')
            ->assertJsonPath('report.type', 'weekly')
            ->assertJsonPath('report.period_end', '2026-10-02')
            ->assertJsonPath('report.content', 'Updated week.')
            ->assertJsonPath('report.attachment.name', 'new.png')
            ->assertJsonPath('report.attachment.kind', 'image');

        $this->assertNotSame($oldUrl, $response->json('report.attachment.url'));

        $report->refresh();
        Storage::disk('local')->assertMissing($oldPath);
        Storage::disk('local')->assertExists($report->attachment_path);
        $this->assertCount(1, Storage::disk('local')->allFiles());
        $this->assertSame(0, Notification::count(), 'Editing does not notify (website parity).');
    }

    public function test_update_without_a_file_keeps_the_attachment(): void
    {
        [, $token, $student] = $this->student();
        $report = $this->withAttachment($student);

        $this->update($report, $token, $this->payload(['content' => 'Only text changed.']))
            ->assertOk()
            ->assertJsonPath('report.attachment.name', 'proof.pdf');

        Storage::disk('local')->assertExists($report->attachment_path);
        $this->assertSame($report->attachment_path, $report->fresh()->attachment_path);
    }

    public function test_remove_attachment_drops_the_file(): void
    {
        [, $token, $student] = $this->student();
        $report = $this->withAttachment($student);

        $this->update($report, $token, $this->payload(['remove_attachment' => 'true']))
            ->assertOk()
            ->assertJsonPath('report.attachment', null);

        $this->assertNull($report->fresh()->attachment_path);
        $this->assertNull($report->fresh()->attachment_original_name);
        $this->assertSame([], Storage::disk('local')->allFiles());
    }

    public function test_remove_attachment_false_keeps_it_and_invalid_values_are_422(): void
    {
        [, $token, $student] = $this->student();
        $report = $this->withAttachment($student);

        $this->update($report, $token, $this->payload(['remove_attachment' => '0']))
            ->assertOk()->assertJsonPath('report.attachment.name', 'proof.pdf');

        $this->update($report, $token, $this->payload(['remove_attachment' => 'yes']))
            ->assertStatus(422)->assertJsonValidationErrors(['remove_attachment']);
    }

    public function test_remove_attachment_accepts_json_booleans_and_numbers(): void
    {
        [, $token, $student] = $this->student();
        $report = $this->withAttachment($student);
        $patch = fn (mixed $value) => $this->api('PATCH', "/api/v1/reports/{$report->id}", $token, $this->payload(['remove_attachment' => $value]), json: true);

        foreach ([false, 0, '0', 'false', null, ''] as $keep) {
            $patch($keep)->assertOk()->assertJsonPath('report.attachment.name', 'proof.pdf');
        }

        foreach ([2, 'yes', ['1']] as $invalid) {
            $patch($invalid)->assertStatus(422)->assertJsonValidationErrors(['remove_attachment']);
        }

        $patch(true)->assertOk()->assertJsonPath('report.attachment', null);
        $this->assertSame([], Storage::disk('local')->allFiles());
    }

    public function test_remove_attachment_json_one_removes(): void
    {
        [, $token, $student] = $this->student();
        $report = $this->withAttachment($student);

        $this->api('PATCH', "/api/v1/reports/{$report->id}", $token, $this->payload(['remove_attachment' => 1]), json: true)
            ->assertOk()->assertJsonPath('report.attachment', null);
    }

    public function test_uploading_and_removing_at_once_is_a_422(): void
    {
        [, $token, $student] = $this->student();
        $report = $this->withAttachment($student);

        $this->update($report, $token, $this->payload([
            'remove_attachment' => '1',
            'attachment' => UploadedFile::fake()->image('new.png', 10, 10),
        ]))->assertStatus(422)->assertJsonValidationErrors(['remove_attachment']);

        $this->assertSame([$report->attachment_path], Storage::disk('local')->allFiles());
    }

    public function test_a_real_patch_with_a_json_body_works(): void
    {
        [, $token, $student] = $this->student();
        $report = $this->withAttachment($student);

        $this->api('PATCH', "/api/v1/reports/{$report->id}", $token, $this->payload(['content' => 'Via JSON.']), json: true)
            ->assertOk()
            ->assertJsonPath('report.content', 'Via JSON.')
            ->assertJsonPath('report.attachment.name', 'proof.pdf');
    }

    public function test_update_validates_like_create(): void
    {
        [, $token, $student] = $this->student();
        $report = $this->report($student);

        $this->update($report, $token, $this->payload(['type' => 'daily', 'period_start' => '2026-09-28', 'period_end' => '2026-10-02', 'content' => '']))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['period_end', 'content']);

        $this->update($report, $token, $this->payload(['attachment' => $this->realFile('x.png', '<svg xmlns="http://www.w3.org/2000/svg"/>')]))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['attachment']);

        $this->assertSame([], Storage::disk('local')->allFiles());
    }

    public function test_update_into_another_reports_period_is_a_duplicate_422(): void
    {
        [, $token, $student] = $this->student();
        $this->report($student, ['type' => 'daily', 'period_start' => '2026-10-02', 'period_end' => '2026-10-02']);
        $report = $this->report($student, ['type' => 'daily', 'period_start' => '2026-10-01', 'period_end' => '2026-10-01']);

        $this->update($report, $token, $this->payload())
            ->assertStatus(422)
            ->assertJsonPath('errors.period_start.0', "You've already submitted a daily report for this period.");

        // Keeping its own period is fine.
        $this->update($report, $token, $this->payload(['period_start' => '2026-10-01', 'period_end' => '2026-10-01']))->assertOk();
    }

    public function test_a_reviewed_report_cannot_be_updated(): void
    {
        [, $token, $student] = $this->student();
        $report = $this->withAttachment($student, ['status' => 'reviewed', 'reviewer_comment' => 'Fine.', 'reviewed_at' => now()]);

        $this->update($report, $token, $this->payload([
            'content' => 'Sneaky edit',
            'attachment' => UploadedFile::fake()->image('new.png', 10, 10),
        ]))->assertStatus(422)
            ->assertJsonPath('code', 'report_not_pending')
            ->assertJsonPath('message', self::NOT_PENDING)
            ->assertJsonPath('report.id', $report->id)
            ->assertJsonPath('report.status', 'reviewed')
            ->assertJsonPath('report.can_edit', false)
            ->assertJsonMissingPath('errors');

        $this->assertSame($report->content, $report->fresh()->content);
        $this->assertSame([$report->attachment_path], Storage::disk('local')->allFiles());
    }

    public function test_a_review_that_lands_during_the_update_wins(): void
    {
        [, $token, $student] = $this->student();
        $report = $this->withAttachment($student);

        // The reviewer commits right after the controller loaded the
        // (still pending) report, before the service's locked re-check.
        $this->reviewRightAfterTheReportIsLoaded($report);

        $this->update($report, $token, $this->payload([
            'content' => 'Too late',
            'attachment' => UploadedFile::fake()->image('new.png', 10, 10),
        ]))->assertStatus(422)
            ->assertJsonPath('code', 'report_not_pending')
            ->assertJsonPath('report.status', 'reviewed');

        $this->assertNotSame('Too late', $report->fresh()->content);
        $this->assertSame([$report->attachment_path], Storage::disk('local')->allFiles());
    }

    // ---------------------------------------------------------- delete

    public function test_delete_removes_the_report_and_its_file(): void
    {
        [, $token, $student] = $this->student();
        $report = $this->withAttachment($student);

        $this->api('DELETE', "/api/v1/reports/{$report->id}", $token)
            ->assertOk()
            ->assertExactJson(['message' => 'Report deleted.']);

        $this->assertModelMissing($report);
        $this->assertSame([], Storage::disk('local')->allFiles());

        $this->api('DELETE', "/api/v1/reports/{$report->id}", $token)->assertNotFound();
    }

    public function test_a_reviewed_report_cannot_be_deleted(): void
    {
        [, $token, $student] = $this->student();
        $report = $this->withAttachment($student, ['status' => 'reviewed', 'reviewed_at' => now()]);

        $this->api('DELETE', "/api/v1/reports/{$report->id}", $token)
            ->assertStatus(422)
            ->assertJsonPath('code', 'report_not_pending')
            ->assertJsonPath('report.can_delete', false);

        $this->assertModelExists($report);
        Storage::disk('local')->assertExists($report->attachment_path);
    }

    public function test_a_review_that_lands_during_the_delete_wins(): void
    {
        [, $token, $student] = $this->student();
        $report = $this->withAttachment($student);

        $this->reviewRightAfterTheReportIsLoaded($report);

        $this->api('DELETE', "/api/v1/reports/{$report->id}", $token)
            ->assertStatus(422)
            ->assertJsonPath('code', 'report_not_pending');

        $this->assertModelExists($report);
        Storage::disk('local')->assertExists($report->attachment_path);
    }

    public function test_a_delete_that_lands_first_makes_the_other_a_404(): void
    {
        [, $token, $student] = $this->student();
        $report = $this->withAttachment($student);

        $landed = false;
        DB::listen(function ($query) use (&$landed, $report) {
            if ($landed || ! str_contains($query->sql, 'from `internship_reports`')) {
                return;
            }

            $landed = true;
            DB::table('internship_reports')->where('id', $report->id)->delete();
        });

        $this->api('DELETE', "/api/v1/reports/{$report->id}", $token)->assertNotFound();
        $this->assertTrue($landed);
    }

    private function reviewRightAfterTheReportIsLoaded(InternshipReport $report): void
    {
        $reviewer = $this->user(2);
        $landed = false;

        DB::listen(function ($query) use (&$landed, $report, $reviewer) {
            if ($landed || ! str_contains($query->sql, 'from `internship_reports`')) {
                return;
            }

            $landed = true;
            DB::table('internship_reports')->where('id', $report->id)->update([
                'status' => 'reviewed', 'reviewer_comment' => 'Reviewed first', 'reviewed_by' => $reviewer->id, 'reviewed_at' => now(),
            ]);
        });
    }

    // ---------------------------------------------------------- attachment download

    public function test_the_owner_downloads_the_attachment_with_safe_headers(): void
    {
        [, $token, $student] = $this->student();
        $report = $this->withAttachment($student, [], 'Week 3 report.pdf');

        $response = $this->api('GET', "/api/v1/reports/{$report->id}/attachment", $token, ['v' => 'anything'])->assertOk();

        $response->assertHeader('Content-Type', 'application/pdf');
        $this->assertStringContainsString('inline; filename="Week 3 report.pdf"', $response->headers->get('Content-Disposition'));
        $this->assertStringContainsString('private', $response->headers->get('Cache-Control'));
        $this->assertStringContainsString('max-age=86400', $response->headers->get('Cache-Control'));
        $response->assertHeader('X-Content-Type-Options', 'nosniff');
        $response->assertHeader('Content-Security-Policy', "default-src 'none'; sandbox");
        $this->assertSame(Storage::disk('local')->get($report->attachment_path), $response->streamedContent());
    }

    public function test_a_unicode_file_name_survives_the_download_header(): void
    {
        [, $token] = $this->student();

        $response = $this->api('POST', '/api/v1/reports', $token, $this->payload([
            'attachment' => $this->realFile('Ulat ng Linggo – ñ 😀.pdf', $this->minimalPdf()),
        ]))->assertCreated();

        $download = $this->api('GET', "/api/v1/reports/{$response->json('report.id')}/attachment", $token)->assertOk();

        $this->assertStringContainsString("filename*=utf-8''", $download->headers->get('Content-Disposition'));
        $this->assertStringContainsString(rawurlencode('Ulat ng Linggo – ñ 😀.pdf'), $download->headers->get('Content-Disposition'));
    }

    public function test_attachment_access_follows_the_website_policy_for_every_role(): void
    {
        $supervisor = $this->user(3);
        [$owner, , $student] = $this->student($supervisor);
        [$otherStudent] = $this->student();
        $report = $this->withAttachment($student, ['status' => 'reviewed', 'reviewed_at' => now()]);
        $url = "/api/v1/reports/{$report->id}/attachment";

        $this->api('GET', $url, $this->token($owner))->assertOk();
        $this->api('GET', $url, $this->token($supervisor))->assertOk();
        $this->api('GET', $url, $this->token($this->user(2)))->assertOk();
        $this->api('GET', $url, $this->token($this->user(4)))->assertOk();

        $this->api('GET', $url, $this->token($otherStudent))->assertNotFound();
        $this->api('GET', $url, $this->token($this->user(3)))->assertNotFound();
        $this->api('GET', $url, $this->token($this->user(1)))->assertNotFound();
    }

    public function test_reports_without_a_file_or_with_a_missing_file_404(): void
    {
        [, $token, $student] = $this->student();
        $none = $this->report($student);
        $gone = $this->report($student, ['attachment_path' => 'report-attachments/gone.pdf', 'attachment_original_name' => 'gone.pdf']);

        $this->api('GET', "/api/v1/reports/{$none->id}/attachment", $token)->assertNotFound();
        $this->api('GET', "/api/v1/reports/{$gone->id}/attachment", $token)->assertNotFound();
    }

    // ---------------------------------------------------------- web parity

    public function test_api_and_website_store_identical_reports_and_notifications_for_the_same_input(): void
    {
        $supervisor = $this->user(3);
        $this->user(2);
        [$webUser, , $webStudent] = $this->student($supervisor);
        [, $apiToken, $apiStudent] = $this->student($supervisor);

        $input = $this->payload(['type' => 'weekly', 'period_start' => '2026-09-28', 'period_end' => '2026-10-02']);

        $this->actingAs($webUser)->post('/my-reports', [...$input, 'attachment' => $this->realFile('proof.pdf', $this->minimalPdf())])
            ->assertRedirect(route('reports.index', absolute: false));
        $this->app['auth']->forgetGuards();

        $this->api('POST', '/api/v1/reports', $apiToken, [...$input, 'attachment' => $this->realFile('proof.pdf', $this->minimalPdf())])
            ->assertCreated();

        $columns = ['type', 'period_start', 'period_end', 'content', 'attachment_original_name', 'status', 'reviewer_comment', 'reviewed_by', 'reviewed_at'];
        $web = InternshipReport::where('student_id', $webStudent->id)->firstOrFail();
        $api = InternshipReport::where('student_id', $apiStudent->id)->firstOrFail();

        $this->assertSame(
            array_intersect_key($web->getAttributes(), array_flip($columns)),
            array_intersect_key($api->getAttributes(), array_flip($columns)),
        );
        $this->assertSame(
            Storage::disk('local')->get($web->attachment_path),
            Storage::disk('local')->get($api->attachment_path),
        );

        $notifications = Notification::where('type', 'report_submitted')->orderBy('id')->get(['user_id', 'data']);
        $this->assertSame(
            $notifications->where('data.report_id', $web->id)->pluck('user_id')->sort()->values()->all(),
            $notifications->where('data.report_id', $api->id)->pluck('user_id')->sort()->values()->all(),
        );
        $this->assertCount(2, $notifications->where('data.report_id', $api->id));
    }

    public function test_api_and_website_reject_the_same_invalid_input_with_the_same_keys(): void
    {
        [$webUser] = $this->student();
        [, $apiToken] = $this->student();

        $input = $this->payload(['type' => 'daily', 'period_start' => '2026-09-28', 'period_end' => '2026-10-02', 'content' => '']);

        $web = $this->actingAs($webUser)->from('/my-reports')->post('/my-reports', $input);
        $webErrors = array_keys(session('errors')->getBag('default')->messages());
        $this->app['auth']->forgetGuards();

        $apiErrors = array_keys($this->api('POST', '/api/v1/reports', $apiToken, $input)->assertStatus(422)->json('errors'));

        $web->assertRedirect('/my-reports');
        sort($webErrors);
        sort($apiErrors);
        $this->assertSame(['content', 'period_end'], $apiErrors);
        $this->assertSame($webErrors, $apiErrors);
    }

    public function test_a_report_from_the_app_reviewed_on_the_website_shows_the_comment_in_the_app(): void
    {
        $coordinator = $this->user(2);
        [$user, $token] = $this->student();

        $id = $this->api('POST', '/api/v1/reports', $token, $this->payload())->assertCreated()->json('report.id');

        $this->actingAs($coordinator)->patch("/report-reviews/{$id}", ['comment' => 'Nice detail.'])->assertRedirect();
        $this->app['auth']->forgetGuards();

        $this->api('GET', "/api/v1/reports/{$id}", $token)
            ->assertOk()
            ->assertJsonPath('report.status', 'reviewed')
            ->assertJsonPath('report.reviewer_comment', 'Nice detail.')
            ->assertJsonPath('report.reviewer.id', $coordinator->id)
            ->assertJsonPath('report.can_edit', false);

        $notification = Notification::where('user_id', $user->id)->where('type', 'report_reviewed')->sole();
        $this->api('GET', '/api/v1/notifications', $token)
            ->assertOk()
            ->assertJsonPath('data.0.id', $notification->id)
            ->assertJsonPath('data.0.target', ['screen' => 'my-reports', 'params' => ['report_id' => $id]]);
    }
}
