<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Evaluation;
use App\Models\EvaluationCriteria;
use App\Models\Student;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class EvaluationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);
    }

    private function makeAssignedStudent(User $supervisor, ?Company $company = null): Student
    {
        $company ??= Company::factory()->create();

        return Student::factory()->create([
            'company_id' => $company->id,
            'supervisor_id' => $supervisor->id,
        ]);
    }

    private function activeCriteriaPayload(): array
    {
        return EvaluationCriteria::where('is_active', true)
            ->get()
            ->map(fn (EvaluationCriteria $criterion) => [
                'evaluation_criteria_id' => $criterion->id,
                'rating' => 4,
                'comment' => 'Solid performance.',
            ])
            ->all();
    }

    // --- Full lifecycle: draft -> submit -> locked -> admin reopen ---

    public function test_supervisor_can_create_a_draft_evaluation_for_their_own_student(): void
    {
        $supervisor = User::factory()->create(['role_id' => 3]);
        $student = $this->makeAssignedStudent($supervisor);
        EvaluationCriteria::factory()->count(2)->create();

        $response = $this->actingAs($supervisor)->post('/supervisor-evaluations', [
            'student_id' => $student->id,
            'evaluation_period_start' => '2026-01-01',
            'evaluation_period_end' => '2026-01-31',
            'strengths' => 'Great attitude',
            'responses' => $this->activeCriteriaPayload(),
        ]);

        $response->assertRedirect();
        $this->assertDatabaseHas('evaluations', [
            'student_id' => $student->id,
            'company_id' => $student->company_id,
            'supervisor_id' => $supervisor->id,
            'status' => 'draft',
        ]);
        $this->assertDatabaseCount('evaluation_responses', EvaluationCriteria::where('is_active', true)->count());
    }

    public function test_duplicate_evaluation_criteria_id_in_responses_is_rejected_with_a_validation_error(): void
    {
        // Regression test: evaluation_responses has a DB-level unique
        // constraint on (evaluation_id, evaluation_criteria_id); before the
        // `distinct` rule was added, a duplicate criteria id in the payload
        // passed validation and then blew up with an unhandled 500 from the
        // DB constraint instead of a clean 422.
        $supervisor = User::factory()->create(['role_id' => 3]);
        $student = $this->makeAssignedStudent($supervisor);
        $criterion = EvaluationCriteria::factory()->create();

        $response = $this->actingAs($supervisor)->post('/supervisor-evaluations', [
            'student_id' => $student->id,
            'evaluation_period_start' => '2026-01-01',
            'evaluation_period_end' => '2026-01-31',
            'responses' => [
                ['evaluation_criteria_id' => $criterion->id, 'rating' => 1],
                ['evaluation_criteria_id' => $criterion->id, 'rating' => 5],
            ],
        ]);

        $response->assertSessionHasErrors('responses.0.evaluation_criteria_id');
        $this->assertDatabaseMissing('evaluations', ['student_id' => $student->id]);
    }

    public function test_duplicate_evaluation_criteria_id_on_update_is_rejected_with_a_validation_error(): void
    {
        $supervisor = User::factory()->create(['role_id' => 3]);
        $student = $this->makeAssignedStudent($supervisor);
        $criterion = EvaluationCriteria::factory()->create();

        $evaluation = Evaluation::factory()->create([
            'student_id' => $student->id,
            'supervisor_id' => $supervisor->id,
        ]);

        $response = $this->actingAs($supervisor)->patch("/supervisor-evaluations/{$evaluation->id}", [
            'evaluation_period_start' => '2026-01-01',
            'evaluation_period_end' => '2026-01-31',
            'responses' => [
                ['evaluation_criteria_id' => $criterion->id, 'rating' => 1],
                ['evaluation_criteria_id' => $criterion->id, 'rating' => 5],
            ],
        ]);

        $response->assertSessionHasErrors('responses.0.evaluation_criteria_id');
        $this->assertDatabaseCount('evaluation_responses', 0);
    }

    public function test_supervisor_can_submit_a_draft_evaluation_once_all_active_criteria_are_rated(): void
    {
        $supervisor = User::factory()->create(['role_id' => 3]);
        $student = $this->makeAssignedStudent($supervisor);
        EvaluationCriteria::factory()->count(3)->create();

        $evaluation = Evaluation::factory()->create([
            'student_id' => $student->id,
            'company_id' => $student->company_id,
            'supervisor_id' => $supervisor->id,
        ]);

        foreach (EvaluationCriteria::where('is_active', true)->get() as $criterion) {
            $evaluation->responses()->create([
                'evaluation_criteria_id' => $criterion->id,
                'rating' => 5,
            ]);
        }

        $response = $this->actingAs($supervisor)
            ->patch("/supervisor-evaluations/{$evaluation->id}/submit");

        $response->assertRedirect();
        $this->assertDatabaseHas('evaluations', [
            'id' => $evaluation->id,
            'status' => 'submitted',
        ]);
        $this->assertNotNull($evaluation->fresh()->submitted_at);
        $this->assertEquals(5.00, $evaluation->fresh()->overall_rating);
    }

    public function test_submitting_fails_when_an_active_criterion_is_not_rated(): void
    {
        $supervisor = User::factory()->create(['role_id' => 3]);
        $student = $this->makeAssignedStudent($supervisor);

        EvaluationCriteria::factory()->create(); // an active criterion with no response

        $evaluation = Evaluation::factory()->create([
            'student_id' => $student->id,
            'supervisor_id' => $supervisor->id,
        ]);

        $response = $this->actingAs($supervisor)
            ->patch("/supervisor-evaluations/{$evaluation->id}/submit");

        $response->assertSessionHasErrors('responses');
        $this->assertDatabaseHas('evaluations', [
            'id' => $evaluation->id,
            'status' => 'draft',
        ]);
    }

    public function test_a_submitted_evaluation_can_no_longer_be_edited(): void
    {
        $supervisor = User::factory()->create(['role_id' => 3]);
        $student = $this->makeAssignedStudent($supervisor);

        $evaluation = Evaluation::factory()->submitted()->create([
            'student_id' => $student->id,
            'supervisor_id' => $supervisor->id,
        ]);

        $response = $this->actingAs($supervisor)->patch("/supervisor-evaluations/{$evaluation->id}", [
            'evaluation_period_start' => $evaluation->evaluation_period_start->format('Y-m-d'),
            'evaluation_period_end' => $evaluation->evaluation_period_end->format('Y-m-d'),
        ]);

        $response->assertForbidden();
    }

    public function test_admin_can_lock_a_submitted_evaluation(): void
    {
        $admin = User::factory()->create(['role_id' => 4]);
        $evaluation = Evaluation::factory()->submitted()->create();

        $response = $this->actingAs($admin)->patch("/supervisor-evaluations/{$evaluation->id}/lock");

        $response->assertRedirect();
        $this->assertDatabaseHas('evaluations', [
            'id' => $evaluation->id,
            'status' => 'locked',
            'locked_by' => $admin->id,
        ]);
    }

    public function test_admin_can_reopen_a_locked_evaluation_back_to_draft(): void
    {
        $admin = User::factory()->create(['role_id' => 4]);
        $evaluation = Evaluation::factory()->submitted()->create([
            'status' => 'locked',
            'locked_at' => now(),
        ]);

        $response = $this->actingAs($admin)->patch("/supervisor-evaluations/{$evaluation->id}/reopen");

        $response->assertRedirect();
        $this->assertDatabaseHas('evaluations', [
            'id' => $evaluation->id,
            'status' => 'draft',
            'submitted_at' => null,
            'locked_at' => null,
            'locked_by' => null,
        ]);
    }

    public function test_supervisor_cannot_reopen_or_lock_an_evaluation(): void
    {
        $supervisor = User::factory()->create(['role_id' => 3]);
        $evaluation = Evaluation::factory()->submitted()->create([
            'supervisor_id' => $supervisor->id,
        ]);

        $this->actingAs($supervisor)
            ->patch("/supervisor-evaluations/{$evaluation->id}/lock")
            ->assertForbidden();

        $this->actingAs($supervisor)
            ->patch("/supervisor-evaluations/{$evaluation->id}/reopen")
            ->assertForbidden();
    }

    // --- Criteria configurability ---

    public function test_admin_can_add_a_new_evaluation_criterion(): void
    {
        $admin = User::factory()->create(['role_id' => 4]);

        $response = $this->actingAs($admin)->post('/evaluation-criteria', [
            'label' => 'Demonstrates punctuality in virtual meetings',
            'category' => 'Attendance & Punctuality',
            'sort_order' => 1,
        ]);

        $response->assertRedirect();
        $this->assertDatabaseHas('evaluation_criteria', [
            'label' => 'Demonstrates punctuality in virtual meetings',
            'is_active' => 1,
        ]);
    }

    public function test_deactivating_a_criterion_with_existing_responses_preserves_historical_data(): void
    {
        $admin = User::factory()->create(['role_id' => 4]);
        $criterion = EvaluationCriteria::factory()->create();
        $evaluation = Evaluation::factory()->submitted()->create();
        $response = $evaluation->responses()->create([
            'evaluation_criteria_id' => $criterion->id,
            'rating' => 4,
            'comment' => 'Historical response',
        ]);

        // Deleting a criterion that already has responses should deactivate
        // it instead of hard-deleting, so the historical response survives.
        $this->actingAs($admin)->delete("/evaluation-criteria/{$criterion->id}");

        $this->assertDatabaseHas('evaluation_criteria', [
            'id' => $criterion->id,
            'is_active' => 0,
        ]);
        $this->assertDatabaseHas('evaluation_responses', [
            'id' => $response->id,
            'evaluation_criteria_id' => $criterion->id,
            'comment' => 'Historical response',
        ]);
    }

    public function test_unused_criterion_can_be_hard_deleted(): void
    {
        $admin = User::factory()->create(['role_id' => 4]);
        $criterion = EvaluationCriteria::factory()->create();

        $this->actingAs($admin)->delete("/evaluation-criteria/{$criterion->id}");

        $this->assertDatabaseMissing('evaluation_criteria', ['id' => $criterion->id]);
    }

    public function test_non_admin_cannot_manage_evaluation_criteria(): void
    {
        $coordinator = User::factory()->create(['role_id' => 2]);
        $supervisor = User::factory()->create(['role_id' => 3]);

        $this->actingAs($coordinator)
            ->post('/evaluation-criteria', ['label' => 'x', 'category' => 'y'])
            ->assertForbidden();

        $this->actingAs($supervisor)
            ->get('/evaluation-criteria')
            ->assertForbidden();
    }

    // --- RULE 1/2 cross-supervisor isolation via direct ID manipulation ---

    public function test_supervisor_cannot_view_another_supervisors_evaluation_via_direct_id(): void
    {
        $supervisor = User::factory()->create(['role_id' => 3]);
        $otherSupervisor = User::factory()->create(['role_id' => 3]);
        $otherStudent = $this->makeAssignedStudent($otherSupervisor);
        $evaluation = Evaluation::factory()->create([
            'student_id' => $otherStudent->id,
            'supervisor_id' => $otherSupervisor->id,
        ]);

        $this->actingAs($supervisor)
            ->get("/supervisor-evaluations/{$evaluation->id}")
            ->assertForbidden();
    }

    public function test_supervisor_cannot_create_an_evaluation_for_a_student_they_do_not_supervise(): void
    {
        $supervisor = User::factory()->create(['role_id' => 3]);
        $otherSupervisor = User::factory()->create(['role_id' => 3]);
        $otherStudent = $this->makeAssignedStudent($otherSupervisor);

        $this->actingAs($supervisor)
            ->post('/supervisor-evaluations', [
                'student_id' => $otherStudent->id,
                'evaluation_period_start' => '2026-01-01',
                'evaluation_period_end' => '2026-01-31',
            ])
            ->assertForbidden();

        $this->assertDatabaseMissing('evaluations', ['student_id' => $otherStudent->id]);
    }

    public function test_supervisor_cannot_update_another_supervisors_evaluation_via_direct_id(): void
    {
        $supervisor = User::factory()->create(['role_id' => 3]);
        $otherSupervisor = User::factory()->create(['role_id' => 3]);
        $otherStudent = $this->makeAssignedStudent($otherSupervisor);
        $evaluation = Evaluation::factory()->create([
            'student_id' => $otherStudent->id,
            'supervisor_id' => $otherSupervisor->id,
        ]);

        $this->actingAs($supervisor)
            ->patch("/supervisor-evaluations/{$evaluation->id}", [
                'evaluation_period_start' => '2026-01-01',
                'evaluation_period_end' => '2026-01-31',
                'strengths' => 'Tampered',
            ])
            ->assertForbidden();

        $this->assertDatabaseMissing('evaluations', [
            'id' => $evaluation->id,
            'strengths' => 'Tampered',
        ]);
    }

    public function test_supervisor_cannot_submit_another_supervisors_evaluation_via_direct_id(): void
    {
        $supervisor = User::factory()->create(['role_id' => 3]);
        $otherSupervisor = User::factory()->create(['role_id' => 3]);
        $otherStudent = $this->makeAssignedStudent($otherSupervisor);
        $evaluation = Evaluation::factory()->create([
            'student_id' => $otherStudent->id,
            'supervisor_id' => $otherSupervisor->id,
        ]);

        $this->actingAs($supervisor)
            ->patch("/supervisor-evaluations/{$evaluation->id}/submit")
            ->assertForbidden();

        $this->assertDatabaseHas('evaluations', ['id' => $evaluation->id, 'status' => 'draft']);
    }

    public function test_supervisor_index_is_scoped_to_their_own_students_evaluations(): void
    {
        $supervisor = User::factory()->create(['role_id' => 3]);
        $otherSupervisor = User::factory()->create(['role_id' => 3]);
        $ownStudent = $this->makeAssignedStudent($supervisor);
        $otherStudent = $this->makeAssignedStudent($otherSupervisor);

        $ownEvaluation = Evaluation::factory()->create([
            'student_id' => $ownStudent->id,
            'supervisor_id' => $supervisor->id,
        ]);
        Evaluation::factory()->create([
            'student_id' => $otherStudent->id,
            'supervisor_id' => $otherSupervisor->id,
        ]);

        $this->actingAs($supervisor)
            ->get('/supervisor-evaluations')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('SupervisorEvaluations/Index')
                ->has('evaluations', 1)
                ->where('evaluations.0.id', $ownEvaluation->id)
            );
    }

    // --- Coordinator view-only enforcement ---

    public function test_coordinator_can_view_but_not_mutate_evaluations(): void
    {
        $coordinator = User::factory()->create(['role_id' => 2]);
        $student = Student::factory()->create();
        $evaluation = Evaluation::factory()->create(['student_id' => $student->id]);
        $criterion = EvaluationCriteria::factory()->create();

        $this->actingAs($coordinator)
            ->get('/supervisor-evaluations')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('SupervisorEvaluations/Index'));

        $this->actingAs($coordinator)
            ->get("/supervisor-evaluations/{$evaluation->id}")
            ->assertOk();

        $this->actingAs($coordinator)
            ->post('/supervisor-evaluations', [
                'student_id' => $student->id,
                'evaluation_period_start' => '2026-01-01',
                'evaluation_period_end' => '2026-01-31',
            ])
            ->assertForbidden();

        $this->actingAs($coordinator)
            ->patch("/supervisor-evaluations/{$evaluation->id}", [
                'evaluation_period_start' => '2026-01-01',
                'evaluation_period_end' => '2026-01-31',
            ])
            ->assertForbidden();

        $this->actingAs($coordinator)
            ->patch("/supervisor-evaluations/{$evaluation->id}/submit")
            ->assertForbidden();

        $this->actingAs($coordinator)
            ->delete("/evaluation-criteria/{$criterion->id}")
            ->assertForbidden();
    }

    // --- Student "My Feedback" scoping ---

    public function test_student_only_sees_their_own_submitted_evaluations(): void
    {
        $student = Student::factory()->create();
        $otherStudent = Student::factory()->create();

        $submitted = Evaluation::factory()->submitted()->create(['student_id' => $student->id]);
        Evaluation::factory()->create(['student_id' => $student->id]); // draft, should be hidden
        Evaluation::factory()->submitted()->create(['student_id' => $otherStudent->id]);

        $this->actingAs($student->user)
            ->get('/my-feedback')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('SupervisorEvaluations/MyFeedback')
                ->has('evaluations', 1)
                ->where('evaluations.0.id', $submitted->id)
            );
    }

    public function test_student_cannot_access_supervisor_evaluation_routes(): void
    {
        $student = Student::factory()->create();

        $this->actingAs($student->user)
            ->get('/supervisor-evaluations')
            ->assertForbidden();
    }
}
