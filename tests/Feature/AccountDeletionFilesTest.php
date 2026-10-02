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
 * Deleting an account cascades the student's rows at the FK level (no model
 * events), so the private files those rows point at must be removed
 * explicitly — and only once the delete has actually happened.
 */
class AccountDeletionFilesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);
        Storage::fake('local');
    }

    private function studentUser(): User
    {
        $user = User::factory()->create(['role_id' => 1]);
        Student::factory()->create(['user_id' => $user->id]);

        return $user;
    }

    private int $reportDay = 0;

    /**
     * Factory dates are random, so pin a distinct period per report to stay
     * clear of the (student_id, period_start, type) unique index.
     */
    private function distinctPeriod(): array
    {
        $day = now()->subDays(++$this->reportDay)->toDateString();

        return ['type' => 'daily', 'period_start' => $day, 'period_end' => $day];
    }

    private function reportWithAttachment(User $user, string $name): string
    {
        $path = "report-attachments/{$name}";
        Storage::disk('local')->put($path, 'attachment');

        InternshipReport::factory()->create([
            'student_id' => $user->student->id,
            'attachment_path' => $path,
            'attachment_original_name' => $name,
            ...$this->distinctPeriod(),
        ]);

        return $path;
    }

    public function test_deleting_own_account_removes_report_attachments_and_attendance_photos(): void
    {
        $user = $this->studentUser();
        $first = $this->reportWithAttachment($user, 'own-1.pdf');
        $second = $this->reportWithAttachment($user, 'own-2.jpg');
        InternshipReport::factory()->create(['student_id' => $user->student->id, ...$this->distinctPeriod()]);
        $photoDir = "attendance-photos/{$user->student->id}";
        Storage::disk('local')->put("{$photoDir}/in.jpg", 'in');

        $this->actingAs($user)
            ->delete('/profile', ['password' => 'password'])
            ->assertSessionHasNoErrors()
            ->assertRedirect('/');

        $this->assertModelMissing($user);
        $this->assertDatabaseCount('internship_reports', 0);
        Storage::disk('local')->assertMissing($first);
        Storage::disk('local')->assertMissing($second);
        $this->assertFalse(Storage::disk('local')->directoryExists($photoDir));
    }

    public function test_deleting_own_account_leaves_other_students_files_alone(): void
    {
        $user = $this->studentUser();
        $other = $this->studentUser();
        $own = $this->reportWithAttachment($user, 'own.pdf');
        $others = $this->reportWithAttachment($other, 'other.pdf');
        $otherPhoto = "attendance-photos/{$other->student->id}/in.jpg";
        Storage::disk('local')->put($otherPhoto, 'in');

        $this->actingAs($user)
            ->delete('/profile', ['password' => 'password'])
            ->assertSessionHasNoErrors();

        Storage::disk('local')->assertMissing($own);
        Storage::disk('local')->assertExists($others);
        Storage::disk('local')->assertExists($otherPhoto);
        $this->assertDatabaseHas('internship_reports', ['attachment_path' => $others]);
    }

    public function test_failed_account_deletion_keeps_report_attachments(): void
    {
        $user = $this->studentUser();
        $path = $this->reportWithAttachment($user, 'own.pdf');

        $this->actingAs($user)
            ->delete('/profile', ['password' => 'wrong-password'])
            ->assertSessionHasErrors('password');

        $this->assertModelExists($user);
        Storage::disk('local')->assertExists($path);
        $this->assertDatabaseHas('internship_reports', ['attachment_path' => $path]);
    }

    public function test_non_student_can_delete_account_without_touching_student_files(): void
    {
        $student = $this->studentUser();
        $path = $this->reportWithAttachment($student, 'student.pdf');
        $coordinator = User::factory()->create(['role_id' => 2]);

        $this->actingAs($coordinator)
            ->delete('/profile', ['password' => 'password'])
            ->assertSessionHasNoErrors()
            ->assertRedirect('/');

        $this->assertModelMissing($coordinator);
        Storage::disk('local')->assertExists($path);
    }
}
