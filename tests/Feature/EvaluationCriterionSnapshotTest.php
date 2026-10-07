<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Evaluation;
use App\Models\EvaluationCriteria;
use App\Models\EvaluationResponse;
use App\Models\Student;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Renaming a criterion doesn't rewrite submitted evaluations: the label,
 * category and description are snapshotted on submit and shown (web props
 * and API, same keys as before) instead of the live criterion. Drafts follow
 * renames; reopen + resubmit takes a fresh snapshot.
 */
class EvaluationCriterionSnapshotTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $supervisor;

    private Student $student;

    private EvaluationCriteria $criterion;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);

        $this->admin = User::factory()->create(['role_id' => User::ROLE_ADMIN, 'status' => 'active']);
        $this->supervisor = User::factory()->create(['role_id' => User::ROLE_SUPERVISOR, 'status' => 'active']);
        $this->student = Student::factory()->create([
            'company_id' => Company::factory()->create()->id,
            'supervisor_id' => $this->supervisor->id,
        ]);
        $this->student->user->forceFill(['status' => 'active'])->save();

        $this->criterion = EvaluationCriteria::factory()->create([
            'label' => 'Old label',
            'category' => 'Old category',
            'description' => 'Old description',
        ]);
    }

    private function draft(): Evaluation
    {
        $this->actingAs($this->supervisor)->post('/supervisor-evaluations', [
            'student_id' => $this->student->id,
            'evaluation_period_start' => '2026-09-01',
            'evaluation_period_end' => '2026-09-14',
            'responses' => [['evaluation_criteria_id' => $this->criterion->id, 'rating' => 4, 'comment' => 'Good']],
        ])->assertSessionHasNoErrors();

        return Evaluation::latest('id')->firstOrFail();
    }

    private function submit(Evaluation $evaluation): void
    {
        $this->actingAs($this->supervisor)
            ->patch("/supervisor-evaluations/{$evaluation->id}/submit")
            ->assertSessionHasNoErrors();
    }

    private function rename(string $suffix): void
    {
        $this->actingAs($this->admin)->patch("/evaluation-criteria/{$this->criterion->id}", [
            'label' => "{$suffix} label",
            'category' => "{$suffix} category",
            'description' => "{$suffix} description",
            'sort_order' => 1,
        ])->assertSessionHasNoErrors();
    }

    private function api(string $uri, User $user): TestResponse
    {
        $this->app['auth']->forgetGuards();
        $this->defaultHeaders = [];

        return $this->withHeaders([
            'Accept' => 'application/json',
            'Authorization' => 'Bearer '.$user->createToken('test')->plainTextToken,
        ])->get($uri)->assertOk();
    }

    /** [label, category, description] the website Show page renders. */
    private function webShow(Evaluation $evaluation, User $viewer): array
    {
        $this->app['auth']->forgetGuards();
        $criteria = $this->actingAs($viewer)->get("/supervisor-evaluations/{$evaluation->id}")
            ->assertOk()->viewData('page')['props']['evaluation']['responses'][0]['criteria'];

        return [$criteria['label'], $criteria['category'], $criteria['description']];
    }

    private function webMyFeedback(): array
    {
        $this->app['auth']->forgetGuards();
        $criteria = $this->actingAs($this->student->user)->get('/my-feedback')
            ->assertOk()->viewData('page')['props']['evaluations'][0]['responses'][0]['criteria'];

        return [$criteria['label'], $criteria['category'], $criteria['description']];
    }

    /** [label, category, description] from an API detail's `categories`. */
    private function apiCategories(TestResponse $response): array
    {
        $group = $response->json('evaluation.categories.0');

        return [$group['criteria'][0]['label'], $group['category'], $group['criteria'][0]['description']];
    }

    private function apiEvaluation(Evaluation $evaluation): array
    {
        return $this->apiCategories($this->api("/api/v1/evaluations/{$evaluation->id}", $this->supervisor));
    }

    private function apiFeedback(Evaluation $evaluation): array
    {
        return $this->apiCategories($this->api("/api/v1/feedback/{$evaluation->id}", $this->student->user));
    }

    public function test_a_submitted_evaluation_keeps_the_wording_it_was_submitted_with(): void
    {
        $evaluation = $this->draft();
        $this->submit($evaluation);
        $this->rename('New');

        $old = ['Old label', 'Old category', 'Old description'];

        $this->assertSame($old, $this->webShow($evaluation, $this->supervisor));
        $this->assertSame($old, $this->webShow($evaluation, $this->admin));
        $this->assertSame($old, $this->webMyFeedback());
        $this->assertSame($old, $this->apiEvaluation($evaluation));
        $this->assertSame($old, $this->apiFeedback($evaluation));

        // Locking keeps it.
        $this->actingAs($this->admin)->patch("/supervisor-evaluations/{$evaluation->id}/lock")->assertSessionHasNoErrors();
        $this->assertSame($old, $this->apiFeedback($evaluation));

        // The criterion itself, and the form for new evaluations, use the new wording.
        $this->assertSame('New label', $this->criterion->fresh()->label);
        $this->api('/api/v1/evaluations/criteria', $this->supervisor)
            ->assertJsonPath('categories.0.criteria.0.label', 'New label');
    }

    public function test_props_and_json_keep_their_shape(): void
    {
        $evaluation = $this->draft();
        $this->submit($evaluation);

        $this->app['auth']->forgetGuards();
        $response = $this->actingAs($this->supervisor)->get("/supervisor-evaluations/{$evaluation->id}")
            ->viewData('page')['props']['evaluation']['responses'][0];

        // No new keys on the response; the snapshot lives in `criteria`.
        foreach (array_keys(EvaluationResponse::SNAPSHOT) as $column) {
            $this->assertArrayNotHasKey($column, $response);
        }
        $this->assertSame($this->criterion->id, $response['criteria']['id']);
        $this->assertTrue($response['criteria']['is_active']);

        $this->api("/api/v1/evaluations/{$evaluation->id}", $this->supervisor)
            ->assertJsonStructure(['evaluation' => ['categories' => [['category', 'criteria' => [['id', 'label', 'description', 'is_active', 'rating', 'max', 'comment']]]]]]);
    }

    public function test_a_draft_follows_a_rename(): void
    {
        $evaluation = $this->draft();
        $this->rename('New');

        $this->assertSame(['New label', 'New category', 'New description'], $this->webShow($evaluation, $this->supervisor));
        $this->assertSame(['New label', 'New category', 'New description'], $this->apiEvaluation($evaluation));
        $this->assertNull(EvaluationResponse::first()->criterion_label);
    }

    public function test_reopen_returns_to_live_wording_and_resubmitting_refreshes_the_snapshot(): void
    {
        $evaluation = $this->draft();
        $this->submit($evaluation);
        $this->rename('Second');

        $this->actingAs($this->admin)->patch("/supervisor-evaluations/{$evaluation->id}/reopen")->assertSessionHasNoErrors();

        $this->assertNull(EvaluationResponse::first()->criterion_label);
        $this->assertSame(['Second label', 'Second category', 'Second description'], $this->webShow($evaluation, $this->supervisor));

        $this->submit($evaluation);
        $this->rename('Third');

        $second = ['Second label', 'Second category', 'Second description'];
        $this->assertSame($second, $this->webShow($evaluation, $this->supervisor));
        $this->assertSame($second, $this->apiFeedback($evaluation));
    }

    public function test_deactivating_after_submit_still_shows_the_snapshot_and_live_active_flag(): void
    {
        $evaluation = $this->draft();
        $this->submit($evaluation);
        $this->rename('New');
        $this->criterion->update(['is_active' => false]);

        $response = $this->api("/api/v1/evaluations/{$evaluation->id}", $this->supervisor);
        $response->assertJsonPath('evaluation.categories.0.criteria.0.label', 'Old label');
        $response->assertJsonPath('evaluation.categories.0.criteria.0.is_active', false);
    }

    public function test_the_migration_backfill_snapshots_only_submitted_and_locked_evaluations(): void
    {
        $submitted = Evaluation::factory()->create(['student_id' => $this->student->id, 'status' => 'submitted']);
        $locked = Evaluation::factory()->create(['student_id' => $this->student->id, 'status' => 'locked']);
        $draft = Evaluation::factory()->create(['student_id' => $this->student->id, 'status' => 'draft']);
        $alreadySnapshotted = Evaluation::factory()->create(['student_id' => $this->student->id, 'status' => 'submitted']);

        foreach ([$submitted, $locked, $draft] as $evaluation) {
            EvaluationResponse::create(['evaluation_id' => $evaluation->id, 'evaluation_criteria_id' => $this->criterion->id, 'rating' => 3]);
        }
        EvaluationResponse::create([
            'evaluation_id' => $alreadySnapshotted->id, 'evaluation_criteria_id' => $this->criterion->id, 'rating' => 3,
            'criterion_label' => 'Kept', 'criterion_category' => 'Kept category', 'criterion_description' => null,
        ]);

        $migration = require database_path('migrations/21_add_criterion_snapshot_to_evaluation_responses_table.php');
        $migration->backfill();

        $snapshot = fn (Evaluation $evaluation) => EvaluationResponse::where('evaluation_id', $evaluation->id)->first()
            ->only(array_keys(EvaluationResponse::SNAPSHOT));

        $current = ['criterion_label' => 'Old label', 'criterion_category' => 'Old category', 'criterion_description' => 'Old description'];
        $this->assertSame($current, $snapshot($submitted));
        $this->assertSame($current, $snapshot($locked));
        $this->assertSame(['criterion_label' => null, 'criterion_category' => null, 'criterion_description' => null], $snapshot($draft));
        $this->assertSame(['criterion_label' => 'Kept', 'criterion_category' => 'Kept category', 'criterion_description' => null], $snapshot($alreadySnapshotted));
    }
}
