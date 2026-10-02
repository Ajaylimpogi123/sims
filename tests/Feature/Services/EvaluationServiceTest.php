<?php

namespace Tests\Feature\Services;

use App\Models\Company;
use App\Models\EvaluationCriteria;
use App\Models\Student;
use App\Models\User;
use App\Services\EvaluationService;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * EvaluationService called directly, the way the /api/v1 controllers will.
 */
class EvaluationServiceTest extends TestCase
{
    use RefreshDatabase;

    private EvaluationService $service;

    private User $supervisor;

    private Student $student;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);

        $this->service = app(EvaluationService::class);
        $this->supervisor = User::factory()->create(['role_id' => User::ROLE_SUPERVISOR]);
        $this->student = Student::factory()->create([
            'supervisor_id' => $this->supervisor->id,
            'company_id' => Company::factory()->create()->id,
        ]);
    }

    private function data(array $responses, array $overrides = []): array
    {
        return array_merge([
            'evaluation_period_start' => '2026-08-01',
            'evaluation_period_end' => '2026-08-31',
            'strengths' => 'Punctual',
            'responses' => $responses,
        ], $overrides);
    }

    public function test_create_draft_snapshots_assignment_and_computes_the_overall_rating(): void
    {
        [$a, $b] = EvaluationCriteria::factory()->count(2)->create(['is_active' => true]);

        $evaluation = $this->service->createDraft($this->student, $this->data([
            ['evaluation_criteria_id' => $a->id, 'rating' => 4],
            ['evaluation_criteria_id' => $b->id, 'rating' => 5, 'comment' => 'Great'],
        ]));

        $this->assertSame('draft', $evaluation->status);
        $this->assertSame($this->student->company_id, $evaluation->company_id);
        $this->assertSame($this->supervisor->id, $evaluation->supervisor_id);
        $this->assertSame('4.50', $evaluation->fresh()->overall_rating);
        $this->assertNull($evaluation->areas_for_improvement);
        $this->assertCount(2, $evaluation->responses);
    }

    public function test_update_draft_replaces_responses_and_recomputes_the_rating(): void
    {
        [$a, $b] = EvaluationCriteria::factory()->count(2)->create(['is_active' => true]);
        $evaluation = $this->service->createDraft($this->student, $this->data([
            ['evaluation_criteria_id' => $a->id, 'rating' => 4],
        ]));

        $this->service->updateDraft($evaluation, $this->data([
            ['evaluation_criteria_id' => $b->id, 'rating' => 2],
        ], ['strengths' => null]));

        $evaluation->refresh();
        $this->assertSame([$b->id], $evaluation->responses()->pluck('evaluation_criteria_id')->all());
        $this->assertSame('2.00', $evaluation->overall_rating);
        $this->assertNull($evaluation->strengths);
    }

    public function test_submit_requires_every_active_criterion_to_be_rated(): void
    {
        [$a, $b] = EvaluationCriteria::factory()->count(2)->create(['is_active' => true]);
        EvaluationCriteria::factory()->create(['is_active' => false]);
        $evaluation = $this->service->createDraft($this->student, $this->data([
            ['evaluation_criteria_id' => $a->id, 'rating' => 3],
        ]));

        try {
            $this->service->submit($evaluation);
            $this->fail('Submitting with an unrated active criterion must fail.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('responses', $e->errors());
        }

        $this->service->updateDraft($evaluation, $this->data([
            ['evaluation_criteria_id' => $a->id, 'rating' => 3],
            ['evaluation_criteria_id' => $b->id, 'rating' => 5],
        ]));
        $this->service->submit($evaluation);

        $this->assertSame('submitted', $evaluation->fresh()->status);
        $this->assertNotNull($evaluation->fresh()->submitted_at);
    }

    public function test_lock_and_reopen(): void
    {
        $admin = User::factory()->create(['role_id' => User::ROLE_ADMIN]);
        $evaluation = $this->service->createDraft($this->student, $this->data([]));
        $this->service->submit($evaluation);

        $this->service->lock($evaluation, $admin);
        $this->assertSame('locked', $evaluation->fresh()->status);
        $this->assertSame($admin->id, $evaluation->fresh()->locked_by);

        $this->service->reopen($evaluation);
        $evaluation->refresh();
        $this->assertSame('draft', $evaluation->status);
        $this->assertNull($evaluation->submitted_at);
        $this->assertNull($evaluation->locked_at);
        $this->assertNull($evaluation->locked_by);
    }
}
