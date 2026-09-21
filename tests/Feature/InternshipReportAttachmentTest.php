<?php

namespace Tests\Feature;

use App\Models\InternshipReport;
use App\Models\Student;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Regression coverage for Finding 5: report attachments were stored on the
 * public disk and linked to directly via /storage/..., so anyone with the
 * (unguessable but leakable) URL could download them with zero
 * authentication, bypassing every ownership/scoping check below. Attachments
 * now live on the private `local` disk and are only ever served through
 * these authenticated, authorized download routes.
 */
class InternshipReportAttachmentTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);
        Storage::fake('local');
    }

    private function reportWithAttachment(Student $student, array $overrides = []): InternshipReport
    {
        $path = UploadedFile::fake()
            ->create('proof.pdf', 100, 'application/pdf')
            ->store('report-attachments', 'local');

        return InternshipReport::factory()->create(array_merge([
            'student_id' => $student->id,
            'attachment_path' => $path,
            'attachment_original_name' => 'proof.pdf',
        ], $overrides));
    }

    public function test_uploading_an_attachment_stores_it_on_the_private_local_disk_not_public(): void
    {
        Storage::fake('public');

        $user = User::factory()->create(['role_id' => 1]);
        $student = Student::factory()->create(['user_id' => $user->id]);

        $file = UploadedFile::fake()->create('proof.pdf', 100, 'application/pdf');

        $this->actingAs($user)->post('/my-reports', [
            'type' => 'daily',
            'period_start' => '2026-01-05',
            'period_end' => '2026-01-05',
            'content' => 'Worked on the reporting module today.',
            'attachment' => $file,
        ]);

        $report = InternshipReport::where('student_id', $student->id)->firstOrFail();

        Storage::disk('local')->assertExists($report->attachment_path);
        Storage::disk('public')->assertMissing($report->attachment_path);
    }

    public function test_student_can_download_their_own_report_attachment(): void
    {
        $user = User::factory()->create(['role_id' => 1]);
        $student = Student::factory()->create(['user_id' => $user->id]);
        $report = $this->reportWithAttachment($student);

        $response = $this->actingAs($user)->get(route('reports.attachment', $report->id));

        $response->assertOk();
        $response->assertHeader('content-type', 'application/pdf');
    }

    public function test_student_cannot_download_another_students_report_attachment(): void
    {
        $user = User::factory()->create(['role_id' => 1]);
        Student::factory()->create(['user_id' => $user->id]);

        $otherStudent = Student::factory()->create();
        $report = $this->reportWithAttachment($otherStudent);

        $this->actingAs($user)
            ->get(route('reports.attachment', $report->id))
            ->assertForbidden();
    }

    public function test_guest_cannot_download_a_report_attachment(): void
    {
        $student = Student::factory()->create();
        $report = $this->reportWithAttachment($student);

        $this->get(route('reports.attachment', $report->id))
            ->assertRedirect(route('login', absolute: false));
    }

    public function test_downloading_a_report_with_no_attachment_404s(): void
    {
        $user = User::factory()->create(['role_id' => 1]);
        $student = Student::factory()->create(['user_id' => $user->id]);
        $report = InternshipReport::factory()->create(['student_id' => $student->id]);

        $this->actingAs($user)
            ->get(route('reports.attachment', $report->id))
            ->assertNotFound();
    }

    public function test_supervisor_can_download_their_own_students_report_attachment(): void
    {
        $supervisor = User::factory()->create(['role_id' => 3]);
        $student = Student::factory()->create(['supervisor_id' => $supervisor->id]);
        $report = $this->reportWithAttachment($student);

        $this->actingAs($supervisor)
            ->get(route('report-reviews.attachment', $report->id))
            ->assertOk();
    }

    public function test_supervisor_cannot_download_another_supervisors_student_report_attachment(): void
    {
        $supervisor = User::factory()->create(['role_id' => 3]);
        $otherSupervisor = User::factory()->create(['role_id' => 3]);
        $otherStudent = Student::factory()->create(['supervisor_id' => $otherSupervisor->id]);
        $report = $this->reportWithAttachment($otherStudent);

        $this->actingAs($supervisor)
            ->get(route('report-reviews.attachment', $report->id))
            ->assertForbidden();
    }

    public function test_coordinator_and_admin_can_download_any_report_attachment(): void
    {
        $coordinator = User::factory()->create(['role_id' => 2]);
        $admin = User::factory()->create(['role_id' => 4]);
        $student = Student::factory()->create();
        $report = $this->reportWithAttachment($student);

        $this->actingAs($coordinator)
            ->get(route('report-reviews.attachment', $report->id))
            ->assertOk();

        $this->actingAs($admin)
            ->get(route('report-reviews.attachment', $report->id))
            ->assertOk();
    }
}
