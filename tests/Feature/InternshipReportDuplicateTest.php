<?php

namespace Tests\Feature;

use App\Models\InternshipReport;
use App\Models\Student;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class InternshipReportDuplicateTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);
    }

    private function studentUser(): User
    {
        $user = User::factory()->create(['role_id' => 1]);
        Student::factory()->create(['user_id' => $user->id]);

        return $user;
    }

    private function payload(array $overrides = []): array
    {
        return array_merge([
            'type' => 'daily',
            'period_start' => '2026-01-05',
            'period_end' => '2026-01-05',
            'content' => 'Worked on the reporting module today.',
        ], $overrides);
    }

    public function test_a_student_can_submit_a_report(): void
    {
        $user = $this->studentUser();

        $response = $this->actingAs($user)->post('/my-reports', $this->payload());

        $response->assertRedirect(route('reports.index', absolute: false));
        $this->assertDatabaseHas('internship_reports', [
            'student_id' => $user->student->id,
            'type' => 'daily',
            'period_start' => '2026-01-05',
        ]);
    }

    public function test_submitting_a_duplicate_period_and_type_is_rejected(): void
    {
        $user = $this->studentUser();

        InternshipReport::factory()->create([
            'student_id' => $user->student->id,
            'type' => 'daily',
            'period_start' => '2026-01-05',
            'period_end' => '2026-01-05',
        ]);

        $response = $this->actingAs($user)->post('/my-reports', $this->payload());

        $response->assertSessionHasErrors('period_start');
        $this->assertSame(
            1,
            InternshipReport::where('student_id', $user->student->id)
                ->where('period_start', '2026-01-05')
                ->where('type', 'daily')
                ->count(),
        );
    }

    public function test_a_different_type_for_the_same_period_still_succeeds(): void
    {
        $user = $this->studentUser();

        InternshipReport::factory()->create([
            'student_id' => $user->student->id,
            'type' => 'daily',
            'period_start' => '2026-01-05',
            'period_end' => '2026-01-05',
        ]);

        $response = $this->actingAs($user)->post(
            '/my-reports',
            $this->payload(['type' => 'weekly', 'period_end' => '2026-01-09']),
        );

        $response->assertRedirect(route('reports.index', absolute: false));
        $this->assertDatabaseHas('internship_reports', [
            'student_id' => $user->student->id,
            'type' => 'weekly',
            'period_start' => '2026-01-05',
        ]);
    }

    public function test_a_different_period_start_for_the_same_type_still_succeeds(): void
    {
        $user = $this->studentUser();

        InternshipReport::factory()->create([
            'student_id' => $user->student->id,
            'type' => 'daily',
            'period_start' => '2026-01-05',
            'period_end' => '2026-01-05',
        ]);

        $response = $this->actingAs($user)->post(
            '/my-reports',
            $this->payload(['period_start' => '2026-01-06', 'period_end' => '2026-01-06']),
        );

        $response->assertRedirect(route('reports.index', absolute: false));
        $this->assertDatabaseHas('internship_reports', [
            'student_id' => $user->student->id,
            'type' => 'daily',
            'period_start' => '2026-01-06',
        ]);
    }

    public function test_another_students_report_for_the_same_period_does_not_block_this_student(): void
    {
        $otherStudent = Student::factory()->create();
        InternshipReport::factory()->create([
            'student_id' => $otherStudent->id,
            'type' => 'daily',
            'period_start' => '2026-01-05',
            'period_end' => '2026-01-05',
        ]);

        $user = $this->studentUser();

        $response = $this->actingAs($user)->post('/my-reports', $this->payload());

        $response->assertRedirect(route('reports.index', absolute: false));
        $this->assertDatabaseHas('internship_reports', [
            'student_id' => $user->student->id,
            'type' => 'daily',
            'period_start' => '2026-01-05',
        ]);
    }

    public function test_updating_a_report_to_collide_with_another_existing_report_is_rejected(): void
    {
        $user = $this->studentUser();

        InternshipReport::factory()->create([
            'student_id' => $user->student->id,
            'type' => 'daily',
            'period_start' => '2026-01-05',
            'period_end' => '2026-01-05',
        ]);

        $editableReport = InternshipReport::factory()->create([
            'student_id' => $user->student->id,
            'type' => 'daily',
            'period_start' => '2026-01-06',
            'period_end' => '2026-01-06',
            'status' => 'pending',
        ]);

        $response = $this->actingAs($user)->patch(
            "/my-reports/{$editableReport->id}",
            $this->payload(['period_start' => '2026-01-05', 'period_end' => '2026-01-05']),
        );

        $response->assertSessionHasErrors('period_start');
        $this->assertDatabaseHas('internship_reports', [
            'id' => $editableReport->id,
            'period_start' => '2026-01-06',
        ]);
    }

    public function test_updating_a_report_without_changing_its_own_period_still_succeeds(): void
    {
        $user = $this->studentUser();

        $report = InternshipReport::factory()->create([
            'student_id' => $user->student->id,
            'type' => 'daily',
            'period_start' => '2026-01-05',
            'period_end' => '2026-01-05',
            'status' => 'pending',
            'content' => 'Original content.',
        ]);

        $response = $this->actingAs($user)->patch(
            "/my-reports/{$report->id}",
            $this->payload(['content' => 'Updated content.']),
        );

        $response->assertRedirect(route('reports.index', absolute: false));
        $this->assertDatabaseHas('internship_reports', [
            'id' => $report->id,
            'content' => 'Updated content.',
        ]);
    }
}
