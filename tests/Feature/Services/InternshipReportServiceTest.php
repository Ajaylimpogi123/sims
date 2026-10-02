<?php

namespace Tests\Feature\Services;

use App\Models\InternshipReport;
use App\Models\Notification;
use App\Models\Student;
use App\Models\User;
use App\Services\InternshipReportService;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * InternshipReportService called directly, the way the /api/v1 controllers
 * will.
 */
class InternshipReportServiceTest extends TestCase
{
    use RefreshDatabase;

    private InternshipReportService $service;

    private Student $student;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);
        Storage::fake(InternshipReportService::ATTACHMENT_DISK);

        $this->service = app(InternshipReportService::class);
        $supervisor = User::factory()->create(['role_id' => User::ROLE_SUPERVISOR]);
        $this->student = Student::factory()->create(['supervisor_id' => $supervisor->id]);
    }

    private function data(array $overrides = []): array
    {
        return array_merge([
            'type' => 'daily',
            'period_start' => '2026-09-01',
            'period_end' => '2026-09-01',
            'content' => 'Did things.',
        ], $overrides);
    }

    public function test_create_stores_a_pending_report_with_its_attachment(): void
    {
        $report = $this->service->create($this->student, $this->data(), UploadedFile::fake()->create('log.pdf', 10, 'application/pdf'));

        $this->assertSame('pending', $report->status);
        $this->assertSame('log.pdf', $report->attachment_original_name);
        Storage::disk(InternshipReportService::ATTACHMENT_DISK)->assertExists($report->attachment_path);
        $this->assertSame(1, Notification::where('type', 'report_submitted')->where('user_id', $this->student->supervisor_id)->count());
    }

    public function test_duplicate_type_and_period_is_a_validation_error(): void
    {
        $this->service->create($this->student, $this->data());

        try {
            $this->service->create($this->student, $this->data(['content' => 'Again']));
            $this->fail('Duplicate report must be rejected.');
        } catch (ValidationException $e) {
            $this->assertSame(
                ["You've already submitted a daily report for this period."],
                $e->errors()['period_start'],
            );
        }

        // A weekly report for the same start date is a different report.
        $this->service->create($this->student, $this->data(['type' => 'weekly']));
        $this->assertSame(2, $this->student->internshipReports()->count());
    }

    public function test_update_replaces_the_attachment_and_keeps_it_when_none_is_sent(): void
    {
        $report = $this->service->create($this->student, $this->data(), UploadedFile::fake()->create('a.pdf', 10, 'application/pdf'));
        $oldPath = $report->attachment_path;

        $this->service->update($report, $this->data(['content' => 'Edited']));
        $this->assertSame($oldPath, $report->fresh()->attachment_path);

        $this->service->update($report, $this->data(), UploadedFile::fake()->create('b.pdf', 10, 'application/pdf'));

        $disk = Storage::disk(InternshipReportService::ATTACHMENT_DISK);
        $disk->assertMissing($oldPath);
        $disk->assertExists($report->fresh()->attachment_path);
        $this->assertSame('b.pdf', $report->fresh()->attachment_original_name);
    }

    public function test_update_may_keep_its_own_period(): void
    {
        $report = $this->service->create($this->student, $this->data());

        $this->service->update($report, $this->data(['content' => 'Same period, new text']));

        $this->assertSame('Same period, new text', $report->fresh()->content);
    }

    public function test_delete_removes_the_attachment(): void
    {
        $report = $this->service->create($this->student, $this->data(), UploadedFile::fake()->create('a.pdf', 10, 'application/pdf'));
        $path = $report->attachment_path;

        $this->service->delete($report);

        $this->assertModelMissing($report);
        Storage::disk(InternshipReportService::ATTACHMENT_DISK)->assertMissing($path);
    }

    public function test_review_marks_the_report_reviewed_and_notifies_the_student(): void
    {
        $coordinator = User::factory()->create(['role_id' => User::ROLE_COORDINATOR]);
        $report = InternshipReport::factory()->create(['student_id' => $this->student->id]);

        $this->service->review($report, $coordinator, 'Good work');

        $report->refresh();
        $this->assertSame('reviewed', $report->status);
        $this->assertSame('Good work', $report->reviewer_comment);
        $this->assertSame($coordinator->id, $report->reviewed_by);
        $this->assertNotNull($report->reviewed_at);
        $this->assertSame(1, Notification::where('user_id', $this->student->user_id)->count());
    }
}
