<?php

namespace Tests\Feature;

use App\Models\InternshipReport;
use App\Models\Student;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class ReportReviewTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);
    }

    public function test_supervisor_can_still_view_report_reviews_scoped_to_their_students(): void
    {
        $supervisor = User::factory()->create(['role_id' => 3]);
        $otherSupervisor = User::factory()->create(['role_id' => 3]);

        $ownStudent = Student::factory()->create(['supervisor_id' => $supervisor->id]);
        $otherStudent = Student::factory()->create(['supervisor_id' => $otherSupervisor->id]);

        $ownReport = InternshipReport::factory()->create(['student_id' => $ownStudent->id]);
        InternshipReport::factory()->create(['student_id' => $otherStudent->id]);

        $this->actingAs($supervisor)
            ->get('/report-reviews')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('ReportReviews/Index')
                ->has('reports', 1)
                ->where('reports.0.id', $ownReport->id)
            );
    }

    public function test_supervisor_can_no_longer_submit_a_review_for_their_own_student(): void
    {
        $supervisor = User::factory()->create(['role_id' => 3]);
        $student = Student::factory()->create(['supervisor_id' => $supervisor->id]);
        $report = InternshipReport::factory()->create([
            'student_id' => $student->id,
            'status' => 'pending',
        ]);

        $this->actingAs($supervisor)
            ->patch("/report-reviews/{$report->id}", ['comment' => 'Looks good'])
            ->assertForbidden();

        $this->assertDatabaseHas('internship_reports', [
            'id' => $report->id,
            'status' => 'pending',
            'reviewer_comment' => null,
        ]);
    }

    public function test_supervisor_can_no_longer_submit_a_review_for_any_student(): void
    {
        $supervisor = User::factory()->create(['role_id' => 3]);
        $otherSupervisor = User::factory()->create(['role_id' => 3]);
        $otherStudent = Student::factory()->create(['supervisor_id' => $otherSupervisor->id]);
        $report = InternshipReport::factory()->create([
            'student_id' => $otherStudent->id,
            'status' => 'pending',
        ]);

        $this->actingAs($supervisor)
            ->patch("/report-reviews/{$report->id}", ['comment' => 'Not mine to review'])
            ->assertForbidden();
    }

    public function test_coordinator_can_still_submit_a_review(): void
    {
        $coordinator = User::factory()->create(['role_id' => 2]);
        $report = InternshipReport::factory()->create(['status' => 'pending']);

        $response = $this->actingAs($coordinator)->patch(
            "/report-reviews/{$report->id}",
            ['comment' => 'Reviewed by coordinator'],
        );

        $response->assertRedirect(route('report-reviews.index', absolute: false));
        $this->assertDatabaseHas('internship_reports', [
            'id' => $report->id,
            'status' => 'reviewed',
            'reviewer_comment' => 'Reviewed by coordinator',
        ]);
    }

    public function test_admin_can_still_submit_a_review(): void
    {
        $admin = User::factory()->create(['role_id' => 4]);
        $report = InternshipReport::factory()->create(['status' => 'pending']);

        $response = $this->actingAs($admin)->patch(
            "/report-reviews/{$report->id}",
            ['comment' => 'Reviewed by admin'],
        );

        $response->assertRedirect(route('report-reviews.index', absolute: false));
        $this->assertDatabaseHas('internship_reports', [
            'id' => $report->id,
            'status' => 'reviewed',
        ]);
    }
}
