<?php

namespace Tests\Feature;

use App\Models\InternshipReport;
use App\Models\Student;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * QA M7 BUG-3: legacy report attachments on the public disk, and web
 * downloads of a report whose file is missing.
 */
class SecureReportAttachmentsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);
        Storage::fake('local');
        Storage::fake('public');
    }

    private function legacyReport(string $path, string $bytes = 'legacy'): InternshipReport
    {
        Storage::disk('public')->put($path, $bytes);

        return InternshipReport::factory()->create([
            'attachment_path' => $path,
            'attachment_original_name' => 'scan.jpg',
        ]);
    }

    public function test_dry_run_changes_nothing(): void
    {
        $this->legacyReport('report-attachments/a.jpg');
        Storage::disk('public')->put('report-attachments/orphan.png', 'x');

        $this->artisan('reports:secure-attachments', ['--dry-run' => true, '--delete-orphans' => true])
            ->expectsOutputToContain('[dry run] Moved: 1. Unreferenced: 1 (deleted). Conflicts: 0.')
            ->assertSuccessful();

        Storage::disk('public')->assertExists(['report-attachments/a.jpg', 'report-attachments/orphan.png']);
        Storage::disk('local')->assertMissing('report-attachments/a.jpg');
    }

    public function test_referenced_files_move_to_the_private_disk_and_orphans_stay_unless_asked(): void
    {
        $report = $this->legacyReport('report-attachments/a.jpg', 'jpeg-bytes');
        Storage::disk('public')->put('report-attachments/orphan.png', 'x');

        $this->artisan('reports:secure-attachments')->assertSuccessful();

        Storage::disk('local')->assertExists('report-attachments/a.jpg');
        $this->assertSame('jpeg-bytes', Storage::disk('local')->get('report-attachments/a.jpg'));
        Storage::disk('public')->assertMissing('report-attachments/a.jpg');
        Storage::disk('public')->assertExists('report-attachments/orphan.png');
        $this->assertSame('report-attachments/a.jpg', $report->fresh()->attachment_path);

        $this->artisan('reports:secure-attachments', ['--delete-orphans' => true])->assertSuccessful();
        Storage::disk('public')->assertMissing('report-attachments/orphan.png');

        // Idempotent.
        $this->artisan('reports:secure-attachments')
            ->expectsOutput('No report attachments on the public disk.')
            ->assertSuccessful();
    }

    public function test_a_different_private_file_at_the_same_path_is_never_overwritten(): void
    {
        $this->legacyReport('report-attachments/a.jpg', 'public-version');
        Storage::disk('local')->put('report-attachments/a.jpg', 'private-version');

        $this->artisan('reports:secure-attachments')->assertFailed();

        $this->assertSame('private-version', Storage::disk('local')->get('report-attachments/a.jpg'));
        Storage::disk('public')->assertExists('report-attachments/a.jpg');
    }

    public function test_web_downloads_of_a_missing_file_are_404_not_500_and_send_safe_headers(): void
    {
        $admin = User::factory()->create(['role_id' => 4]);
        $studentUser = User::factory()->create(['role_id' => 1]);
        $student = Student::factory()->create(['user_id' => $studentUser->id]);

        $missing = InternshipReport::factory()->create([
            'student_id' => $student->id,
            'attachment_path' => 'report-attachments/gone.jpg',
            'attachment_original_name' => 'gone.jpg',
        ]);

        $this->actingAs($admin)->get("/report-reviews/{$missing->id}/attachment")->assertNotFound();
        $this->actingAs($studentUser)->get("/my-reports/{$missing->id}/attachment")->assertNotFound();

        Storage::disk('local')->put('report-attachments/here.pdf', "%PDF-1.4\n%%EOF\n");
        $present = InternshipReport::factory()->create([
            'student_id' => $student->id,
            'attachment_path' => 'report-attachments/here.pdf',
            'attachment_original_name' => 'here.pdf',
        ]);

        foreach ([
            $this->actingAs($admin)->get("/report-reviews/{$present->id}/attachment"),
            $this->actingAs($studentUser)->get("/my-reports/{$present->id}/attachment"),
        ] as $response) {
            $response->assertOk()
                ->assertHeader('Content-Type', 'application/pdf')
                ->assertHeader('X-Content-Type-Options', 'nosniff')
                ->assertHeader('Content-Security-Policy', "default-src 'none'; sandbox");
            $this->assertStringContainsString('filename=here.pdf', str_replace('"', '', $response->headers->get('Content-Disposition')));
        }
    }
}
