<?php

namespace Tests\Feature;

use App\Models\Attendance;
use App\Models\Company;
use App\Models\Evaluation;
use App\Models\EvaluationCriteria;
use App\Models\EvaluationResponse;
use App\Models\InternshipReport;
use App\Models\Student;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class AnalyticsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);
    }

    /**
     * Fetch the `analytics` Inertia::defer() prop by simulating the
     * partial-reload request the frontend issues for it, targeting either
     * /dashboard or /admin-dashboard.
     */
    private function fetchAnalytics(User $user, string $uri = '/dashboard', array $query = []): TestResponse
    {
        $queryString = $query ? ('?'.http_build_query($query)) : '';

        return $this->actingAs($user)
            ->withHeaders([
                'X-Inertia-Partial-Data' => 'analytics',
                'X-Inertia-Partial-Component' => 'Dashboard/Index',
            ])
            ->get($uri.$queryString);
    }

    public function test_internship_status_breakdown_chart_reflects_seeded_data(): void
    {
        Student::factory()->count(2)->create(['internship_status' => 'ongoing']);
        Student::factory()->create(['internship_status' => 'completed']);
        $admin = User::factory()->create(['role_id' => 4]);

        $this->fetchAnalytics($admin, '/admin-dashboard')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->has('analytics.internship.statusBreakdown', 2)
        );
    }

    public function test_students_by_company_chart(): void
    {
        $company = Company::factory()->create(['company_name' => 'Acme Corp']);
        Student::factory()->count(3)->create(['company_id' => $company->id]);
        $admin = User::factory()->create(['role_id' => 4]);

        $this->fetchAnalytics($admin, '/admin-dashboard')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('analytics.internship.studentsByCompany.0.company', 'Acme Corp')
            ->where('analytics.internship.studentsByCompany.0.total', 3)
        );
    }

    public function test_completion_progress_buckets_chart(): void
    {
        $student = Student::factory()->create(['required_hours' => 100]);
        Attendance::factory()->create(['student_id' => $student->id, 'rendered_hours' => 10]);
        $admin = User::factory()->create(['role_id' => 4]);

        $this->fetchAnalytics($admin, '/admin-dashboard')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('analytics.internship.completionProgressBuckets.0.bucket', '0-25%')
            ->where('analytics.internship.completionProgressBuckets.0.total', 1)
        );
    }

    public function test_attendance_outcomes_chart(): void
    {
        Attendance::factory()->create(['time_in_status' => 'approved']);
        Attendance::factory()->create(['time_in_status' => 'rejected']);
        $admin = User::factory()->create(['role_id' => 4]);

        $this->fetchAnalytics($admin, '/admin-dashboard')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('analytics.attendance.outcomes.0.outcome', 'present')
            ->where('analytics.attendance.outcomes.0.total', 1)
            ->where('analytics.attendance.outcomes.1.outcome', 'rejected')
            ->where('analytics.attendance.outcomes.1.total', 1)
        );
    }

    public function test_attendance_trend_chart_is_grouped_by_date(): void
    {
        Attendance::factory()->create(['date' => today()->toDateString(), 'time_in_status' => 'approved']);
        $admin = User::factory()->create(['role_id' => 4]);

        $this->fetchAnalytics($admin, '/admin-dashboard')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->has('analytics.attendance.trend')
        );
    }

    public function test_frequent_rejections_chart_uses_threshold(): void
    {
        $flagged = Student::factory()->create();
        foreach (range(0, 2) as $daysAgo) {
            Attendance::factory()->create([
                'student_id' => $flagged->id,
                'date' => today()->subDays($daysAgo)->toDateString(),
                'time_in_status' => 'rejected',
            ]);
        }

        $notFlagged = Student::factory()->create();
        foreach (range(0, 1) as $daysAgo) {
            Attendance::factory()->create([
                'student_id' => $notFlagged->id,
                'date' => today()->subDays($daysAgo)->toDateString(),
                'time_in_status' => 'rejected',
            ]);
        }

        $admin = User::factory()->create(['role_id' => 4]);

        $this->fetchAnalytics($admin, '/admin-dashboard')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->has('analytics.attendance.frequentRejections', 1)
            ->where('analytics.attendance.frequentRejections.0.student_id', $flagged->id)
            ->where('analytics.attendance.frequentRejections.0.rejection_count', 3)
        );
    }

    public function test_evaluation_completion_chart(): void
    {
        Evaluation::factory()->count(2)->create(['status' => 'draft']);
        Evaluation::factory()->submitted()->create();
        $admin = User::factory()->create(['role_id' => 4]);

        $this->fetchAnalytics($admin, '/admin-dashboard')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->has('analytics.evaluation.completion', 2)
        );
    }

    public function test_evaluation_by_category_chart_averages_ratings(): void
    {
        $criterion = EvaluationCriteria::factory()->create(['category' => 'Communication']);
        $evaluation = Evaluation::factory()->create();
        EvaluationResponse::factory()->create([
            'evaluation_id' => $evaluation->id,
            'evaluation_criteria_id' => $criterion->id,
            'rating' => 4,
        ]);
        $otherEvaluation = Evaluation::factory()->create();
        EvaluationResponse::factory()->create([
            'evaluation_id' => $otherEvaluation->id,
            'evaluation_criteria_id' => $criterion->id,
            'rating' => 2,
        ]);
        $admin = User::factory()->create(['role_id' => 4]);

        $this->fetchAnalytics($admin, '/admin-dashboard')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('analytics.evaluation.byCategory.0.category', 'Communication')
            ->where('analytics.evaluation.byCategory.0.average_rating', 3)
        );
    }

    public function test_reports_funnel_chart_has_only_pending_and_reviewed_no_rejected_segment(): void
    {
        $student = Student::factory()->create();
        InternshipReport::factory()->create(['student_id' => $student->id, 'status' => 'pending']);
        InternshipReport::factory()->reviewed()->create(['student_id' => $student->id]);
        $admin = User::factory()->create(['role_id' => 4]);

        $this->fetchAnalytics($admin, '/admin-dashboard')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->has('analytics.reports.funnel', 2)
        );
    }

    /**
     * Explicit test proving the Reports Returned/Rejected schema-gap
     * scoping decision is actually implemented, not just documented:
     * InternshipReport::status only ever has pending/reviewed values in
     * this codebase, so no "rejected"/"returned" segment must ever appear
     * in the reports funnel, regardless of what statuses exist in the DB.
     */
    public function test_reports_funnel_never_contains_a_rejected_or_returned_segment(): void
    {
        $student = Student::factory()->create();
        InternshipReport::factory()->create(['student_id' => $student->id, 'status' => 'pending']);
        InternshipReport::factory()->reviewed()->create(['student_id' => $student->id]);
        $admin = User::factory()->create(['role_id' => 4]);

        $response = $this->fetchAnalytics($admin, '/admin-dashboard')->assertOk();

        $funnel = $response->viewData('page')['props']['analytics']['reports']['funnel'];
        $statuses = collect($funnel)->pluck('status')->all();

        $this->assertNotContains('rejected', $statuses);
        $this->assertNotContains('returned', $statuses);
        $this->assertEqualsCanonicalizing(['pending', 'reviewed'], $statuses);
    }

    public function test_report_submission_trend_chart(): void
    {
        $student = Student::factory()->create();
        InternshipReport::factory()->create(['student_id' => $student->id]);
        $admin = User::factory()->create(['role_id' => 4]);

        $this->fetchAnalytics($admin, '/admin-dashboard')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->has('analytics.reports.submissionTrend', 1)
        );
    }

    public function test_an_array_date_from_with_a_date_to_is_a_validation_error_not_a_500(): void
    {
        foreach ([[4, '/admin-dashboard'], [3, '/dashboard'], [2, '/dashboard']] as [$role, $uri]) {
            $user = User::factory()->create(['role_id' => $role]);

            foreach ([['x'], ['a' => '1']] as $dateFrom) {
                $this->fetchAnalytics($user, $uri, ['date_from' => $dateFrom, 'date_to' => '2026-01-01'])
                    ->assertRedirect()
                    ->assertSessionHasErrors('date_from')
                    ->assertSessionDoesntHaveErrors('date_to');
            }
        }
    }
}
